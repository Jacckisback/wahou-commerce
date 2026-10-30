/**
 * Wahou Commerce – checkout, cart page, order received page, post-purchase
 * offers and passwordless account login.
 *
 * Gateways plug in with:
 *   window.WahouCheckout.registerGateway( 'stripe', { mount, onTotalsChange, validate, pay, expressMount, ownsSubmit } );
 * See includes/Gateways/Gateway.php for the full contract.
 */
( function () {
	'use strict';

	var data = window.wahouCheckoutData || {};
	var base = window.wahouData || {};
	var i18n = data.i18n || {};
	var restUrl = data.restUrl || base.restUrl || '';
	var nonce = data.nonce || base.nonce || '';
	var DRAFT_KEY = 'wahouCheckoutDraft';

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	function t( key, fallback ) {
		return i18n[ key ] || fallback || '';
	}

	function each( list, fn ) {
		Array.prototype.forEach.call( list || [], fn );
	}

	function debounce( fn, wait ) {
		var timer;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	function escapeHtml( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function isEmail( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( String( value || '' ).trim() );
	}

	function buildUrl( path, query ) {
		var url = restUrl + String( path ).replace( /^\//, '' );
		if ( query ) {
			var parts = [];
			Object.keys( query ).forEach( function ( key ) {
				if ( query[ key ] !== undefined && query[ key ] !== null && query[ key ] !== '' ) {
					parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( query[ key ] ) );
				}
			} );
			if ( parts.length ) {
				url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + parts.join( '&' );
			}
		}
		return url;
	}

	/**
	 * JSON request to the store API. PATCH/DELETE are tunnelled through POST
	 * (X-HTTP-Method-Override) because some hosts block them.
	 */
	function request( path, opts ) {
		opts = opts || {};
		var method = ( opts.method || 'GET' ).toUpperCase();
		var headers = { Accept: 'application/json' };
		if ( method === 'PATCH' || method === 'DELETE' || method === 'PUT' ) {
			headers[ 'X-HTTP-Method-Override' ] = method;
			method = 'POST';
		}
		if ( opts.body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}
		return fetch( buildUrl( path, opts.query ), {
			method: method,
			headers: headers,
			credentials: 'same-origin',
			body: opts.body !== undefined ? JSON.stringify( opts.body ) : undefined,
		} ).then(
			function ( response ) {
				return response.text().then( function ( text ) {
					var json = {};
					try {
						json = text ? JSON.parse( text ) : {};
					} catch ( e ) {
						json = {};
					}
					if ( ! response.ok ) {
						var error = new Error( json.message || t( 'genericError', 'Error' ) );
						error.code = json.code || '';
						error.data = json.data || {};
						error.status = response.status;
						throw error;
					}
					return json;
				} );
			},
			function () {
				var error = new Error( t( 'networkError', 'Network error' ) );
				error.code = 'network';
				throw error;
			}
		);
	}

	function track( event, payload ) {
		if ( ! payload ) {
			return;
		}
		if ( window.Wahou && typeof window.Wahou.track === 'function' ) {
			window.Wahou.track( event, payload );
			return;
		}
		if ( ! data.analytics && ! base.analytics ) {
			return;
		}
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( { ecommerce: null } );
		window.dataLayer.push( { event: event, ecommerce: payload } );
	}

	function notifyCart( cart, source ) {
		if ( ! cart ) {
			return;
		}
		cart.__source = source || 'checkout';
		document.dispatchEvent( new CustomEvent( 'wahou:cart-updated', { detail: cart } ) );
	}

	function setLoading( button, loading ) {
		if ( ! button ) {
			return;
		}
		button.disabled = !! loading;
		button.classList.toggle( 'is-loading', !! loading );
		button.setAttribute( 'aria-busy', loading ? 'true' : 'false' );
	}

	/* ------------------------------------------------------------------
	 * Checkout.
	 * ------------------------------------------------------------------ */

	var state = {
		root: null,
		form: null,
		ready: false,
		busy: false,
		seq: 0,
		totals: data.totals || null,
		needsShipping: !! data.needsShipping,
		hasDigital: !! data.hasDigital,
		gateways: {},
		mounted: {},
		expressMounted: {},
		lastOrderKey: '',
		shippingSignature: '',
	};

	var api = {
		collect: collect,
		validateForm: validateForm,
		checkConsents: checkConsents,
		submitOrder: submitOrder,
		showError: showError,
		clearError: clearError,
		setBusy: setBusy,
		redirect: redirect,
		totals: function () {
			return state.totals;
		},
		fill: fill,
		selectedGateway: selectedGateway,
		request: request,
		orderKey: function () {
			return state.lastOrderKey;
		},
	};

	window.WahouCheckout = window.WahouCheckout || {};
	window.WahouCheckout.api = api;
	window.WahouCheckout.registerGateway = function ( id, impl ) {
		if ( ! id || ! impl ) {
			return;
		}
		state.gateways[ id ] = impl;
		if ( state.ready ) {
			onGatewayRegistered( id );
		}
	};

	function qs( selector ) {
		return state.root ? state.root.querySelector( selector ) : null;
	}

	function qsa( selector ) {
		return state.root ? state.root.querySelectorAll( selector ) : [];
	}

	function element( name ) {
		return state.form ? state.form.elements.namedItem( name ) : null;
	}

	function isHidden( el ) {
		return !! ( el && el.closest && el.closest( '[hidden]' ) );
	}

	function fieldValue( name ) {
		var el = element( name );
		if ( ! el ) {
			return undefined;
		}
		if ( typeof el.length === 'number' && ! el.tagName ) {
			return el.value; // RadioNodeList.
		}
		if ( el.type === 'checkbox' ) {
			return el.checked;
		}
		return String( el.value || '' ).trim();
	}

	function isChecked( name ) {
		var el = element( name );
		return !! ( el && el.type === 'checkbox' && el.checked && ! isHidden( el ) );
	}

	function group( prefix ) {
		var out = {};
		each( state.form.elements, function ( el ) {
			if ( ! el.name || el.name.indexOf( prefix + '[' ) !== 0 || isHidden( el ) ) {
				return;
			}
			var key = el.name.slice( prefix.length + 1, -1 );
			out[ key ] = el.type === 'checkbox' ? el.checked : String( el.value || '' ).trim();
		} );
		return out;
	}

	function selectedGateway() {
		var checked = state.form ? state.form.querySelector( 'input[name="payment_method"]:checked' ) : null;
		return checked ? checked.value : '';
	}

	function taxGroup() {
		return state.needsShipping ? 'shipping' : 'billing';
	}

	function collect() {
		var payload = {
			email: fieldValue( 'email' ) || '',
			billing: group( 'billing' ),
			billing_different: !! element( 'billing_same' ) && ! element( 'billing_same' ).checked,
			is_business: isChecked( 'is_business' ),
			payment_method: selectedGateway(),
			terms: isChecked( 'terms' ),
			digital_waiver: isChecked( 'digital_waiver' ),
			marketing: isChecked( 'marketing' ),
			customer_note: fieldValue( 'customer_note' ) || '',
			wahou_hp: fieldValue( 'wahou_hp' ) || '',
		};
		if ( state.needsShipping ) {
			payload.shipping = group( 'shipping' );
			payload.shipping_method = fieldValue( 'shipping_method' ) || '';
		}
		return payload;
	}

	/* ---------------- Errors ---------------- */

	function fieldWrap( el ) {
		return ( el.closest && el.closest( '.wahou-field' ) ) || el.parentNode;
	}

	function setFieldError( el, message ) {
		var wrap = fieldWrap( el );
		wrap.classList.add( 'is-invalid' );
		el.setAttribute( 'aria-invalid', 'true' );
		var err = wrap.querySelector( '.wahou-field__error' );
		if ( err ) {
			err.textContent = message;
			err.hidden = false;
		}
	}

	function clearFieldError( el ) {
		if ( ! el || el.getAttribute( 'aria-invalid' ) !== 'true' ) {
			return;
		}
		var wrap = fieldWrap( el );
		wrap.classList.remove( 'is-invalid' );
		el.removeAttribute( 'aria-invalid' );
		var err = wrap.querySelector( '.wahou-field__error' );
		if ( err ) {
			err.textContent = '';
			err.hidden = true;
		}
	}

	function clearFieldErrors() {
		each( qsa( '[aria-invalid="true"]' ), clearFieldError );
		var gw = qs( '[data-wahou-gateway-error]' );
		if ( gw ) {
			gw.hidden = true;
		}
	}

	function focusField( el ) {
		if ( ! el ) {
			return;
		}
		try {
			el.focus( { preventScroll: true } );
		} catch ( e ) {
			el.focus();
		}
		var target = fieldWrap( el );
		if ( target && target.scrollIntoView ) {
			target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	function showError( message ) {
		var box = qs( '[data-wahou-checkout-error]' );
		if ( ! box ) {
			return;
		}
		box.textContent = message || t( 'genericError' );
		box.hidden = false;
		var rect = box.getBoundingClientRect();
		if ( rect.top < 0 || rect.bottom > window.innerHeight ) {
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	function clearError() {
		var box = qs( '[data-wahou-checkout-error]' );
		if ( box ) {
			box.hidden = true;
			box.textContent = '';
		}
	}

	function showGatewayError( message ) {
		var gw = qs( '[data-wahou-gateway-error]' );
		if ( gw ) {
			gw.textContent = message;
			gw.hidden = false;
		} else {
			showError( message );
		}
	}

	function validateForm() {
		clearFieldErrors();
		var first = null;
		each( state.form.elements, function ( el ) {
			if ( ! el.name || el.disabled || el.name === 'wahou_hp' || isHidden( el ) ) {
				return;
			}
			var message = '';
			if ( el.type === 'checkbox' ) {
				if ( el.required && ! el.checked ) {
					message = el.name === 'terms' || el.name === 'digital_waiver' ? t( 'consentsRequired' ) : t( 'required' );
				}
			} else if ( el.type === 'radio' ) {
				return;
			} else if ( el.required && ! String( el.value || '' ).trim() ) {
				message = t( 'required' );
			} else if ( el.type === 'email' && el.value && ! isEmail( el.value ) ) {
				message = t( 'invalidEmail' );
			}
			if ( message ) {
				setFieldError( el, message );
				first = first || el;
			}
		} );
		if ( ! first && needsPayment() && qs( '[data-wahou-gateways]' ) && ! selectedGateway() ) {
			showGatewayError( t( 'chooseGateway' ) );
			return false;
		}
		if ( first ) {
			showError( t( 'checkFields' ) );
			focusField( first );
			return false;
		}
		return true;
	}

	function checkConsents() {
		var ok = true;
		var first = null;
		each( qsa( '[data-wahou-consent]' ), function ( el ) {
			if ( el.required && ! el.checked ) {
				setFieldError( el, t( 'consentsRequired' ) );
				first = first || el;
				ok = false;
			}
		} );
		if ( first ) {
			focusField( first );
		}
		return ok;
	}

	function applyServerErrors( fields ) {
		var first = null;
		var orphans = [];
		Object.keys( fields || {} ).forEach( function ( key ) {
			var message = fields[ key ];
			if ( key === 'payment_method' ) {
				showGatewayError( message );
				return;
			}
			var dot = key.indexOf( '.' );
			var name = dot > -1 ? key.slice( 0, dot ) + '[' + key.slice( dot + 1 ) + ']' : key;
			var el = state.form.querySelector( '[name="' + name.replace( /(["\\])/g, '\\$1' ) + '"]' );
			if ( el && ! isHidden( el ) ) {
				setFieldError( el, message );
				first = first || el;
			} else {
				orphans.push( message );
			}
		} );
		if ( first ) {
			focusField( first );
		}
		return orphans;
	}

	/* ---------------- Busy & redirect ---------------- */

	function payButton() {
		return qs( '[data-wahou-pay]' );
	}

	function setBusy( busy ) {
		state.busy = !! busy;
		state.root.classList.toggle( 'is-busy', state.busy );
		state.form.setAttribute( 'aria-busy', state.busy ? 'true' : 'false' );
		setLoading( payButton(), state.busy );
	}

	function redirect( url ) {
		try {
			window.sessionStorage.removeItem( DRAFT_KEY );
		} catch ( e ) {}
		window.location.assign( url );
	}

	/* ---------------- Totals & summary ---------------- */

	function needsPayment() {
		return ! state.totals || Number( state.totals.total ) > 0;
	}

	function payLabel() {
		if ( ! needsPayment() ) {
			return data.freeLabel || '';
		}
		var label = data.payLabel || '%s';
		return label.split( '%s' ).join( state.totals ? state.totals.total_html : '' );
	}

	function updateTotalsText() {
		if ( ! state.totals ) {
			return;
		}
		each( qsa( '[data-wahou-total]' ), function ( el ) {
			el.textContent = state.totals.total_html;
		} );
		var label = qs( '[data-wahou-pay-label]' );
		if ( label ) {
			label.textContent = payLabel();
		}
		var payment = qs( '[data-wahou-payment-section]' );
		var freeNote = qs( '[data-wahou-free-note]' );
		if ( payment ) {
			payment.hidden = ! needsPayment();
		}
		if ( freeNote ) {
			freeNote.hidden = needsPayment();
		}
		var impl = state.form ? state.gateways[ selectedGateway() ] : null;
		var pay = payButton();
		if ( pay ) {
			pay.hidden = !! ( impl && impl.ownsSubmit && needsPayment() );
		}
	}

	function daysLabel( min, max ) {
		min = parseInt( min, 10 ) || 0;
		max = parseInt( max, 10 ) || 0;
		if ( max <= 0 ) {
			return '';
		}
		if ( min <= 0 || min >= max ) {
			return ( max === 1 ? t( 'dayOne', '%s' ) : t( 'daysMany', '%s' ) ).replace( '%s', max );
		}
		return t( 'daysRange', '%1$s–%2$s' ).replace( '%1$s', min ).replace( '%2$s', max );
	}

	function renderShippingOptions() {
		var box = qs( '[data-wahou-shipping-options]' );
		if ( ! box || ! state.totals ) {
			return;
		}
		var options = state.totals.shipping_options || [];
		var signature = JSON.stringify( [ options, state.totals.shipping_method ] );
		if ( signature === state.shippingSignature ) {
			return;
		}
		state.shippingSignature = signature;
		if ( ! options.length ) {
			var message = ( state.totals.errors && state.totals.errors.shipping ) || t( 'noShipping' );
			box.innerHTML = '<p class="wahou-notice wahou-notice--warning wahou-checkout__no-shipping">' + escapeHtml( message ) + '</p>';
			return;
		}
		box.innerHTML = options
			.map( function ( option ) {
				var checked = option.id === state.totals.shipping_method;
				var days = daysLabel( option.min_days, option.max_days );
				return (
					'<label class="wahou-option' + ( checked ? ' is-selected' : '' ) + '">' +
					'<input type="radio" name="shipping_method" value="' + escapeHtml( option.id ) + '"' + ( checked ? ' checked' : '' ) + '>' +
					'<span class="wahou-option__main"><span class="wahou-option__title">' + escapeHtml( option.title ) + '</span>' +
					( days ? '<span class="wahou-option__meta">' + escapeHtml( days ) + '</span>' : '' ) +
					'</span><span class="wahou-option__price">' + escapeHtml( Number( option.cost ) > 0 ? option.cost_html : t( 'free', 'Free' ) ) + '</span></label>'
				);
			} )
			.join( '' );
	}

	function liveGateways() {
		var seen = [];
		var list = [];
		Object.keys( state.gateways ).forEach( function ( id ) {
			if ( ( state.mounted[ id ] || state.expressMounted[ id ] ) && seen.indexOf( id ) === -1 ) {
				seen.push( id );
				list.push( state.gateways[ id ] );
			}
		} );
		return list;
	}

	function notifyGateways() {
		liveGateways().forEach( function ( impl ) {
			if ( typeof impl.onTotalsChange === 'function' ) {
				try {
					impl.onTotalsChange( state.totals );
				} catch ( e ) {
					if ( window.console ) {
						window.console.error( e );
					}
				}
			}
		} );
	}

	function updateBump( bump ) {
		var box = qs( '[data-wahou-bump]' );
		if ( ! box ) {
			return;
		}
		if ( ! bump || String( bump.id ) !== box.getAttribute( 'data-wahou-bump' ) ) {
			box.hidden = true;
			return;
		}
		box.hidden = false;
		var check = box.querySelector( '[data-wahou-bump-toggle]' );
		if ( check ) {
			check.checked = !! bump.in_cart;
		}
		box.classList.toggle( 'is-active', !! bump.in_cart );
	}

	function updateVatStatus() {
		var status = qs( '[data-wahou-vat-status]' );
		if ( ! status || status.getAttribute( 'data-state' ) !== 'valid' || ! state.totals ) {
			return;
		}
		status.textContent = ( status.getAttribute( 'data-name' ) ? status.getAttribute( 'data-name' ) + ' – ' : '' ) +
			( state.totals.tax_mode === 'reverse_charge' ? t( 'vatValidReverse' ) : t( 'vatValid' ) );
	}

	function applyCart( cart ) {
		if ( ! cart ) {
			return;
		}
		if ( cart.empty ) {
			window.location.reload();
			return;
		}
		state.totals = cart.totals || state.totals;
		var summary = qs( '[data-wahou-summary]' );
		if ( summary && cart.html && typeof cart.html.summary === 'string' ) {
			summary.innerHTML = cart.html.summary;
		}
		updateTotalsText();
		renderShippingOptions();
		updateBump( cart.bump );
		updateVatStatus();
		notifyGateways();
	}

	/* ---------------- Cart sync ---------------- */

	function customerFields() {
		var g = taxGroup();
		var fields = {
			first_name: fieldValue( g + '[first_name]' ),
			last_name: fieldValue( g + '[last_name]' ),
			country: fieldValue( g + '[country]' ),
			postcode: fieldValue( g + '[postcode]' ),
			city: fieldValue( g + '[city]' ),
		};
		var email = fieldValue( 'email' );
		if ( email && isEmail( email ) ) {
			fields.email = email;
		}
		if ( state.needsShipping ) {
			var method = fieldValue( 'shipping_method' );
			if ( method ) {
				fields.shipping_method = method;
			}
		}
		if ( isChecked( 'is_business' ) ) {
			fields.vat_number = fieldValue( 'billing[vat_number]' ) || '';
			fields.company = fieldValue( 'billing[company]' ) || '';
		} else if ( element( 'billing[vat_number]' ) ) {
			fields.vat_number = '';
		}
		Object.keys( fields ).forEach( function ( key ) {
			if ( fields[ key ] === undefined ) {
				delete fields[ key ];
			}
		} );
		return fields;
	}

	function syncCustomer( extra ) {
		var mine = ++state.seq;
		var body = customerFields();
		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				body[ key ] = extra[ key ];
			} );
		}
		state.root.classList.add( 'is-updating' );
		return request( 'cart/customer', { method: 'POST', body: body, query: { html: 'summary' } } )
			.then( function ( cart ) {
				if ( mine === state.seq ) {
					applyCart( cart );
				}
				return cart;
			} )
			.catch( function () {
				return null;
			} )
			.then( function ( cart ) {
				if ( mine === state.seq ) {
					state.root.classList.remove( 'is-updating' );
				}
				return cart;
			} );
	}

	var syncSoon = debounce( function () {
		syncCustomer();
	}, 350 );

	function vatCountry() {
		var billingCountry = element( 'billing[country]' );
		if ( billingCountry && ! isHidden( billingCountry ) ) {
			return billingCountry.value;
		}
		return fieldValue( taxGroup() + '[country]' ) || '';
	}

	function checkVat() {
		var input = element( 'billing[vat_number]' );
		var status = qs( '[data-wahou-vat-status]' );
		if ( ! input || ! status ) {
			return Promise.resolve();
		}
		var value = String( input.value || '' ).trim();
		status.removeAttribute( 'data-name' );
		if ( ! value ) {
			status.textContent = '';
			status.removeAttribute( 'data-state' );
			return syncCustomer();
		}
		status.textContent = t( 'vatChecking' );
		status.setAttribute( 'data-state', 'checking' );
		return request( 'vat/check', { method: 'POST', body: { vat_number: value, country: vatCountry() } } )
			.then( function ( result ) {
				if ( result.valid ) {
					status.setAttribute( 'data-state', 'valid' );
					if ( result.name ) {
						status.setAttribute( 'data-name', result.name );
					}
					status.textContent = ( result.name ? result.name + ' – ' : '' ) + t( 'vatValid' );
				} else if ( result.error === 'invalid' || result.error === 'format' ) {
					status.setAttribute( 'data-state', 'invalid' );
					status.textContent = t( 'vatInvalid' );
				} else {
					status.setAttribute( 'data-state', 'unavailable' );
					status.textContent = t( 'vatUnavailable' );
				}
			} )
			.catch( function () {
				status.setAttribute( 'data-state', 'unavailable' );
				status.textContent = t( 'vatUnavailable' );
			} )
			.then( function () {
				return syncCustomer();
			} );
	}

	/* ---------------- Gateways ---------------- */

	function gatewayContext( id, container ) {
		return {
			id: id,
			container: container,
			config: ( data.gateways || {} )[ id ] || {},
			totals: state.totals,
			api: api,
			needsShipping: state.needsShipping,
			hasDigital: state.hasDigital,
			currency: data.currency || base.currency || '',
			locale: data.locale || base.locale || '',
		};
	}

	function gatewayBody( id ) {
		var bodies = qsa( '[data-gateway-body]' );
		for ( var i = 0; i < bodies.length; i++ ) {
			if ( bodies[ i ].getAttribute( 'data-gateway-body' ) === id ) {
				return bodies[ i ];
			}
		}
		return null;
	}

	function mountGateway( id ) {
		var impl = state.gateways[ id ];
		if ( ! impl || state.mounted[ id ] ) {
			return;
		}
		state.mounted[ id ] = true;
		if ( typeof impl.mount === 'function' ) {
			try {
				impl.mount( gatewayContext( id, gatewayBody( id ) ) );
			} catch ( e ) {
				if ( window.console ) {
					window.console.error( e );
				}
			}
		}
	}

	function selectGateway( id ) {
		each( qsa( '[data-gateway]' ), function ( card ) {
			var active = card.getAttribute( 'data-gateway' ) === id;
			card.classList.toggle( 'is-selected', active );
			var body = card.querySelector( '[data-gateway-body]' );
			if ( body ) {
				body.hidden = ! active;
			}
		} );
		var gw = qs( '[data-wahou-gateway-error]' );
		if ( gw ) {
			gw.hidden = true;
		}
		if ( id ) {
			mountGateway( id );
		}
		var impl = state.gateways[ id ];
		var pay = payButton();
		if ( pay ) {
			pay.hidden = !! ( impl && impl.ownsSubmit && needsPayment() );
		}
	}

	function expressMount( id ) {
		var impl = state.gateways[ id ];
		var container = document.getElementById( 'wahou-express' );
		if ( ! impl || typeof impl.expressMount !== 'function' || ! container || state.expressMounted[ id ] ) {
			return;
		}
		state.expressMounted[ id ] = true;
		try {
			impl.expressMount( gatewayContext( id, container ) );
		} catch ( e ) {
			if ( window.console ) {
				window.console.error( e );
			}
		}
	}

	function onGatewayRegistered( id ) {
		expressMount( id );
		if ( selectedGateway() === id ) {
			selectGateway( id );
		}
	}

	function watchExpress() {
		var container = document.getElementById( 'wahou-express' );
		var divider = qs( '[data-wahou-express-divider]' );
		var section = qs( '[data-wahou-express-section]' );
		if ( ! container || ! divider ) {
			return;
		}
		var update = function () {
			var visible = false;
			each( container.children, function ( child ) {
				if ( ! child.hidden && child.style.display !== 'none' ) {
					visible = true;
				}
			} );
			divider.hidden = ! visible;
			if ( section ) {
				section.classList.toggle( 'has-express', visible );
			}
		};
		update();
		if ( window.MutationObserver ) {
			new MutationObserver( update ).observe( container, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'hidden', 'style' ] } );
		}
	}

	/* ---------------- Fill (express wallets) ---------------- */

	function setValue( name, value ) {
		var el = element( name );
		if ( ! el || ( typeof el.length === 'number' && ! el.tagName ) || value === undefined || value === null ) {
			return;
		}
		value = String( value );
		if ( el.tagName === 'SELECT' ) {
			var exists = Array.prototype.some.call( el.options, function ( option ) {
				return option.value === value;
			} );
			if ( ! exists ) {
				return;
			}
		}
		el.value = value;
		clearFieldError( el );
	}

	function fill( details ) {
		details = details || {};
		if ( details.email ) {
			setValue( 'email', details.email );
		}
		[ 'shipping', 'billing' ].forEach( function ( g ) {
			var d = details[ g ];
			if ( ! d ) {
				return;
			}
			if ( d.name && ! d.first_name ) {
				var parts = String( d.name ).trim().split( /\s+/ );
				d.first_name = parts.shift() || '';
				d.last_name = parts.join( ' ' );
			}
			Object.keys( d ).forEach( function ( key ) {
				setValue( g + '[' + key + ']', d[ key ] );
			} );
		} );
		if ( details.phone ) {
			setValue( 'shipping[phone]', details.phone );
		}
		saveDraft();
		return syncCustomer();
	}

	/* ---------------- Submit ---------------- */

	function submitOrder( extra ) {
		var payload = collect();
		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				payload[ key ] = extra[ key ];
			} );
		}
		clearError();
		return request( 'checkout', { method: 'POST', body: payload } ).then(
			function ( result ) {
				state.lastOrderKey = result.order_key || '';
				return result;
			},
			function ( error ) {
				var orphans = error.data && error.data.fields ? applyServerErrors( error.data.fields ) : [];
				showError( [ error.message ].concat( orphans ).join( ' ' ) );
				error.shown = true;
				throw error;
			}
		);
	}

	function handlePayment( result, impl ) {
		var payment = ( result && result.payment ) || {};
		if ( payment.action === 'redirect' && payment.redirect ) {
			redirect( payment.redirect );
			return Promise.resolve();
		}
		if ( impl && typeof impl.pay === 'function' ) {
			return Promise.resolve( impl.pay( payment, api ) ).then( function ( outcome ) {
				if ( outcome && outcome.redirect ) {
					redirect( outcome.redirect );
				}
			} );
		}
		return Promise.reject( new Error( t( 'gatewayUnavailable' ) ) );
	}

	function onSubmit( event ) {
		event.preventDefault();
		if ( state.busy ) {
			return;
		}
		var id = selectedGateway();
		var impl = id ? state.gateways[ id ] : null;
		if ( impl && impl.ownsSubmit && needsPayment() ) {
			showError( t( 'useGatewayButton' ) );
			return;
		}
		if ( ! validateForm() ) {
			return;
		}
		clearError();
		setBusy( true );
		Promise.resolve()
			.then( function () {
				return needsPayment() && impl && typeof impl.validate === 'function' ? impl.validate( api ) : true;
			} )
			.then( function () {
				return submitOrder();
			} )
			.then( function ( result ) {
				return handlePayment( result, needsPayment() ? impl : null );
			} )
			.catch( function ( error ) {
				if ( error && ! error.shown ) {
					showError( ( error && error.message ) || t( 'genericError' ) );
				}
				setBusy( false );
			} );
	}

	/* ---------------- Draft persistence ---------------- */

	var SKIP_DRAFT = [ 'wahou_hp', 'payment_method', 'terms', 'digital_waiver', 'shipping_method' ];

	function saveDraft() {
		if ( ! state.form ) {
			return;
		}
		try {
			var draft = {};
			each( state.form.elements, function ( el ) {
				if ( ! el.name || SKIP_DRAFT.indexOf( el.name ) !== -1 || el.type === 'radio' ) {
					return;
				}
				draft[ el.name ] = el.type === 'checkbox' ? el.checked : el.value;
			} );
			window.sessionStorage.setItem( DRAFT_KEY, JSON.stringify( draft ) );
		} catch ( e ) {}
	}

	var saveDraftSoon = debounce( saveDraft, 400 );

	function restoreDraft() {
		var draft = null;
		try {
			draft = JSON.parse( window.sessionStorage.getItem( DRAFT_KEY ) || 'null' );
		} catch ( e ) {
			draft = null;
		}
		if ( ! draft ) {
			return false;
		}
		var changed = false;
		Object.keys( draft ).forEach( function ( name ) {
			var el = element( name );
			if ( ! el || ( typeof el.length === 'number' && ! el.tagName ) ) {
				return;
			}
			if ( el.type === 'checkbox' ) {
				if ( [ 'billing_same', 'is_business', 'marketing' ].indexOf( name ) !== -1 && el.checked !== !! draft[ name ] ) {
					el.checked = !! draft[ name ];
					changed = true;
				}
			} else if ( ! el.value && draft[ name ] ) {
				if ( el.tagName === 'SELECT' && ! Array.prototype.some.call( el.options, function ( o ) {
					return o.value === draft[ name ];
				} ) ) {
					return;
				}
				el.value = draft[ name ];
				changed = true;
			}
		} );
		return changed;
	}

	/* ---------------- Toggles ---------------- */

	function applyToggles() {
		var same = element( 'billing_same' );
		var billing = qs( '[data-wahou-billing-fields]' );
		if ( same && billing ) {
			billing.hidden = same.checked;
		}
		var business = element( 'is_business' );
		var businessFields = qs( '[data-wahou-business-fields]' );
		if ( business && businessFields ) {
			businessFields.hidden = ! business.checked;
		}
	}

	/* ---------------- Init ---------------- */

	function initCheckout( root ) {
		state.root = root;
		state.form = root.querySelector( '#wahou-checkout-form' );
		if ( ! state.form ) {
			return;
		}
		state.needsShipping = state.form.getAttribute( 'data-needs-shipping' ) === '1';
		state.hasDigital = state.form.getAttribute( 'data-has-digital' ) === '1';
		root.classList.add( 'is-enhanced' );

		var restored = restoreDraft();
		applyToggles();
		updateTotalsText();
		if ( state.totals ) {
			state.shippingSignature = JSON.stringify( [ state.totals.shipping_options || [], state.totals.shipping_method ] );
		}

		// Summary toggle (mobile).
		var toggle = root.querySelector( '[data-wahou-summary-toggle]' );
		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var open = root.classList.toggle( 'is-summary-open' );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				var label = toggle.querySelector( '[data-wahou-summary-label]' );
				if ( label ) {
					label.textContent = open ? t( 'hideSummary' ) : t( 'showSummary' );
				}
			} );
		}

		state.form.addEventListener( 'submit', onSubmit );

		state.form.addEventListener( 'input', function ( event ) {
			clearFieldError( event.target );
			saveDraftSoon();
		} );

		state.form.addEventListener( 'change', function ( event ) {
			var el = event.target;
			if ( ! el || ! el.name ) {
				return;
			}
			clearFieldError( el );
			saveDraftSoon();

			if ( el.name === 'payment_method' ) {
				selectGateway( el.value );
				return;
			}
			if ( el.name === 'shipping_method' ) {
				each( qsa( '[data-wahou-shipping-options] .wahou-option' ), function ( option ) {
					option.classList.toggle( 'is-selected', !! option.querySelector( 'input:checked' ) );
				} );
				syncCustomer();
				return;
			}
			if ( el.hasAttribute( 'data-wahou-toggle' ) ) {
				applyToggles();
				if ( el.name === 'is_business' ) {
					if ( el.checked ) {
						var vatInput = element( 'billing[vat_number]' );
						if ( vatInput && vatInput.value ) {
							checkVat();
						}
					} else {
						syncSoon();
					}
				}
				return;
			}
			if ( el.hasAttribute( 'data-wahou-vat' ) ) {
				checkVat();
				return;
			}
			if ( el.hasAttribute( 'data-wahou-recovery-consent' ) ) {
				syncCustomer( { consent: !! el.checked } );
				return;
			}
			if ( el.hasAttribute( 'data-wahou-bump-toggle' ) ) {
				toggleBump( el );
				return;
			}
			if ( el.hasAttribute( 'data-wahou-sync' ) ) {
				if ( el.name === 'email' && el.value && ! isEmail( el.value ) ) {
					return;
				}
				syncSoon();
			}
		} );

		// Coupon (summary is re-rendered: delegate).
		root.addEventListener( 'click', function ( event ) {
			var apply = event.target.closest( '[data-wahou-coupon-apply]' );
			if ( apply ) {
				event.preventDefault();
				applyCoupon( apply.closest( '[data-wahou-coupon]' ), apply );
				return;
			}
			var remove = event.target.closest( '[data-wahou-coupon-remove]' );
			if ( remove ) {
				event.preventDefault();
				setLoading( remove, true );
				request( 'cart/coupon', { method: 'DELETE', query: { html: 'summary' } } )
					.then( applyCart )
					.catch( function ( error ) {
						showError( error.message );
						setLoading( remove, false );
					} );
			}
		} );
		root.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && event.target.matches && event.target.matches( '[data-wahou-coupon-input]' ) ) {
				event.preventDefault();
				var scope = event.target.closest( '[data-wahou-coupon]' );
				applyCoupon( scope, scope ? scope.querySelector( '[data-wahou-coupon-apply]' ) : null );
			}
		} );

		// The cart was changed elsewhere (drawer): refresh the summary.
		document.addEventListener( 'wahou:cart-updated', function ( event ) {
			if ( event.detail && event.detail.__source === 'checkout' ) {
				return;
			}
			request( 'cart', { query: { html: 'summary' } } ).then( applyCart ).catch( function () {} );
		} );

		watchExpress();
		state.ready = true;
		Object.keys( state.gateways ).forEach( expressMount );
		var current = selectedGateway();
		if ( current ) {
			selectGateway( current );
		}

		if ( restored ) {
			applyToggles();
			syncCustomer();
		}
		if ( data.beginCheckout ) {
			track( 'begin_checkout', data.beginCheckout );
		}
	}

	function applyCoupon( scope, button ) {
		if ( ! scope ) {
			return;
		}
		var input = scope.querySelector( '[data-wahou-coupon-input]' );
		var error = scope.querySelector( '[data-wahou-coupon-error]' );
		var code = input ? String( input.value || '' ).trim() : '';
		if ( ! code ) {
			if ( error ) {
				error.textContent = t( 'enterCoupon' );
				error.hidden = false;
			}
			if ( input ) {
				input.focus();
			}
			return;
		}
		setLoading( button, true );
		request( 'cart/coupon', { method: 'POST', body: { code: code }, query: { html: 'summary' } } )
			.then( function ( cart ) {
				applyCart( cart );
			} )
			.catch( function ( err ) {
				if ( error ) {
					error.textContent = err.message;
					error.hidden = false;
				}
				setLoading( button, false );
			} );
	}

	function toggleBump( checkbox ) {
		var box = checkbox.closest( '[data-wahou-bump]' );
		if ( ! box ) {
			return;
		}
		var add = !! checkbox.checked;
		box.classList.toggle( 'is-active', add );
		checkbox.disabled = true;
		request( 'cart/bump', {
			method: 'POST',
			body: { offer_id: parseInt( box.getAttribute( 'data-wahou-bump' ), 10 ), add: add },
			query: { html: 'summary' },
		} )
			.then( function ( cart ) {
				applyCart( cart );
				notifyCart( cart, 'checkout' );
			} )
			.catch( function ( error ) {
				checkbox.checked = ! add;
				box.classList.toggle( 'is-active', ! add );
				showError( error.message );
			} )
			.then( function () {
				checkbox.disabled = false;
			} );
	}

	/* ------------------------------------------------------------------
	 * Cart page.
	 * ------------------------------------------------------------------ */

	function initCartPage() {
		var pending = {};

		function root() {
			return document.querySelector( '[data-wahou-cart-page]' );
		}

		function replace( cart ) {
			var current = root();
			if ( ! current || ! cart || ! cart.html || typeof cart.html.page !== 'string' ) {
				return;
			}
			var holder = document.createElement( 'div' );
			holder.innerHTML = cart.html.page;
			var fresh = holder.querySelector( '[data-wahou-cart-page]' );
			if ( fresh ) {
				current.parentNode.replaceChild( fresh, current );
			}
			notifyCart( cart, 'cart-page' );
		}

		function fail( error ) {
			var box = root() ? root().querySelector( '[data-wahou-cp-error]' ) : null;
			if ( box ) {
				box.textContent = error.message || t( 'genericError' );
				box.hidden = false;
			}
			var current = root();
			if ( current ) {
				current.classList.remove( 'is-updating' );
			}
		}

		function call( path, opts ) {
			var current = root();
			if ( current ) {
				current.classList.add( 'is-updating' );
			}
			opts.query = { html: 'page' };
			return request( path, opts ).then( replace ).catch( fail );
		}

		function updateQty( key, qty ) {
			clearTimeout( pending[ key ] );
			pending[ key ] = setTimeout( function () {
				call( 'cart/items/' + key, { method: 'PATCH', body: { qty: qty } } );
			}, 450 );
		}

		document.addEventListener( 'click', function ( event ) {
			var scope = event.target.closest( '[data-wahou-cart-page]' );
			if ( ! scope ) {
				return;
			}
			var step = event.target.closest( '[data-wahou-cp-step]' );
			if ( step ) {
				event.preventDefault();
				var input = step.parentNode.querySelector( '[data-wahou-cp-qty]' );
				if ( ! input ) {
					return;
				}
				var max = parseInt( input.getAttribute( 'max' ), 10 ) || 999;
				var value = Math.max( 0, Math.min( max, ( parseInt( input.value, 10 ) || 0 ) + parseInt( step.getAttribute( 'data-wahou-cp-step' ), 10 ) ) );
				input.value = value;
				updateQty( input.getAttribute( 'data-key' ), value );
				return;
			}
			var remove = event.target.closest( '[data-wahou-cp-remove]' );
			if ( remove ) {
				event.preventDefault();
				var line = remove.closest( '.wahou-cart-line' );
				if ( line ) {
					line.classList.add( 'is-removing' );
				}
				call( 'cart/items/' + remove.getAttribute( 'data-wahou-cp-remove' ), { method: 'DELETE' } );
				return;
			}
			var add = event.target.closest( '[data-wahou-cp-add]' );
			if ( add ) {
				event.preventDefault();
				setLoading( add, true );
				call( 'cart/items', { method: 'POST', body: { product_id: parseInt( add.getAttribute( 'data-wahou-cp-add' ), 10 ), qty: 1 } } );
			}
		} );

		document.addEventListener( 'change', function ( event ) {
			var input = event.target.closest ? event.target.closest( '[data-wahou-cart-page] [data-wahou-cp-qty]' ) : null;
			if ( input ) {
				updateQty( input.getAttribute( 'data-key' ), Math.max( 0, parseInt( input.value, 10 ) || 0 ) );
			}
		} );

		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( ! form.closest || ! form.closest( '[data-wahou-cart-page]' ) ) {
				return;
			}
			if ( form.hasAttribute( 'data-wahou-cp-coupon' ) ) {
				event.preventDefault();
				var input = form.querySelector( '[data-wahou-coupon-input]' );
				var error = form.parentNode.querySelector( '[data-wahou-coupon-error]' );
				var code = input ? String( input.value || '' ).trim() : '';
				if ( ! code ) {
					if ( error ) {
						error.textContent = t( 'enterCoupon' );
						error.hidden = false;
					}
					return;
				}
				setLoading( form.querySelector( 'button[type="submit"]' ), true );
				request( 'cart/coupon', { method: 'POST', body: { code: code }, query: { html: 'page' } } )
					.then( replace )
					.catch( function ( err ) {
						setLoading( form.querySelector( 'button[type="submit"]' ), false );
						if ( error ) {
							error.textContent = err.message;
							error.hidden = false;
						}
					} );
			} else if ( form.hasAttribute( 'data-wahou-cp-coupon-remove' ) ) {
				event.preventDefault();
				call( 'cart/coupon', { method: 'DELETE' } );
			} else if ( form.hasAttribute( 'data-wahou-cp-form' ) ) {
				// Enter in a quantity field: update every changed line now.
				event.preventDefault();
				each( form.querySelectorAll( '[data-wahou-cp-qty]' ), function ( input ) {
					if ( String( input.value ) !== String( input.defaultValue ) ) {
						updateQty( input.getAttribute( 'data-key' ), Math.max( 0, parseInt( input.value, 10 ) || 0 ) );
					}
				} );
			}
		} );

		// Cart changed in the drawer: refresh the page.
		document.addEventListener( 'wahou:cart-updated', function ( event ) {
			if ( event.detail && event.detail.__source === 'cart-page' ) {
				return;
			}
			request( 'cart', { query: { html: 'page' } } ).then( function ( cart ) {
				var current = root();
				if ( ! current || ! cart || ! cart.html ) {
					return;
				}
				var holder = document.createElement( 'div' );
				holder.innerHTML = cart.html.page;
				var fresh = holder.querySelector( '[data-wahou-cart-page]' );
				if ( fresh ) {
					current.parentNode.replaceChild( fresh, current );
				}
			} ).catch( function () {} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Order received page.
	 * ------------------------------------------------------------------ */

	function initThankYou( root ) {
		var key = root.getAttribute( 'data-order-key' );
		var json = root.querySelector( 'script[data-wahou-purchase]' );
		if ( json ) {
			try {
				track( 'purchase', JSON.parse( json.textContent ) );
			} catch ( e ) {}
		}
		try {
			window.sessionStorage.removeItem( DRAFT_KEY );
		} catch ( e ) {}

		if ( ! root.hasAttribute( 'data-wahou-poll' ) || ! key ) {
			return;
		}
		var attempt = 0;
		var poll = function () {
			if ( attempt >= 30 ) {
				var still = root.querySelector( '[data-wahou-still-processing]' );
				if ( still ) {
					still.hidden = false;
				}
				return;
			}
			setTimeout( function () {
				attempt++;
				request( 'order/' + encodeURIComponent( key ) )
					.then( function ( result ) {
						if ( result.paid || [ 'on-hold', 'failed', 'cancelled' ].indexOf( result.status ) !== -1 ) {
							if ( result.redirect ) {
								window.location.assign( result.redirect );
							} else {
								window.location.reload();
							}
							return;
						}
						poll();
					} )
					.catch( poll );
			}, attempt < 6 ? 1500 : 4000 );
		};
		poll();
	}

	/* ------------------------------------------------------------------
	 * Post-purchase offer.
	 * ------------------------------------------------------------------ */

	function initOffer( root ) {
		var key = root.getAttribute( 'data-order-key' );
		var id = root.getAttribute( 'data-offer-id' );
		var fallback = root.getAttribute( 'data-fallback' );
		var accept = root.querySelector( '[data-wahou-offer-accept]' );
		var decline = root.querySelector( '[data-wahou-offer-decline]' );
		var error = root.querySelector( '[data-wahou-offer-error]' );
		var busy = false;

		function go( url ) {
			window.location.assign( url || fallback );
		}

		function fail( message ) {
			busy = false;
			setLoading( accept, false );
			if ( decline ) {
				decline.disabled = false;
			}
			if ( error ) {
				error.textContent = message || t( 'offerError' );
				error.hidden = false;
			}
		}

		if ( accept ) {
			accept.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( busy ) {
					return;
				}
				busy = true;
				setLoading( accept, true );
				if ( decline ) {
					decline.disabled = true;
				}
				request( 'offer/' + id + '/accept', { method: 'POST', body: { order_key: key } } )
					.then( function ( result ) {
						if ( result.status === 'requires_action' && result.client_secret ) {
							if ( window.WahouStripe && typeof window.WahouStripe.handleNextAction === 'function' ) {
								return Promise.resolve( window.WahouStripe.handleNextAction( result.client_secret ) ).then( function () {
									go( result.redirect );
								} );
							}
							throw new Error( t( 'offerError' ) );
						}
						go( result.redirect );
					} )
					.catch( function ( err ) {
						fail( err && err.message );
					} );
			} );
		}
		if ( decline ) {
			decline.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( busy ) {
					return;
				}
				busy = true;
				decline.disabled = true;
				request( 'offer/' + id + '/decline', { method: 'POST', body: { order_key: key } } )
					.then( function ( result ) {
						go( result.redirect );
					} )
					.catch( function () {
						go( fallback );
					} );
			} );
		}
	}

	/* ------------------------------------------------------------------
	 * Magic link (account page and thank-you page).
	 * ------------------------------------------------------------------ */

	function sendMagicLink( email, button, messageBox, redirectTo ) {
		setLoading( button, true );
		return request( 'account/magic-link', { method: 'POST', body: { email: email, redirect: redirectTo || '' } } )
			.then( function ( result ) {
				if ( messageBox ) {
					messageBox.className = messageBox.className.replace( /wahou-notice--\w+/, 'wahou-notice--success' );
					messageBox.textContent = result.message || '';
					messageBox.hidden = false;
				}
				// Keep the button disabled for a moment: avoids repeated emails.
				setTimeout( function () {
					setLoading( button, false );
				}, 30000 );
			} )
			.catch( function ( error ) {
				setLoading( button, false );
				if ( messageBox ) {
					messageBox.className = messageBox.className.replace( /wahou-notice--\w+/, 'wahou-notice--error' );
					messageBox.textContent = error.message;
					messageBox.hidden = false;
				}
			} );
	}

	function initMagicForms() {
		each( document.querySelectorAll( '[data-wahou-magic-form]' ), function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var input = form.querySelector( 'input[type="email"]' );
				if ( ! input || ! isEmail( input.value ) ) {
					return; // Let the browser show its validation message.
				}
				event.preventDefault();
				var container = form.closest( '[data-wahou-account]' ) || form.parentNode;
				var redirectField = form.querySelector( 'input[name="redirect_to"]' );
				sendMagicLink(
					input.value.trim(),
					form.querySelector( 'button[type="submit"]' ),
					container.querySelector( '[data-wahou-magic-message]' ),
					redirectField ? redirectField.value : ''
				);
			} );
		} );
		each( document.querySelectorAll( '[data-wahou-magic-link]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var box = button.parentNode.querySelector( '[data-wahou-magic-message]' );
				sendMagicLink( button.getAttribute( 'data-email' ) || '', button, box );
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Boot.
	 * ------------------------------------------------------------------ */

	function init() {
		var checkout = document.querySelector( '[data-wahou-checkout]' );
		if ( checkout ) {
			initCheckout( checkout );
		}
		if ( document.querySelector( '[data-wahou-cart-page]' ) ) {
			initCartPage();
		}
		var thankyou = document.querySelector( '[data-wahou-thankyou]' );
		if ( thankyou ) {
			initThankYou( thankyou );
		}
		var offer = document.querySelector( '[data-wahou-offer]' );
		if ( offer ) {
			initOffer( offer );
		}
		initMagicForms();
		if ( ! checkout ) {
			state.ready = true;
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
