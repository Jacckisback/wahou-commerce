/**
 * Wahou Commerce – setup wizard.
 * Vanilla JS, no dependencies. Each step is saved over admin-ajax.
 */
( function () {
	'use strict';

	var data = window.wahouOnboarding || {};
	var i18n = data.i18n || {};
	var steps = Array.prototype.slice.call( document.querySelectorAll( '[data-step]' ) );
	var progress = document.querySelector( '[data-ob-progress]' );
	var compact = document.querySelector( '[data-ob-compact]' );
	var live = document.querySelector( '[data-ob-live]' );
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var sells = data.sells || 'both';
	var current = 'welcome';
	var furthest = 0;
	var celebrated = false;
	var currencyTouched = false;

	if ( ! steps.length ) {
		return;
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	function format( template, a, b ) {
		return String( template || '' ).replace( '%1$s', a ).replace( '%2$s', b );
	}

	function announce( message ) {
		if ( ! live ) {
			return;
		}
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = message;
		}, 60 );
	}

	function stepNames() {
		return ( data.steps || [] ).filter( function ( name ) {
			return ! ( 'shipping' === name && 'digital' === sells );
		} );
	}

	function stepEl( name ) {
		return document.querySelector( '[data-step="' + name + '"]' );
	}

	function request( body ) {
		return window.fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var json = null;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					json = null;
				}
				if ( ! json || 'object' !== typeof json ) {
					var trimmed = String( text || '' ).trim();
					throw new Error( '0' === trimmed || '-1' === trimmed ? i18n.unavailable : i18n.error );
				}
				return json;
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Navigation.
	 * ------------------------------------------------------------------ */

	function show( name, focus ) {
		var names = stepNames();
		if ( -1 === names.indexOf( name ) ) {
			name = 'shipping' === name ? 'design' : names[ 0 ];
		}
		current = name;
		var index = names.indexOf( name );
		var total = names.length;
		furthest = Math.max( furthest, index );

		steps.forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-step' ) !== name;
		} );

		if ( progress ) {
			progress.setAttribute( 'aria-valuenow', String( index + 1 ) );
			progress.setAttribute( 'aria-valuemax', String( total ) );
			if ( progress.firstElementChild ) {
				progress.firstElementChild.style.width = ( ( index + 1 ) / total * 100 ) + '%';
			}
		}

		document.querySelectorAll( '[data-stepper]' ).forEach( function ( li ) {
			var key = li.getAttribute( 'data-stepper' );
			var i = names.indexOf( key );
			var button = li.querySelector( 'button' );
			var dot = li.querySelector( '.wahou-ob-stepper__dot' );
			li.hidden = -1 === i;
			li.classList.toggle( 'is-current', key === name );
			li.classList.toggle( 'is-done', i > -1 && i < index );
			if ( dot && i > -1 ) {
				dot.textContent = i < index ? '✓' : String( i + 1 );
			}
			if ( button ) {
				button.disabled = ! ( i > -1 && i <= furthest && key !== name && 'done' !== name && 'done' !== key );
				if ( key === name ) {
					button.setAttribute( 'aria-current', 'step' );
				} else {
					button.removeAttribute( 'aria-current' );
				}
			}
		} );

		if ( compact ) {
			compact.textContent = format( i18n.stepOf, index + 1, total );
		}
		try {
			window.history.replaceState( null, '', '#' + name );
		} catch ( e ) {
			// Ignore (file:// or sandboxed contexts).
		}
		window.scrollTo( 0, 0 );

		var heading = stepEl( name ) ? stepEl( name ).querySelector( 'h1' ) : null;
		if ( heading ) {
			if ( false !== focus ) {
				heading.focus( { preventScroll: true } );
			}
			announce( heading.textContent.trim() );
		}
		refreshConditions();
		if ( 'done' === name ) {
			celebrate();
		}
	}

	function next() {
		var names = stepNames();
		var index = names.indexOf( current );
		show( names[ Math.min( names.length - 1, index + 1 ) ] );
	}

	function prev() {
		var names = stepNames();
		var index = names.indexOf( current );
		show( names[ Math.max( 0, index - 1 ) ] );
	}

	/* ------------------------------------------------------------------
	 * Conditional fields.
	 * ------------------------------------------------------------------ */

	function controlValue( scope, name ) {
		var controls = scope.querySelectorAll( '[name="' + name + '"]' );
		var value = '';
		Array.prototype.forEach.call( controls, function ( control ) {
			if ( 'radio' === control.type ) {
				if ( control.checked ) {
					value = control.value;
				}
			} else if ( 'checkbox' === control.type ) {
				value = control.checked ? control.value : '';
			} else {
				value = control.value;
			}
		} );
		return value;
	}

	function refreshConditions() {
		document.querySelectorAll( '[data-show-when]' ).forEach( function ( el ) {
			var rule = el.getAttribute( 'data-show-when' ).split( ':' );
			var scope = el.closest( 'form' ) || document;
			el.hidden = -1 === ( rule[ 1 ] || '' ).split( '|' ).indexOf( controlValue( scope, rule[ 0 ] ) );
		} );
		var country = document.getElementById( 'ob-store_country' );
		var isEu = !! country && -1 !== ( data.eu || [] ).indexOf( country.value );
		document.querySelectorAll( '[data-eu-only]' ).forEach( function ( el ) {
			el.hidden = ! isEu;
		} );
	}

	/* ------------------------------------------------------------------
	 * Validation & errors.
	 * ------------------------------------------------------------------ */

	function isVisible( el ) {
		return !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length );
	}

	function clearErrors( form ) {
		form.querySelectorAll( '[data-error-for]' ).forEach( function ( p ) {
			p.hidden = true;
			p.textContent = '';
		} );
		form.querySelectorAll( '[aria-invalid="true"]' ).forEach( function ( el ) {
			el.removeAttribute( 'aria-invalid' );
			el.removeAttribute( 'aria-errormessage' );
		} );
		var box = form.querySelector( '[data-form-error]' );
		if ( box ) {
			box.hidden = true;
			box.textContent = '';
		}
	}

	function fieldError( form, name, message ) {
		var input = form.querySelector( '[name="' + name + '"]' );
		var p = form.querySelector( '[data-error-for="' + name + '"]' );
		if ( p ) {
			p.id = p.id || 'ob-err-' + name;
			p.textContent = message;
			p.hidden = false;
		}
		if ( input ) {
			input.setAttribute( 'aria-invalid', 'true' );
			if ( p ) {
				input.setAttribute( 'aria-errormessage', p.id );
			}
		}
	}

	function formError( form, message ) {
		var box = form.querySelector( '[data-form-error]' );
		if ( box ) {
			box.textContent = message;
			box.hidden = false;
		}
	}

	function focusFirstInvalid( form ) {
		var first = form.querySelector( '[aria-invalid="true"]' );
		if ( first && isVisible( first ) ) {
			first.focus();
		}
	}

	function validIban( value ) {
		var iban = String( value || '' ).replace( /[^A-Za-z0-9]/g, '' ).toUpperCase();
		if ( ! /^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test( iban ) ) {
			return false;
		}
		var moved = iban.slice( 4 ) + iban.slice( 0, 4 );
		var numeric = moved.replace( /[A-Z]/g, function ( c ) {
			return String( c.charCodeAt( 0 ) - 55 );
		} );
		var remainder = 0;
		for ( var i = 0; i < numeric.length; i += 7 ) {
			remainder = parseInt( String( remainder ) + numeric.slice( i, i + 7 ), 10 ) % 97;
		}
		return 1 === remainder;
	}

	function validate( form ) {
		var ok = true;
		form.querySelectorAll( '[required]' ).forEach( function ( input ) {
			if ( ! isVisible( input ) ) {
				return;
			}
			if ( ! String( input.value || '' ).trim() ) {
				fieldError( form, input.name, i18n.required );
				ok = false;
			} else if ( input.validity && input.validity.typeMismatch ) {
				fieldError( form, input.name, input.validationMessage || i18n.required );
				ok = false;
			}
		} );
		var bank = form.querySelector( '[name="bank_enabled"]' );
		if ( bank && bank.checked ) {
			var iban = form.querySelector( '[name="bank_iban"]' );
			var holder = form.querySelector( '[name="bank_account_holder"]' );
			if ( holder && ! holder.value.trim() ) {
				fieldError( form, 'bank_account_holder', i18n.required );
				ok = false;
			}
			if ( iban && ! validIban( iban.value ) ) {
				fieldError( form, 'bank_iban', i18n.ibanInvalid );
				ok = false;
			}
		}
		if ( ! ok ) {
			focusFirstInvalid( form );
		}
		return ok;
	}

	function busy( form, on ) {
		var button = form.querySelector( 'button[type="submit"]' );
		form.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		if ( ! button ) {
			return;
		}
		var label = button.querySelector( '[data-label]' ) || button;
		button.disabled = on;
		button.classList.toggle( 'is-busy', on );
		if ( on ) {
			label.setAttribute( 'data-text', label.textContent );
			label.textContent = i18n.saving;
		} else if ( label.getAttribute( 'data-text' ) ) {
			label.textContent = label.getAttribute( 'data-text' );
		}
	}

	/* ------------------------------------------------------------------
	 * Steps.
	 * ------------------------------------------------------------------ */

	function fillDone( result ) {
		document.querySelectorAll( '[data-done-link]' ).forEach( function ( link ) {
			var key = link.getAttribute( 'data-done-link' );
			if ( result[ key ] ) {
				link.href = result[ key ];
			}
		} );
		var list = document.querySelector( '[data-ob-summary]' );
		if ( list ) {
			list.textContent = '';
			( result.summary || [] ).forEach( function ( line ) {
				var li = document.createElement( 'li' );
				li.textContent = line;
				list.appendChild( li );
			} );
		}
		var tip = document.querySelector( '[data-ob-buy]' );
		if ( tip && result.buy_url ) {
			tip.hidden = false;
			tip.querySelector( '[data-ob-buy-url]' ).textContent = result.buy_url;
		}
	}

	document.querySelectorAll( 'form[data-save]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( 'true' === form.getAttribute( 'aria-busy' ) ) {
				return;
			}
			clearErrors( form );
			if ( ! validate( form ) ) {
				return;
			}
			var step = form.getAttribute( 'data-save' );
			var body = new window.FormData( form );
			body.append( 'action', 'wahou_onboarding_save' );
			body.append( 'nonce', data.nonce );
			body.append( 'step', step );
			busy( form, true );
			request( body ).then( function ( json ) {
				if ( ! json.success ) {
					var details = json.data || {};
					var fields = details.fields || {};
					Object.keys( fields ).forEach( function ( name ) {
						fieldError( form, name, fields[ name ] );
					} );
					formError( form, details.message || i18n.error );
					focusFirstInvalid( form );
					return;
				}
				if ( 'welcome' === step ) {
					sells = ( json.data && json.data.sells ) || body.get( 'sells' ) || sells;
				}
				if ( 'legal' === step ) {
					fillDone( json.data || {} );
				}
				busy( form, false );
				next();
			} ).catch( function ( error ) {
				formError( form, ( error && error.message ) || i18n.error );
			} ).then( function () {
				busy( form, false );
			} );
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest ? event.target.closest( '[data-back], [data-skip], [data-goto], [data-swatch]' ) : null;
		if ( ! target ) {
			return;
		}
		if ( target.hasAttribute( 'data-back' ) ) {
			prev();
		} else if ( target.hasAttribute( 'data-skip' ) ) {
			next();
		} else if ( target.hasAttribute( 'data-goto' ) && ! target.disabled ) {
			show( target.getAttribute( 'data-goto' ) );
		} else if ( target.hasAttribute( 'data-swatch' ) ) {
			var color = document.getElementById( 'ob-brand_color' );
			if ( color ) {
				color.value = target.getAttribute( 'data-swatch' );
				color.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
			}
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var el = event.target;
		if ( ! el || ! el.name ) {
			refreshConditions();
			return;
		}
		if ( 'store_country' === el.name && ! currencyTouched ) {
			var currency = document.getElementById( 'ob-currency' );
			var suggested = ( data.currencies || {} )[ el.value ] || ( -1 !== ( data.eu || [] ).indexOf( el.value ) ? 'EUR' : '' );
			if ( currency && suggested && currency.querySelector( 'option[value="' + suggested + '"]' ) ) {
				currency.value = suggested;
			}
		}
		if ( 'currency' === el.name ) {
			currencyTouched = true;
		}
		if ( 'button_style' === el.name ) {
			var preview = document.querySelector( '[data-preview]' );
			if ( preview ) {
				preview.setAttribute( 'data-style', el.value );
			}
		}
		if ( /^(stripe|paypal)_mode$/.test( el.name ) ) {
			var card = el.closest( '[data-gateway]' );
			var publicInput = card ? card.querySelector( '[data-key="public"]' ) : null;
			var secretInput = card ? card.querySelector( '[data-key="secret"]' ) : null;
			if ( card && 'stripe' === card.getAttribute( 'data-gateway' ) && publicInput && secretInput ) {
				publicInput.placeholder = 'live' === el.value ? 'pk_live_…' : 'pk_test_…';
				secretInput.placeholder = 'live' === el.value ? 'sk_live_…' : 'sk_test_…';
			}
		}
		refreshConditions();
	} );

	/* ------------------------------------------------------------------
	 * Payments.
	 * ------------------------------------------------------------------ */

	document.querySelectorAll( '[data-connect]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var card = button.closest( '[data-gateway]' );
			var gateway = card.getAttribute( 'data-gateway' );
			var modeInput = card.querySelector( 'input[name="' + gateway + '_mode"]:checked' );
			var mode = modeInput ? modeInput.value : '';
			var publicKey = card.querySelector( '[data-key="public"]' ).value.trim();
			var secretInput = card.querySelector( '[data-key="secret"]' );
			var secret = secretInput.value.trim();
			var result = card.querySelector( '[data-gateway-result]' );

			function say( message, ok ) {
				result.hidden = false;
				result.textContent = message;
				result.classList.toggle( 'is-error', ! ok );
				result.classList.toggle( 'is-success', !! ok );
			}

			if ( ! publicKey || ! secret ) {
				say( i18n.missingKeys, false );
				return;
			}
			if ( 'stripe' === gateway ) {
				var expected = 'live' === mode ? 'live' : 'test';
				if ( 0 !== publicKey.indexOf( 'pk_' + expected + '_' ) || ! /^(sk|rk)_/.test( secret ) || -1 === secret.indexOf( '_' + expected + '_' ) ) {
					say( i18n.stripeKeyMismatch, false );
					return;
				}
			}

			var original = button.textContent;
			button.disabled = true;
			button.textContent = i18n.connecting;
			card.setAttribute( 'aria-busy', 'true' );

			var body = new window.FormData();
			body.append( 'action', 'wahou_gateway_connect' );
			body.append( '_ajax_nonce', data.connectNonce );
			body.append( 'gateway', gateway );
			body.append( 'mode', mode );
			body.append( 'public_key', publicKey );
			body.append( 'secret', secret );

			request( body ).then( function ( json ) {
				if ( ! json.success ) {
					say( ( json.data && json.data.message ) || i18n.error, false );
					return;
				}
				var details = json.data || {};
				card.classList.add( 'is-connected' );
				card.querySelector( '[data-gateway-status]' ).textContent = i18n.connected;
				card.querySelector( '[data-gateway-connected]' ).value = '1';
				var info = card.querySelector( '[data-gateway-info]' );
				if ( info ) {
					info.hidden = false;
					info.textContent = [ details.account_name, 'live' === mode ? i18n.liveMode : i18n.testMode ].filter( Boolean ).join( ' · ' );
				}
				secretInput.value = '';
				say( details.message || i18n.connected, true );
			} ).catch( function ( error ) {
				say( ( error && error.message ) || i18n.error, false );
			} ).then( function () {
				button.disabled = false;
				button.textContent = original;
				card.removeAttribute( 'aria-busy' );
			} );
		} );
	} );

	/* ------------------------------------------------------------------
	 * Design preview.
	 * ------------------------------------------------------------------ */

	function onBrand( hex ) {
		var m = /^#?([0-9a-f]{6})$/i.exec( hex || '' );
		if ( ! m ) {
			return '#ffffff';
		}
		var channels = [ 0, 2, 4 ].map( function ( i ) {
			var c = parseInt( m[ 1 ].slice( i, i + 2 ), 16 ) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		var luminance = 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
		return 1.05 / ( luminance + 0.05 ) >= 4.5 ? '#ffffff' : '#111827';
	}

	function applyBrand( hex ) {
		document.documentElement.style.setProperty( '--ob-brand', hex );
		document.documentElement.style.setProperty( '--ob-on-brand', onBrand( hex ) );
		document.querySelectorAll( '[data-swatch]' ).forEach( function ( swatch ) {
			swatch.setAttribute( 'aria-pressed', swatch.getAttribute( 'data-swatch' ).toLowerCase() === String( hex ).toLowerCase() ? 'true' : 'false' );
		} );
	}

	var brandInput = document.getElementById( 'ob-brand_color' );
	if ( brandInput ) {
		brandInput.addEventListener( 'input', function () {
			applyBrand( brandInput.value );
		} );
		applyBrand( brandInput.value );
	}

	/* ------------------------------------------------------------------
	 * Celebration.
	 * ------------------------------------------------------------------ */

	function celebrate() {
		if ( celebrated || reduceMotion ) {
			return;
		}
		celebrated = true;
		var canvas = document.querySelector( '[data-ob-confetti]' );
		if ( ! canvas || ! canvas.getContext ) {
			return;
		}
		var ctx = canvas.getContext( '2d' );
		var ratio = window.devicePixelRatio || 1;
		var width = window.innerWidth;
		var height = window.innerHeight;
		canvas.width = width * ratio;
		canvas.height = height * ratio;
		ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
		canvas.classList.add( 'is-active' );

		var brand = window.getComputedStyle( document.documentElement ).getPropertyValue( '--ob-brand' ).trim() || '#5b3df5';
		var colors = [ brand, '#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#ec4899', '#facc15' ];
		var pieces = [];
		for ( var i = 0; i < 170; i++ ) {
			pieces.push( {
				x: width / 2 + ( Math.random() - 0.5 ) * width * 0.3,
				y: height * 0.32,
				vx: ( Math.random() - 0.5 ) * 15,
				vy: -Math.random() * 15 - 5,
				size: 6 + Math.random() * 7,
				rotation: Math.random() * Math.PI,
				spin: ( Math.random() - 0.5 ) * 0.35,
				color: colors[ i % colors.length ]
			} );
		}
		var duration = 3600;
		var start = null;

		function frame( now ) {
			if ( null === start ) {
				start = now;
			}
			var elapsed = now - start;
			ctx.clearRect( 0, 0, width, height );
			pieces.forEach( function ( p ) {
				p.vy += 0.38;
				p.vx *= 0.99;
				p.x += p.vx;
				p.y += p.vy;
				p.rotation += p.spin;
				ctx.save();
				ctx.translate( p.x, p.y );
				ctx.rotate( p.rotation );
				ctx.globalAlpha = Math.max( 0, 1 - elapsed / duration );
				ctx.fillStyle = p.color;
				ctx.fillRect( -p.size / 2, -p.size / 4, p.size, p.size / 2 );
				ctx.restore();
			} );
			if ( elapsed < duration ) {
				window.requestAnimationFrame( frame );
			} else {
				ctx.clearRect( 0, 0, width, height );
				canvas.classList.remove( 'is-active' );
			}
		}
		window.requestAnimationFrame( frame );
	}

	/* ------------------------------------------------------------------
	 * Start.
	 * ------------------------------------------------------------------ */

	var names = stepNames();
	var startName = data.start || 'welcome';
	if ( -1 === names.indexOf( startName ) ) {
		startName = 'shipping' === startName ? 'design' : names[ 0 ];
	}
	furthest = names.indexOf( startName );
	var hash = String( window.location.hash || '' ).replace( '#', '' );
	if ( hash && names.indexOf( hash ) > -1 && names.indexOf( hash ) <= furthest && 'done' !== hash ) {
		startName = hash;
	}
	show( startName, false );
}() );
