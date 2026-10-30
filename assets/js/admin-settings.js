/**
 * Wahou Commerce – settings screen.
 *
 * Shipping zones editor, payment gateway connection, conditional fields,
 * country pickers, reminder sequence, design preview and small helpers.
 * Vanilla JS, no build step.
 */
( function () {
	'use strict';

	const data = window.wahouAdmin || {};
	const i18n = data.i18n || {};
	const nonces = data.nonces || {};
	const currency = data.currency || { symbol: '€' };

	const t = ( key, fallback ) => i18n[ key ] || fallback || key;
	const $ = ( selector, context = document ) => context.querySelector( selector );
	const $$ = ( selector, context = document ) => Array.from( context.querySelectorAll( selector ) );
	let sequence = Date.now();

	function uid( prefix ) {
		return prefix + ( ++sequence ).toString( 36 );
	}

	function el( tag, attrs = {}, children = [] ) {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ key, value ] ) => {
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'class' ) {
				node.className = value;
			} else if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'html' ) {
				node.innerHTML = value;
			} else if ( value === true ) {
				node.setAttribute( key, '' );
			} else {
				node.setAttribute( key, String( value ) );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( child ) => {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function icon( name ) {
		return el( 'span', { class: 'dashicons ' + name, 'aria-hidden': 'true' } );
	}

	/* ------------------------------------------------------------------
	 * Unsaved changes.
	 * ------------------------------------------------------------------ */

	const form = $( '[data-wahou-settings-form]' );
	let dirty = false;

	function markDirty() {
		if ( ! form ) {
			return;
		}
		dirty = true;
		const hint = $( '[data-unsaved-hint]' );
		if ( hint ) {
			hint.hidden = false;
		}
	}

	if ( form ) {
		const onEdit = ( event ) => {
			if ( event.target && event.target.name ) {
				markDirty();
			}
		};
		form.addEventListener( 'input', onEdit );
		form.addEventListener( 'change', onEdit );
		form.addEventListener( 'submit', () => {
			dirty = false;
		} );
		window.addEventListener( 'beforeunload', ( event ) => {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Conditional fields: data-show-if="key", "key=value", "key!=value".
	 * ------------------------------------------------------------------ */

	function fieldValue( name ) {
		const inputs = $$( '[name="' + name + '"]' );
		if ( ! inputs.length ) {
			return null;
		}
		const first = inputs[ 0 ];
		if ( first.type === 'checkbox' ) {
			return inputs.some( ( input ) => input.checked ) ? '1' : '';
		}
		if ( first.type === 'radio' ) {
			const checked = inputs.find( ( input ) => input.checked );
			return checked ? checked.value : '';
		}
		return first.value;
	}

	function conditionMet( condition ) {
		const match = condition.match( /^(.+?)(!=|=)(.*)$/ );
		let name = match ? match[ 1 ] : condition;
		if ( name.indexOf( '[' ) === -1 ) {
			name = 'wahou[' + name + ']';
		}
		const current = fieldValue( name );
		if ( current === null ) {
			return true;
		}
		if ( match && match[ 2 ] === '=' ) {
			return current === match[ 3 ];
		}
		if ( match && match[ 2 ] === '!=' ) {
			return current !== match[ 3 ];
		}
		return current !== '' && current !== '0';
	}

	function applyShowIf() {
		$$( '[data-show-if]' ).forEach( ( node ) => {
			node.hidden = ! conditionMet( node.dataset.showIf );
		} );
	}

	/* ------------------------------------------------------------------
	 * Country multi-select → chips.
	 * ------------------------------------------------------------------ */

	function enhanceCountries( wrap ) {
		const select = $( 'select[multiple]', wrap );
		if ( ! select ) {
			return;
		}
		select.hidden = true;
		const chips = el( 'ul', { class: 'wahou-chips' } );
		const add = el( 'select', { class: 'wahou-input', 'aria-label': t( 'addCountry' ) } );
		const eu = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--small wahou-btn--secondary', text: t( 'addEu' ) } );
		const clear = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--small wahou-btn--ghost', text: t( 'clear' ) } );
		const options = () => Array.from( select.options );

		const render = () => {
			chips.innerHTML = '';
			options().filter( ( option ) => option.selected ).forEach( ( option ) => {
				const remove = el( 'button', { type: 'button', class: 'wahou-chip__remove', 'aria-label': t( 'remove' ) + ' ' + option.textContent, html: '&times;' } );
				remove.addEventListener( 'click', () => {
					option.selected = false;
					render();
					markDirty();
				} );
				chips.appendChild( el( 'li', { class: 'wahou-chip' }, [ el( 'span', { class: 'wahou-chip__label', text: option.textContent } ), remove ] ) );
			} );
			add.innerHTML = '';
			add.appendChild( el( 'option', { value: '', text: t( 'addCountry' ) } ) );
			options().filter( ( option ) => ! option.selected ).forEach( ( option ) => {
				add.appendChild( el( 'option', { value: option.value, text: option.textContent } ) );
			} );
			clear.hidden = ! options().some( ( option ) => option.selected );
		};

		add.addEventListener( 'change', () => {
			const option = options().find( ( item ) => item.value === add.value );
			if ( option ) {
				option.selected = true;
				render();
				markDirty();
			}
		} );
		eu.addEventListener( 'click', () => {
			const list = Array.isArray( data.eu ) ? data.eu : [];
			options().forEach( ( option ) => {
				if ( list.includes( option.value ) ) {
					option.selected = true;
				}
			} );
			render();
			markDirty();
		} );
		clear.addEventListener( 'click', () => {
			options().forEach( ( option ) => {
				option.selected = false;
			} );
			render();
			markDirty();
		} );

		wrap.appendChild( chips );
		wrap.appendChild( el( 'div', { class: 'wahou-countries__add' }, [ add, eu, clear ] ) );
		render();
	}

	/* ------------------------------------------------------------------
	 * Shipping zones.
	 * ------------------------------------------------------------------ */

	function initZones( root ) {
		const input = $( '[data-zones-input]', root );
		const list = $( '[data-zones-list]', root );
		let names = [];
		let eu = [];
		try {
			names = JSON.parse( $( '[data-zones-countries]', root ).textContent ) || [];
			eu = JSON.parse( $( '[data-zones-eu]', root ).textContent ) || [];
		} catch ( e ) {}
		const countries = new Map( names );
		let zones;
		try {
			zones = JSON.parse( input.value ) || [];
		} catch ( e ) {
			zones = [];
		}
		zones.forEach( ( zone ) => {
			zone.countries = Array.isArray( zone.countries ) ? zone.countries : [];
			zone.methods = Array.isArray( zone.methods ) ? zone.methods : [];
		} );

		const save = () => {
			input.value = JSON.stringify( zones );
			markDirty();
		};
		const countryLabel = ( code ) => ( code === '*' ? t( 'world' ) : countries.get( code ) || code );
		const newMethod = () => ( { id: '', type: 'flat', title: '', cost: '', min_amount: '', min_days: 2, max_days: 4, enabled: true } );

		function field( label, control, id ) {
			return el( 'div', { class: 'wahou-field' }, [ el( 'label', { class: 'wahou-field__label', for: id, text: label } ), control ] );
		}

		function moneyGroup( input ) {
			return el( 'span', { class: 'wahou-input-group' }, [ input, el( 'span', { class: 'wahou-input-group__addon', text: currency.symbol || '' } ) ] );
		}

		function renderMethod( zone, method, index, rerender ) {
			const typeId = uid( 'wahou-method-type-' );
			const type = el( 'select', { class: 'wahou-input', id: typeId }, [
				el( 'option', { value: 'flat', text: t( 'methodFlat' ) } ),
				el( 'option', { value: 'free', text: t( 'methodFree' ) } ),
				el( 'option', { value: 'pickup', text: t( 'methodPickup' ) } ),
			] );
			type.value = method.type || 'flat';
			type.addEventListener( 'change', () => {
				method.type = type.value;
				save();
				rerender();
			} );

			const titleId = uid( 'wahou-method-title-' );
			const title = el( 'input', { type: 'text', class: 'wahou-input', id: titleId, value: method.title || '', placeholder: t( 'optional' ), maxlength: 80 } );
			title.addEventListener( 'input', () => {
				method.title = title.value;
				save();
			} );

			const costId = uid( 'wahou-method-cost-' );
			const cost = el( 'input', { type: 'text', inputmode: 'decimal', class: 'wahou-input wahou-input--money', id: costId, value: method.cost || '', placeholder: '0' } );
			cost.addEventListener( 'input', () => {
				method.cost = cost.value.trim();
				save();
			} );

			const freeId = uid( 'wahou-method-free-' );
			const free = el( 'input', { type: 'text', inputmode: 'decimal', class: 'wahou-input wahou-input--money', id: freeId, value: method.min_amount || '', placeholder: '0', title: t( 'freeFromHelp' ) } );
			free.addEventListener( 'input', () => {
				method.min_amount = free.value.trim();
				save();
			} );

			const minId = uid( 'wahou-method-min-' );
			const min = el( 'input', { type: 'number', min: 0, max: 365, class: 'wahou-input', id: minId, value: method.min_days ?? '', 'aria-label': t( 'days' ) + ' – ' + t( 'daysMin' ) } );
			const max = el( 'input', { type: 'number', min: 0, max: 365, class: 'wahou-input', value: method.max_days ?? '', 'aria-label': t( 'days' ) + ' – ' + t( 'daysMax' ) } );
			min.addEventListener( 'input', () => {
				method.min_days = parseInt( min.value || '0', 10 );
				save();
			} );
			max.addEventListener( 'input', () => {
				method.max_days = parseInt( max.value || '0', 10 );
				save();
			} );

			const enabledId = uid( 'wahou-method-enabled-' );
			const enabled = el( 'input', { type: 'checkbox', id: enabledId } );
			enabled.checked = method.enabled !== false;
			enabled.addEventListener( 'change', () => {
				method.enabled = enabled.checked;
				row.classList.toggle( 'is-disabled', ! enabled.checked );
				save();
			} );
			const toggle = el( 'label', { class: 'wahou-toggle', for: enabledId }, [ enabled, el( 'span', { class: 'wahou-toggle__track', 'aria-hidden': 'true' } ), el( 'span', { class: 'wahou-toggle__label', text: t( 'enabled' ) } ) ] );

			const remove = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--ghost wahou-btn--icon', 'aria-label': t( 'remove' ) }, icon( 'dashicons-trash' ) );
			remove.addEventListener( 'click', () => {
				zone.methods.splice( index, 1 );
				save();
				rerender();
			} );

			const isFree = method.type === 'free';
			const row = el( 'div', { class: 'wahou-method' + ( method.enabled === false ? ' is-disabled' : '' ) }, [
				field( t( 'methodType' ), type, typeId ),
				field( t( 'methodTitle' ), title, titleId ),
				isFree ? field( t( 'freeFrom' ), moneyGroup( free ), freeId ) : field( t( 'cost' ), moneyGroup( cost ), costId ),
				el( 'div', { class: 'wahou-field' }, [
					el( 'label', { class: 'wahou-field__label', for: minId, text: t( 'days' ) } ),
					el( 'span', { class: 'wahou-method__days' }, [ min, '–', max ] ),
				] ),
				toggle,
				el( 'span' ),
				remove,
			] );
			return row;
		}

		function renderZone( zone, index ) {
			const nameId = uid( 'wahou-zone-name-' );
			const name = el( 'input', { type: 'text', class: 'wahou-input', id: nameId, value: zone.name || '', placeholder: t( 'newZone' ), maxlength: 80 } );
			name.addEventListener( 'input', () => {
				zone.name = name.value;
				save();
			} );
			const remove = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--ghost' }, [ icon( 'dashicons-trash' ), t( 'removeZone' ) ] );
			remove.addEventListener( 'click', () => {
				if ( window.confirm( t( 'confirmRemoveZone' ) ) ) {
					zones.splice( index, 1 );
					save();
					render();
				}
			} );

			const chips = el( 'ul', { class: 'wahou-chips' } );
			const warning = el( 'p', { class: 'wahou-field__help', text: t( 'noCountries' ) } );
			const add = el( 'select', { class: 'wahou-input', 'aria-label': t( 'addCountry' ) } );
			const renderChips = () => {
				chips.innerHTML = '';
				zone.countries.forEach( ( code ) => {
					const chipRemove = el( 'button', { type: 'button', class: 'wahou-chip__remove', 'aria-label': t( 'remove' ) + ' ' + countryLabel( code ), html: '&times;' } );
					chipRemove.addEventListener( 'click', () => {
						zone.countries = zone.countries.filter( ( item ) => item !== code );
						renderChips();
						save();
					} );
					chips.appendChild( el( 'li', { class: 'wahou-chip' }, [ el( 'span', { class: 'wahou-chip__label', text: countryLabel( code ) } ), chipRemove ] ) );
				} );
				add.innerHTML = '';
				add.appendChild( el( 'option', { value: '', text: t( 'addCountry' ) } ) );
				names.forEach( ( [ code, label ] ) => {
					if ( ! zone.countries.includes( code ) ) {
						add.appendChild( el( 'option', { value: code, text: label } ) );
					}
				} );
				warning.hidden = zone.countries.length > 0;
			};
			add.addEventListener( 'change', () => {
				if ( add.value && ! zone.countries.includes( add.value ) ) {
					zone.countries.push( add.value );
					renderChips();
					save();
				}
			} );
			const addEu = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--small wahou-btn--secondary', text: t( 'addEu' ) } );
			addEu.addEventListener( 'click', () => {
				eu.forEach( ( code ) => {
					if ( ! zone.countries.includes( code ) ) {
						zone.countries.push( code );
					}
				} );
				renderChips();
				save();
			} );
			const addWorld = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--small wahou-btn--secondary', text: t( 'addWorld' ) } );
			addWorld.addEventListener( 'click', () => {
				if ( ! zone.countries.includes( '*' ) ) {
					zone.countries.push( '*' );
					renderChips();
					save();
				}
			} );

			const methods = el( 'div', { class: 'wahou-zone__methods' } );
			const renderMethods = () => {
				methods.innerHTML = '';
				zone.methods.forEach( ( method, i ) => methods.appendChild( renderMethod( zone, method, i, renderMethods ) ) );
			};
			const addMethod = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--small wahou-btn--secondary', text: t( 'addMethod' ) } );
			addMethod.addEventListener( 'click', () => {
				zone.methods.push( newMethod() );
				save();
				renderMethods();
			} );

			renderChips();
			renderMethods();

			return el( 'div', { class: 'wahou-zone' }, [
				el( 'div', { class: 'wahou-zone__head' }, [ field( t( 'zoneName' ), name, nameId ), remove ] ),
				el( 'div', { class: 'wahou-field' }, [
					el( 'span', { class: 'wahou-field__label', text: t( 'countries' ) } ),
					chips,
					el( 'div', { class: 'wahou-countries__add' }, [ add, addEu, addWorld ] ),
					warning,
				] ),
				el( 'div', { class: 'wahou-field' }, [ el( 'span', { class: 'wahou-field__label', text: t( 'methods' ) } ), methods, addMethod ] ),
			] );
		}

		function render() {
			list.innerHTML = '';
			if ( ! zones.length ) {
				list.appendChild( el( 'p', { class: 'wahou-empty', text: t( 'noZones' ) } ) );
			}
			zones.forEach( ( zone, index ) => list.appendChild( renderZone( zone, index ) ) );
		}

		const addZone = $( '[data-zone-add]', root );
		if ( addZone ) {
			addZone.addEventListener( 'click', () => {
				zones.push( { id: '', name: '', countries: [], methods: [ newMethod() ] } );
				save();
				render();
				const last = list.lastElementChild;
				const first = last && $( 'input', last );
				if ( first ) {
					first.focus();
				}
			} );
		}
		render();
	}

	/* ------------------------------------------------------------------
	 * Repeaters (reminder sequence).
	 * ------------------------------------------------------------------ */

	function renumber( repeater ) {
		$$( '[data-step-number]', repeater ).forEach( ( node, index ) => {
			node.textContent = '#' + ( index + 1 );
		} );
		const add = $( '[data-add-row]', repeater );
		const max = parseInt( repeater.dataset.max || '0', 10 );
		if ( add && max ) {
			add.hidden = $$( '[data-row]', $( '[data-rows]', repeater ) ).length >= max;
		}
	}

	function initRepeaters() {
		$$( '[data-wahou-repeater]' ).forEach( renumber );
		document.addEventListener( 'click', ( event ) => {
			const add = event.target.closest( '[data-add-row]' );
			if ( add ) {
				const repeater = add.closest( '[data-wahou-repeater]' );
				const template = $( 'template[data-template]', repeater );
				const rows = $( '[data-rows]', repeater );
				if ( template && rows ) {
					rows.insertAdjacentHTML( 'beforeend', template.innerHTML.replace( /__i__/g, String( ++sequence ) ) );
					renumber( repeater );
					markDirty();
					const focusable = $( 'input:not([type="hidden"])', rows.lastElementChild );
					if ( focusable ) {
						focusable.focus();
					}
				}
				return;
			}
			const remove = event.target.closest( '[data-remove-row]' );
			if ( remove ) {
				const repeater = remove.closest( '[data-wahou-repeater]' );
				const row = remove.closest( '[data-row]' );
				if ( row ) {
					row.remove();
					renumber( repeater );
					markDirty();
				}
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Payment gateways.
	 * ------------------------------------------------------------------ */

	function messageFrom( json ) {
		if ( ! json ) {
			return t( 'genericError' );
		}
		const payload = json.data;
		if ( typeof payload === 'string' && payload ) {
			return payload;
		}
		if ( payload && payload.message ) {
			return payload.message;
		}
		if ( Array.isArray( payload ) && payload[ 0 ] && payload[ 0 ].message ) {
			return payload[ 0 ].message;
		}
		return t( 'genericError' );
	}

	function describe( value ) {
		if ( value === true ) {
			return '✓';
		}
		if ( value && typeof value === 'object' ) {
			return value.message || value.status || JSON.stringify( value );
		}
		return String( value );
	}

	function showResult( box, type, message, details = [] ) {
		if ( ! box ) {
			return;
		}
		box.className = 'wahou-gateway-card__result' + ( type ? ' is-' + type : '' );
		box.innerHTML = '';
		if ( ! message ) {
			return;
		}
		box.appendChild( el( 'strong', { text: message } ) );
		if ( details.length ) {
			box.appendChild( el( 'ul', {}, details.map( ( line ) => el( 'li', { text: line } ) ) ) );
		}
	}

	function setPill( card, connected, mode ) {
		const pill = $( '[data-gateway-pill]', card );
		if ( ! pill ) {
			return;
		}
		const test = mode === 'test' || mode === 'sandbox';
		pill.className = 'wahou-pill wahou-pill--' + ( connected ? ( test ? 'warning' : 'success' ) : 'muted' );
		pill.textContent = connected ? ( test ? t( 'connectedTest' ) : t( 'connected' ) ) : t( 'notConnected' );
	}

	function initGateways() {
		$$( '.wahou-gateway-card' ).forEach( ( card ) => {
			card.addEventListener( 'change', ( event ) => {
				if ( ! event.target.closest( '[data-gateway-mode]' ) ) {
					return;
				}
				const mode = event.target.value;
				card.dataset.mode = mode;
				$$( '[data-mode-panel]', card ).forEach( ( panel ) => {
					panel.hidden = panel.dataset.modePanel !== mode;
				} );
			} );
		} );

		document.addEventListener( 'click', async ( event ) => {
			const connect = event.target.closest( '[data-gateway-connect]' );
			const disconnect = event.target.closest( '[data-gateway-disconnect]' );
			const button = connect || disconnect;
			if ( ! button ) {
				return;
			}
			const card = button.closest( '.wahou-gateway-card' );
			const gateway = connect ? button.dataset.gatewayConnect : button.dataset.gatewayDisconnect;
			const mode = card.dataset.mode || '';
			const panel = $( '[data-mode-panel="' + mode + '"]', card );
			const result = $( '[data-gateway-result]', card );
			const publicInput = panel && $( '[data-key="public"]', panel );
			const secretInput = panel && $( '[data-key="secret"]', panel );
			const webhookInput = panel && $( '[data-key="webhook"]', panel );
			const publicKey = publicInput ? publicInput.value.trim() : '';
			const secret = secretInput ? secretInput.value.trim() : '';

			if ( disconnect && ! window.confirm( t( 'confirmDisconnect' ) ) ) {
				return;
			}
			if ( connect && ( ! publicKey || ( ! secret && ( ! secretInput || secretInput.dataset.hasValue !== '1' ) ) ) ) {
				showResult( result, 'error', t( 'enterKeys' ) );
				return;
			}

			const params = new URLSearchParams( {
				action: connect ? 'wahou_gateway_connect' : 'wahou_gateway_disconnect',
				_ajax_nonce: nonces.connect || '',
				nonce: nonces.connect || '',
				gateway,
				mode,
			} );
			if ( connect ) {
				params.append( 'public_key', publicKey );
				params.append( 'secret', secret );
				if ( webhookInput && webhookInput.value.trim() ) {
					params.append( 'webhook_secret', webhookInput.value.trim() );
				}
			}

			const label = button.textContent;
			button.classList.add( 'is-busy' );
			button.setAttribute( 'aria-busy', 'true' );
			if ( connect ) {
				button.textContent = t( 'connecting' );
			}
			showResult( result, '', '' );

			try {
				const response = await fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: params } );
				const json = await response.json().catch( () => null );
				if ( ! json || ! json.success ) {
					throw new Error( messageFrom( json ) );
				}
				const payload = json.data && typeof json.data === 'object' ? json.data : {};
				if ( connect ) {
					const details = [];
					if ( payload.account_name ) {
						details.push( t( 'account' ) + ' : ' + payload.account_name );
					}
					if ( payload.webhook ) {
						details.push( t( 'webhook' ) + ' : ' + describe( payload.webhook ) );
					}
					if ( payload.apple_pay ) {
						details.push( t( 'applePay' ) + ' : ' + describe( payload.apple_pay ) );
					}
					showResult( result, 'success', payload.message || t( 'connected' ), details );
					if ( secretInput && secret ) {
						const prefix = secret.indexOf( '_', 3 ) > 0 ? secret.slice( 0, secret.indexOf( '_', 3 ) + 1 ) : '';
						secretInput.value = '';
						secretInput.placeholder = prefix + '••••' + secret.slice( -4 );
						secretInput.dataset.hasValue = '1';
					}
					const hookId = payload.webhook_id || ( payload.webhook && payload.webhook.id );
					const hookField = panel && $( '[data-webhook]', panel );
					if ( hookField && hookId ) {
						hookField.value = hookId;
					}
					setPill( card, true, mode );
					const enable = $( 'input[name="wahou[' + gateway + '_enabled]"]', card );
					if ( enable && ! enable.checked ) {
						enable.checked = true;
						markDirty();
					}
				} else {
					showResult( result, 'success', payload.message || t( 'disconnected' ) );
					if ( secretInput ) {
						secretInput.placeholder = '';
						secretInput.dataset.hasValue = '0';
					}
					setPill( card, false, mode );
				}
			} catch ( error ) {
				showResult( result, 'error', error.message || t( 'genericError' ) );
			} finally {
				button.classList.remove( 'is-busy' );
				button.removeAttribute( 'aria-busy' );
				button.textContent = label;
			}
		} );

		// Display order of payment methods.
		document.addEventListener( 'click', ( event ) => {
			const move = event.target.closest( '[data-move]' );
			if ( ! move ) {
				return;
			}
			const item = move.closest( '.wahou-sortable__item' );
			if ( ! item ) {
				return;
			}
			if ( move.dataset.move === 'up' && item.previousElementSibling ) {
				item.parentNode.insertBefore( item, item.previousElementSibling );
			} else if ( move.dataset.move === 'down' && item.nextElementSibling ) {
				item.parentNode.insertBefore( item.nextElementSibling, item );
			}
			move.focus();
			markDirty();
		} );
	}

	/* ------------------------------------------------------------------
	 * Design: color twins, range output, live preview.
	 * ------------------------------------------------------------------ */

	function textOn( hex ) {
		const value = hex.replace( '#', '' );
		if ( value.length !== 6 ) {
			return '#ffffff';
		}
		const channel = ( index ) => {
			const c = parseInt( value.substr( index, 2 ), 16 ) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		};
		const luminance = 0.2126 * channel( 0 ) + 0.7152 * channel( 2 ) + 0.0722 * channel( 4 );
		return luminance > 0.179 ? '#111111' : '#ffffff';
	}

	function initDesign() {
		const preview = $( '[data-design-preview]' );
		const update = () => {
			if ( ! preview ) {
				return;
			}
			const hex = $( '[data-color-hex]' );
			const range = $( '[data-range]' );
			const style = $( 'input[name="wahou[button_style]"]:checked' );
			if ( hex && /^#[0-9a-f]{6}$/i.test( hex.value ) ) {
				preview.style.setProperty( '--p-brand', hex.value );
				preview.style.setProperty( '--p-on-brand', textOn( hex.value ) );
			}
			if ( range ) {
				preview.style.setProperty( '--p-radius', range.value + 'px' );
			}
			if ( style ) {
				preview.dataset.style = style.value;
			}
		};

		$$( '.wahou-color' ).forEach( ( wrap ) => {
			const swatch = $( '[data-color-swatch]', wrap );
			const hex = $( '[data-color-hex]', wrap );
			swatch.addEventListener( 'input', () => {
				hex.value = swatch.value;
				markDirty();
				update();
			} );
			hex.addEventListener( 'input', () => {
				if ( /^#[0-9a-f]{6}$/i.test( hex.value ) ) {
					swatch.value = hex.value;
				}
				update();
			} );
		} );
		$$( '[data-range]' ).forEach( ( range ) => {
			const output = range.parentNode.querySelector( 'output' );
			range.addEventListener( 'input', () => {
				if ( output ) {
					output.textContent = range.value + ' px';
				}
				update();
			} );
		} );
		document.addEventListener( 'change', ( event ) => {
			if ( event.target.name === 'wahou[button_style]' ) {
				update();
			}
		} );
		update();
	}

	/* ------------------------------------------------------------------
	 * Misc: media field, copy buttons, logs, VAT overrides.
	 * ------------------------------------------------------------------ */

	function initMisc() {
		document.addEventListener( 'click', async ( event ) => {
			const choose = event.target.closest( '[data-image-choose]' );
			const remove = event.target.closest( '[data-image-remove]' );
			if ( choose || remove ) {
				const field = ( choose || remove ).closest( '[data-wahou-image]' );
				const input = $( '[data-image-input]', field );
				const preview = $( '[data-image-preview]', field );
				const removeButton = $( '[data-image-remove]', field );
				if ( remove ) {
					input.value = '0';
					preview.innerHTML = '';
					removeButton.hidden = true;
					markDirty();
					return;
				}
				if ( ! window.wp || ! window.wp.media ) {
					return;
				}
				const frame = window.wp.media( { title: t( 'chooseImage' ), button: { text: t( 'useImage' ) }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', () => {
					const attachment = frame.state().get( 'selection' ).first().toJSON();
					input.value = attachment.id;
					preview.innerHTML = '';
					const src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
					preview.appendChild( el( 'img', { src, alt: '' } ) );
					removeButton.hidden = false;
					markDirty();
				} );
				frame.open();
				return;
			}

			const copy = event.target.closest( '[data-copy]' );
			if ( copy ) {
				try {
					await navigator.clipboard.writeText( copy.dataset.copy );
				} catch ( e ) {
					const area = el( 'textarea', {}, copy.dataset.copy );
					document.body.appendChild( area );
					area.select();
					document.execCommand( 'copy' );
					area.remove();
				}
				const original = copy.textContent;
				copy.textContent = t( 'copied' );
				window.setTimeout( () => {
					copy.textContent = original;
				}, 1500 );
			}
		} );

		const logs = $( '[data-log-select]' );
		if ( logs ) {
			logs.addEventListener( 'change', () => {
				window.location.href = logs.value;
			} );
		}

		$$( '.wahou-input--rate' ).forEach( ( input ) => {
			input.addEventListener( 'input', () => {
				const value = input.value.trim().replace( ',', '.' );
				const fallback = ( input.dataset.rateDefault || '' ).replace( ',', '.' );
				input.classList.toggle( 'is-overridden', value !== '' && parseFloat( value ) !== parseFloat( fallback ) );
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Boot.
	 * ------------------------------------------------------------------ */

	function init() {
		$$( '[data-wahou-countries]' ).forEach( enhanceCountries );
		$$( '[data-wahou-zones]' ).forEach( initZones );
		initRepeaters();
		initGateways();
		initDesign();
		initMisc();
		applyShowIf();
		document.addEventListener( 'change', applyShowIf );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
