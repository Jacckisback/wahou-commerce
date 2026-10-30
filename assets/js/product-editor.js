/**
 * Wahou Commerce – product, coupon and offer editors.
 *
 * Vanilla JS, no build step. Every structured value is mirrored into hidden
 * inputs as soon as it changes, because the block editor serializes meta
 * boxes with FormData without firing a submit event.
 */
( function () {
	'use strict';

	const data = window.wahouAdmin || {};
	const i18n = data.i18n || {};
	const nonces = data.nonces || {};

	const t = ( key, fallback ) => i18n[ key ] || fallback || key;
	const $ = ( selector, context = document ) => context.querySelector( selector );
	const $$ = ( selector, context = document ) => Array.from( context.querySelectorAll( selector ) );
	let sequence = Date.now();

	function uid( prefix, length = 8 ) {
		let out = '';
		while ( out.length < length ) {
			out += Math.random().toString( 36 ).slice( 2 );
		}
		return prefix + out.slice( 0, length );
	}

	/**
	 * Create an element. attrs: class, text, html (static markup only), on<event>.
	 */
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
			} else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ), value );
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

	async function ajax( action, params = {} ) {
		const query = new URLSearchParams( Object.assign( { action }, params ) );
		const response = await fetch( data.ajaxUrl + '?' + query.toString(), { credentials: 'same-origin' } );
		const json = await response.json().catch( () => null );
		if ( ! json || ! json.success ) {
			throw new Error( ( json && json.data && json.data.message ) || t( 'genericError' ) );
		}
		return json.data;
	}

	function pickImage( done, multiple = false ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: multiple ? t( 'chooseImages' ) : t( 'chooseImage' ),
			button: { text: multiple ? t( 'addToGallery' ) : t( 'useImage' ) },
			library: { type: 'image' },
			multiple: multiple ? 'add' : false,
		} );
		frame.on( 'select', () => {
			const selection = frame.state().get( 'selection' ).toJSON();
			done( multiple ? selection : selection[ 0 ] );
		} );
		frame.open();
	}

	function thumbUrl( attachment ) {
		if ( ! attachment ) {
			return '';
		}
		return attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
	}

	/* ------------------------------------------------------------------
	 * Conditional fields: data-show-if="name", "name=value" or "name!=value".
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

	function syncChoices( scope = document ) {
		$$( '.wahou-choice', scope ).forEach( ( choice ) => {
			const input = $( 'input', choice );
			choice.classList.toggle( 'is-checked', !! ( input && input.checked ) );
		} );
	}

	/* ------------------------------------------------------------------
	 * Product data box: tabs and contextual visibility.
	 * ------------------------------------------------------------------ */

	function initProductData( box ) {
		box.classList.add( 'wahou-js' );
		const tabs = () => $$( '.wahou-vtabs__tab', box );
		const visible = ( node ) => node && window.getComputedStyle( node ).display !== 'none';

		const activate = ( key, focus = false ) => {
			tabs().forEach( ( tab ) => {
				const on = tab.dataset.tab === key;
				tab.classList.toggle( 'is-active', on );
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tab.tabIndex = on ? 0 : -1;
				if ( on && focus ) {
					tab.focus();
				}
			} );
			$$( '.wahou-vtabs__panel', box ).forEach( ( panel ) => {
				panel.classList.toggle( 'is-active', panel.dataset.panel === key );
			} );
			try {
				window.sessionStorage.setItem( 'wahouProductTab', key );
			} catch ( e ) {}
		};

		box.addEventListener( 'click', ( event ) => {
			const tab = event.target.closest( '.wahou-vtabs__tab' );
			if ( tab && box.contains( tab ) ) {
				activate( tab.dataset.tab );
			}
		} );

		const tablist = $( '.wahou-vtabs', box );
		if ( tablist ) {
			tablist.addEventListener( 'keydown', ( event ) => {
				const keys = [ 'ArrowDown', 'ArrowRight', 'ArrowUp', 'ArrowLeft', 'Home', 'End' ];
				if ( ! keys.includes( event.key ) ) {
					return;
				}
				event.preventDefault();
				const list = tabs().filter( visible );
				const index = list.indexOf( document.activeElement );
				let next = index;
				if ( event.key === 'ArrowDown' || event.key === 'ArrowRight' ) {
					next = ( index + 1 ) % list.length;
				} else if ( event.key === 'ArrowUp' || event.key === 'ArrowLeft' ) {
					next = ( index - 1 + list.length ) % list.length;
				} else if ( event.key === 'Home' ) {
					next = 0;
				} else {
					next = list.length - 1;
				}
				activate( list[ next ].dataset.tab, true );
			} );
		}

		const sync = () => {
			const kind = $( 'input[name="wahou_p[kind]"]:checked', box );
			const type = $( 'select[name="wahou_p[type]"]', box );
			box.dataset.kind = kind ? kind.value : 'physical';
			box.dataset.type = type ? type.value : 'simple';
			syncChoices( box );
			const active = $( '.wahou-vtabs__tab.is-active', box );
			if ( active && ! visible( active ) ) {
				activate( 'general' );
			}
		};

		box.addEventListener( 'change', ( event ) => {
			if ( event.target.name === 'wahou_p[kind]' || event.target.name === 'wahou_p[type]' ) {
				sync();
				if ( event.target.name === 'wahou_p[type]' && event.target.value !== 'simple' ) {
					const target = event.target.value === 'variable' ? 'variants' : 'bundle';
					activate( target );
				}
			}
		} );

		sync();
		let saved = null;
		try {
			saved = window.sessionStorage.getItem( 'wahouProductTab' );
		} catch ( e ) {}
		const savedTab = saved ? $( '.wahou-vtabs__tab[data-tab="' + saved + '"]', box ) : null;
		if ( savedTab && visible( savedTab ) ) {
			activate( saved );
		}
	}

	/* ------------------------------------------------------------------
	 * Product picker (AJAX search + chips).
	 * ------------------------------------------------------------------ */

	const searchCache = new Map();

	function searchProducts( query, exclude ) {
		const key = query + '|' + exclude;
		if ( ! searchCache.has( key ) ) {
			const promise = ajax( 'wahou_search_products', { nonce: nonces.search || '', q: query, exclude } );
			promise.catch( () => searchCache.delete( key ) );
			searchCache.set( key, promise );
		}
		return searchCache.get( key );
	}

	function selectedIds( picker ) {
		return $$( '.wahou-chip', picker ).map( ( chip ) => chip.dataset.id );
	}

	function closeResults( picker ) {
		const list = $( '.wahou-picker__results', picker );
		const input = $( '.wahou-picker__input', picker );
		if ( list ) {
			list.hidden = true;
		}
		if ( input ) {
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}
	}

	function makeChip( name, multiple, item ) {
		return el( 'li', { class: 'wahou-chip', 'data-id': item.id }, [
			el( 'span', { class: 'wahou-chip__label', text: item.text } ),
			el( 'button', { type: 'button', class: 'wahou-chip__remove', 'aria-label': t( 'remove' ) + ' ' + item.text, html: '&times;' } ),
			el( 'input', { type: 'hidden', name: multiple ? name + '[]' : name, value: item.id } ),
		] );
	}

	function selectItem( picker, item ) {
		const multiple = picker.dataset.multiple === '1';
		const chips = $( '[data-chips]', picker );
		const input = $( '.wahou-picker__input', picker );
		if ( ! multiple ) {
			chips.innerHTML = '';
		}
		if ( ! selectedIds( picker ).includes( String( item.id ) ) ) {
			chips.appendChild( makeChip( picker.dataset.name, multiple, item ) );
		}
		input.value = '';
		closeResults( picker );
		if ( multiple ) {
			input.focus();
		} else {
			$( '[data-search]', picker ).hidden = true;
		}
		picker.dispatchEvent( new CustomEvent( 'wahou:picker-change', { bubbles: true, detail: { item } } ) );
	}

	function setActive( picker, index ) {
		const options = $$( '.wahou-picker__option', picker );
		const input = $( '.wahou-picker__input', picker );
		if ( ! options.length ) {
			return;
		}
		picker.wahouActive = ( index + options.length ) % options.length;
		options.forEach( ( option, i ) => {
			const on = i === picker.wahouActive;
			option.classList.toggle( 'is-active', on );
			option.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			if ( on ) {
				input.setAttribute( 'aria-activedescendant', option.id );
				option.scrollIntoView( { block: 'nearest' } );
			}
		} );
	}

	function renderResults( picker, items ) {
		const list = $( '.wahou-picker__results', picker );
		const input = $( '.wahou-picker__input', picker );
		list.innerHTML = '';
		picker.wahouItems = items;
		picker.wahouActive = -1;
		if ( ! items.length ) {
			list.appendChild( el( 'li', { class: 'wahou-picker__empty', text: t( 'noResults' ) } ) );
		}
		items.forEach( ( item, index ) => {
			const option = el(
				'li',
				{ class: 'wahou-picker__option', role: 'option', id: list.id + '-' + item.id, 'aria-selected': 'false', 'data-index': index },
				[
					item.thumb ? el( 'img', { src: item.thumb, alt: '' } ) : el( 'span', { class: 'wahou-picker__noimg' } ),
					el( 'span', { class: 'wahou-picker__option-text' }, [
						el( 'span', { class: 'wahou-picker__option-name', text: item.text } ),
						el( 'span', { class: 'wahou-picker__option-meta', text: item.price || '' } ),
					] ),
				]
			);
			option.addEventListener( 'mousedown', ( event ) => {
				event.preventDefault();
				selectItem( picker, item );
			} );
			list.appendChild( option );
		} );
		list.hidden = false;
		input.setAttribute( 'aria-expanded', 'true' );
	}

	async function runSearch( picker ) {
		const input = $( '.wahou-picker__input', picker );
		const list = $( '.wahou-picker__results', picker );
		if ( ! input || ! list ) {
			return;
		}
		const exclude = ( picker.dataset.exclude ? picker.dataset.exclude.split( ',' ) : [] )
			.concat( selectedIds( picker ) )
			.filter( Boolean )
			.join( ',' );
		const token = ( picker.wahouToken = ( picker.wahouToken || 0 ) + 1 );
		list.innerHTML = '';
		list.appendChild( el( 'li', { class: 'wahou-picker__empty', text: t( 'loading' ) } ) );
		list.hidden = false;
		try {
			const items = await searchProducts( input.value.trim(), exclude );
			if ( token !== picker.wahouToken ) {
				return;
			}
			if ( document.activeElement !== input ) {
				closeResults( picker );
				return;
			}
			renderResults( picker, items );
		} catch ( error ) {
			list.innerHTML = '';
			list.appendChild( el( 'li', { class: 'wahou-picker__empty', text: error.message } ) );
		}
	}

	function initPickers() {
		document.addEventListener( 'input', ( event ) => {
			if ( ! event.target.matches || ! event.target.matches( '.wahou-picker__input' ) ) {
				return;
			}
			const picker = event.target.closest( '[data-wahou-picker]' );
			window.clearTimeout( picker.wahouTimer );
			picker.wahouTimer = window.setTimeout( () => runSearch( picker ), 220 );
		} );
		document.addEventListener( 'focusin', ( event ) => {
			if ( event.target.matches && event.target.matches( '.wahou-picker__input' ) ) {
				runSearch( event.target.closest( '[data-wahou-picker]' ) );
			}
		} );
		document.addEventListener( 'focusout', ( event ) => {
			if ( event.target.matches && event.target.matches( '.wahou-picker__input' ) ) {
				const picker = event.target.closest( '[data-wahou-picker]' );
				window.setTimeout( () => closeResults( picker ), 150 );
			}
		} );
		document.addEventListener( 'keydown', ( event ) => {
			if ( ! event.target.matches || ! event.target.matches( '.wahou-picker__input' ) ) {
				return;
			}
			const picker = event.target.closest( '[data-wahou-picker]' );
			const items = picker.wahouItems || [];
			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				setActive( picker, ( picker.wahouActive ?? -1 ) + 1 );
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				setActive( picker, ( picker.wahouActive ?? 0 ) - 1 );
			} else if ( event.key === 'Enter' ) {
				// Never submit the post form from the search field.
				event.preventDefault();
				const item = items[ picker.wahouActive ?? -1 ] || ( items.length === 1 ? items[ 0 ] : null );
				if ( item ) {
					selectItem( picker, item );
				}
			} else if ( event.key === 'Escape' ) {
				closeResults( picker );
			}
		} );
		document.addEventListener( 'click', ( event ) => {
			const remove = event.target.closest( '.wahou-chip__remove' );
			if ( ! remove ) {
				return;
			}
			const picker = remove.closest( '[data-wahou-picker]' );
			if ( ! picker ) {
				return;
			}
			remove.closest( '.wahou-chip' ).remove();
			if ( picker.dataset.multiple !== '1' ) {
				$( '[data-search]', picker ).hidden = false;
			}
			picker.dispatchEvent( new CustomEvent( 'wahou:picker-change', { bubbles: true, detail: { item: null } } ) );
		} );
	}

	/* ------------------------------------------------------------------
	 * Generic repeaters (bundle items, FAQ…).
	 * ------------------------------------------------------------------ */

	function initRepeaters() {
		document.addEventListener( 'click', ( event ) => {
			const add = event.target.closest( '[data-add-row]' );
			if ( add ) {
				const repeater = add.closest( '[data-wahou-repeater]' );
				const template = repeater && $( 'template[data-template]', repeater );
				const rows = repeater && $( '[data-rows]', repeater );
				if ( ! template || ! rows ) {
					return;
				}
				const max = parseInt( repeater.dataset.max || '0', 10 );
				if ( max && rows.children.length >= max ) {
					return;
				}
				rows.insertAdjacentHTML( 'beforeend', template.innerHTML.replace( /__i__/g, String( ++sequence ) ) );
				const focusable = $( 'input:not([type="hidden"]), textarea, select', rows.lastElementChild );
				if ( focusable ) {
					focusable.focus();
				}
				return;
			}
			const remove = event.target.closest( '[data-remove-row]' );
			if ( remove ) {
				const row = remove.closest( '[data-row]' );
				if ( row ) {
					row.remove();
				}
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Variants.
	 * ------------------------------------------------------------------ */

	function initVariants( root ) {
		const input = $( '[data-variations-input]', root );
		const attributesBox = $( '[data-attributes]', root );
		const list = $( '[data-variants]', root );
		const bulk = $( '[data-variants-bulk]', root );
		let state;
		try {
			state = JSON.parse( input.value || '{}' ) || {};
		} catch ( e ) {
			state = {};
		}
		state.attributes = Array.isArray( state.attributes ) ? state.attributes : [];
		state.variants = Array.isArray( state.variants ) ? state.variants : [];

		const save = () => {
			input.value = JSON.stringify( {
				attributes: state.attributes.filter( ( attribute ) => attribute.name && attribute.values && attribute.values.length ),
				variants: state.variants,
			} );
		};
		// Empty variant prices inherit the product price: show it as placeholder.
		const basePrice = () => {
			const field = document.querySelector( 'input[name="wahou_p[price]"]' );
			return ( field && field.value.trim() ) || root.dataset.basePrice || '';
		};
		const parseValues = ( text ) => Array.from( new Set( text.split( ',' ).map( ( value ) => value.trim() ).filter( Boolean ) ) );
		const keyOf = ( attributes ) => JSON.stringify( Object.keys( attributes || {} ).sort().map( ( key ) => [ key, attributes[ key ] ] ) );

		function combinations() {
			const attributes = state.attributes.filter( ( attribute ) => attribute.name && attribute.values && attribute.values.length );
			if ( ! attributes.length ) {
				return [];
			}
			let result = [ {} ];
			for ( const attribute of attributes ) {
				const next = [];
				for ( const partial of result ) {
					for ( const value of attribute.values ) {
						next.push( Object.assign( {}, partial, { [ attribute.name ]: value } ) );
					}
				}
				result = next;
				if ( result.length > 200 ) {
					return null;
				}
			}
			return result;
		}

		function generate() {
			const combos = combinations();
			if ( combos === null ) {
				window.alert( t( 'tooManyVariants' ) );
				return;
			}
			const existing = new Map( state.variants.map( ( variant ) => [ keyOf( variant.attributes ), variant ] ) );
			state.variants = combos.map( ( attributes ) => existing.get( keyOf( attributes ) ) || {
				id: uid( 'v' ),
				attributes,
				price: '',
				sale_price: '',
				sku: '',
				stock: '',
				image_id: 0,
				image: '',
			} );
			renderVariants();
			save();
		}

		function renderAttributes() {
			attributesBox.innerHTML = '';
			state.attributes.forEach( ( attribute, index ) => {
				const nameId = uid( 'wahou-attr-name-' );
				const valuesId = uid( 'wahou-attr-values-' );
				const name = el( 'input', { type: 'text', class: 'wahou-input', id: nameId, value: attribute.name || '', placeholder: t( 'attributeNamePh' ), maxlength: 60 } );
				const values = el( 'input', { type: 'text', class: 'wahou-input', id: valuesId, value: ( attribute.values || [] ).join( ', ' ), placeholder: t( 'attributeValuesPh' ) } );
				name.addEventListener( 'input', () => {
					attribute.name = name.value.trim();
					save();
				} );
				values.addEventListener( 'input', () => {
					attribute.values = parseValues( values.value );
					save();
				} );
				// Keep the variant list in sync when an option is edited.
				[ name, values ].forEach( ( field ) => field.addEventListener( 'change', generate ) );
				const remove = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--ghost wahou-btn--icon', 'aria-label': t( 'remove' ) }, icon( 'dashicons-trash' ) );
				remove.addEventListener( 'click', () => {
					state.attributes.splice( index, 1 );
					renderAttributes();
					generate();
				} );
				attributesBox.appendChild(
					el( 'div', { class: 'wahou-attribute' }, [
						el( 'div', { class: 'wahou-field' }, [ el( 'label', { class: 'wahou-field__label', for: nameId, text: t( 'attributeName' ) } ), name ] ),
						el( 'div', { class: 'wahou-field' }, [ el( 'label', { class: 'wahou-field__label', for: valuesId, text: t( 'attributeValues' ) } ), values ] ),
						remove,
					] )
				);
			} );
		}

		function moneyInput( variant, key, label, placeholder ) {
			const field = el( 'input', {
				type: 'text',
				inputmode: 'decimal',
				class: 'wahou-input wahou-input--money',
				value: variant[ key ] || '',
				placeholder,
				'aria-label': label,
				autocomplete: 'off',
			} );
			field.addEventListener( 'input', () => {
				variant[ key ] = field.value.trim();
				save();
			} );
			return field;
		}

		function textInput( variant, key, label, placeholder, numeric ) {
			const field = el( 'input', {
				type: numeric ? 'number' : 'text',
				class: 'wahou-input' + ( numeric ? ' wahou-input--number' : '' ),
				value: variant[ key ] || '',
				placeholder,
				'aria-label': label,
				step: numeric ? '1' : null,
			} );
			field.addEventListener( 'input', () => {
				variant[ key ] = field.value.trim();
				save();
			} );
			return field;
		}

		function renderVariants() {
			list.innerHTML = '';
			if ( bulk ) {
				bulk.hidden = state.variants.length < 2;
			}
			if ( ! state.variants.length ) {
				list.appendChild( el( 'p', { class: 'wahou-empty', text: t( 'noVariants' ) } ) );
				return;
			}
			const head = el( 'tr', {}, [ t( 'variant' ), t( 'price' ), t( 'salePrice' ), t( 'sku' ), t( 'stock' ), t( 'image' ), '' ].map( ( text ) => el( 'th', { scope: 'col', text } ) ) );
			const body = el( 'tbody' );
			state.variants.forEach( ( variant, index ) => {
				const label = Object.values( variant.attributes || {} ).join( ' / ' );
				const imageButton = el(
					'button',
					{ type: 'button', class: 'wahou-variant__image', 'aria-label': t( 'chooseImage' ) + ' – ' + label },
					variant.image ? el( 'img', { src: variant.image, alt: '' } ) : icon( 'dashicons-format-image' )
				);
				imageButton.addEventListener( 'click', () => pickImage( ( attachment ) => {
					variant.image_id = attachment ? attachment.id : 0;
					variant.image = thumbUrl( attachment );
					renderVariants();
					save();
				} ) );
				const imageCell = el( 'td', {}, imageButton );
				if ( variant.image_id ) {
					const clear = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--ghost wahou-btn--small', 'aria-label': t( 'removeImage' ), html: '&times;' } );
					clear.addEventListener( 'click', () => {
						variant.image_id = 0;
						variant.image = '';
						renderVariants();
						save();
					} );
					imageCell.appendChild( clear );
				}
				const remove = el( 'button', { type: 'button', class: 'wahou-btn wahou-btn--ghost wahou-btn--icon', 'aria-label': t( 'remove' ) + ' – ' + label }, icon( 'dashicons-trash' ) );
				remove.addEventListener( 'click', () => {
					state.variants.splice( index, 1 );
					renderVariants();
					save();
				} );
				body.appendChild( el( 'tr', {}, [
					el( 'td', {}, el( 'span', { class: 'wahou-variant__label', text: label } ) ),
					el( 'td', {}, moneyInput( variant, 'price', label + ' – ' + t( 'price' ), basePrice() ) ),
					el( 'td', {}, moneyInput( variant, 'sale_price', label + ' – ' + t( 'salePrice' ), '' ) ),
					el( 'td', {}, textInput( variant, 'sku', label + ' – ' + t( 'sku' ), '', false ) ),
					el( 'td', {}, textInput( variant, 'stock', label + ' – ' + t( 'stock' ) + ' (' + t( 'notTracked' ) + ')', '—', true ) ),
					imageCell,
					el( 'td', {}, remove ),
				] ) );
			} );
			list.appendChild( el( 'p', { class: 'wahou-variants__count', text: t( 'variantsCount' ).replace( '%d', String( state.variants.length ) ) } ) );
			list.appendChild( el( 'div', { class: 'wahou-table-wrap' }, el( 'table', { class: 'wahou-table' }, [ el( 'thead', {}, head ), body ] ) ) );
		}

		const addAttribute = $( '[data-add-attribute]', root );
		if ( addAttribute ) {
			addAttribute.addEventListener( 'click', () => {
				if ( state.attributes.length >= 5 ) {
					return;
				}
				state.attributes.push( { name: '', values: [] } );
				renderAttributes();
				const inputs = $$( '.wahou-attribute input', attributesBox );
				if ( inputs.length >= 2 ) {
					inputs[ inputs.length - 2 ].focus();
				}
			} );
		}
		const generateButton = $( '[data-generate-variants]', root );
		if ( generateButton ) {
			generateButton.addEventListener( 'click', () => {
				if ( state.variants.length && ! window.confirm( t( 'confirmGenerate' ) ) ) {
					return;
				}
				generate();
			} );
		}
		const bulkApply = $( '[data-bulk-apply]', root );
		if ( bulkApply ) {
			bulkApply.addEventListener( 'click', () => {
				const value = ( $( '[data-bulk-price]', root ) || {} ).value || '';
				state.variants.forEach( ( variant ) => {
					variant.price = value.trim();
				} );
				renderVariants();
				save();
			} );
		}

		renderAttributes();
		renderVariants();
	}

	/* ------------------------------------------------------------------
	 * Downloadable files.
	 * ------------------------------------------------------------------ */

	function initFiles( root ) {
		const repeater = $( '[data-wahou-repeater]', root );
		const rows = $( '[data-rows]', repeater );
		const fileInput = $( '[data-file-input]', root );
		const progress = $( '[data-upload-progress]', root );

		const addRow = ( type, values ) => {
			const template = $( 'template[data-template="' + type + '"]', repeater );
			if ( ! template ) {
				return null;
			}
			const html = template.innerHTML.replace( /__i__/g, String( ++sequence ) ).replace( /__path__|__size__/g, '' );
			rows.insertAdjacentHTML( 'beforeend', html );
			const row = rows.lastElementChild;
			const set = ( selector, prop, value ) => {
				const node = $( selector, row );
				if ( node && value !== undefined ) {
					node[ prop ] = value;
				}
			};
			set( '[data-file-id]', 'value', values.id || '' );
			set( '[data-file-name]', 'value', values.name || '' );
			if ( type === 'file' ) {
				set( '[data-file-path]', 'value', values.path || '' );
				set( '[data-file-label]', 'textContent', values.file || '' );
				set( '[data-file-size]', 'textContent', values.size || '' );
			}
			return row;
		};

		const setProgress = ( percent ) => {
			$( '.wahou-progress__bar', progress ).style.width = percent + '%';
			$( '.wahou-progress__label', progress ).textContent = t( 'uploading' ).replace( '%d', String( percent ) );
		};

		const upload = ( file ) => {
			const form = new FormData();
			form.append( 'action', 'wahou_upload_file' );
			form.append( 'nonce', nonces.upload || '' );
			form.append( 'post_id', root.dataset.post || '0' );
			form.append( 'file', file );

			const request = new XMLHttpRequest();
			progress.hidden = false;
			setProgress( 0 );
			request.upload.addEventListener( 'progress', ( event ) => {
				if ( event.lengthComputable ) {
					setProgress( Math.round( ( event.loaded / event.total ) * 100 ) );
				}
			} );
			request.addEventListener( 'load', () => {
				let json = null;
				try {
					json = JSON.parse( request.responseText );
				} catch ( e ) {}
				progress.hidden = true;
				fileInput.value = '';
				if ( json && json.success ) {
					const row = addRow( 'file', json.data );
					const name = row && $( '[data-file-name]', row );
					if ( name ) {
						name.focus();
						name.select();
					}
				} else {
					window.alert( ( json && json.data && json.data.message ) || t( 'uploadFailed' ) );
				}
			} );
			request.addEventListener( 'error', () => {
				progress.hidden = true;
				fileInput.value = '';
				window.alert( t( 'uploadFailed' ) );
			} );
			request.open( 'POST', data.ajaxUrl );
			request.send( form );
		};

		if ( fileInput ) {
			fileInput.addEventListener( 'change', () => {
				const file = fileInput.files && fileInput.files[ 0 ];
				if ( ! file ) {
					return;
				}
				if ( data.maxUpload && file.size > data.maxUpload ) {
					window.alert( t( 'fileTooBig' ).replace( '%s', data.maxUploadHuman || '' ) );
					fileInput.value = '';
					return;
				}
				upload( file );
			} );
		}
		const addUrl = $( '[data-add-url]', root );
		if ( addUrl ) {
			addUrl.addEventListener( 'click', () => {
				const row = addRow( 'url', {} );
				const url = row && $( '[data-file-url]', row );
				if ( url ) {
					url.focus();
				}
			} );
		}
	}

	/* ------------------------------------------------------------------
	 * Gallery.
	 * ------------------------------------------------------------------ */

	function initGallery( root ) {
		const input = $( '[data-gallery-input]', root );
		const list = $( '[data-gallery-list]', root );
		const sync = () => {
			input.value = $$( '.wahou-gallery__item', list ).map( ( item ) => item.dataset.id ).join( ',' );
		};
		const item = ( attachment ) => el( 'li', { class: 'wahou-gallery__item', 'data-id': attachment.id, draggable: 'true' }, [
			el( 'img', { src: thumbUrl( attachment ), alt: '' } ),
			el( 'button', { type: 'button', class: 'wahou-gallery__remove', 'aria-label': t( 'removeImage' ), html: '&times;' } ),
		] );

		const add = $( '[data-gallery-add]', root );
		if ( add ) {
			add.addEventListener( 'click', () => pickImage( ( attachments ) => {
				( attachments || [] ).forEach( ( attachment ) => {
					if ( ! $( '[data-id="' + attachment.id + '"]', list ) ) {
						list.appendChild( item( attachment ) );
					}
				} );
				sync();
			}, true ) );
		}
		list.addEventListener( 'click', ( event ) => {
			const remove = event.target.closest( '.wahou-gallery__remove' );
			if ( remove ) {
				remove.closest( '.wahou-gallery__item' ).remove();
				sync();
			}
		} );

		let dragged = null;
		list.addEventListener( 'dragstart', ( event ) => {
			dragged = event.target.closest( '.wahou-gallery__item' );
			if ( dragged ) {
				dragged.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', dragged.dataset.id );
			}
		} );
		list.addEventListener( 'dragover', ( event ) => {
			event.preventDefault();
			const over = event.target.closest( '.wahou-gallery__item' );
			if ( ! dragged || ! over || over === dragged ) {
				return;
			}
			const rect = over.getBoundingClientRect();
			const after = event.clientX - rect.left > rect.width / 2;
			list.insertBefore( dragged, after ? over.nextSibling : over );
		} );
		list.addEventListener( 'drop', ( event ) => event.preventDefault() );
		list.addEventListener( 'dragend', () => {
			if ( dragged ) {
				dragged.classList.remove( 'is-dragging' );
			}
			dragged = null;
			sync();
		} );
	}

	/* ------------------------------------------------------------------
	 * Coupons & offers.
	 * ------------------------------------------------------------------ */

	function initCoupon( root ) {
		const symbol = root.dataset.symbol || '€';
		const update = () => {
			const type = $( 'input[name="wahou_c[type]"]:checked', root );
			const amount = $( '[data-amount]', root );
			const group = amount && amount.closest( '.wahou-input-group' );
			const suffix = group && $( '[data-suffix]', group );
			if ( suffix ) {
				suffix.textContent = type && type.value === 'fixed' ? symbol : '%';
			}
			syncChoices( root );
		};
		root.addEventListener( 'change', update );
		update();
	}

	function generateCode() {
		const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		const random = new Uint32Array( 8 );
		window.crypto.getRandomValues( random );
		return Array.from( random, ( n ) => alphabet[ n % alphabet.length ] ).join( '' );
	}

	function initOffer( root ) {
		const wrap = $( '[data-offer-variant]', root );
		const select = $( 'select[name="wahou_o[variant]"]', root );
		root.addEventListener( 'change', () => syncChoices( root ) );
		root.addEventListener( 'wahou:picker-change', ( event ) => {
			const picker = event.target.closest( '[data-wahou-picker]' );
			if ( ! picker || picker.dataset.name !== 'wahou_o[product]' || ! select || ! wrap ) {
				return;
			}
			const item = event.detail && event.detail.item;
			const variants = item && Array.isArray( item.variants ) ? item.variants : [];
			const placeholder = select.options[ 0 ];
			select.innerHTML = '';
			select.appendChild( placeholder );
			variants.forEach( ( variant ) => select.appendChild( el( 'option', { value: variant.id, text: variant.label } ) ) );
			select.value = '';
			wrap.hidden = ! variants.length;
		} );
		syncChoices( root );
	}

	/* ------------------------------------------------------------------
	 * SEO counters & preview, copy buttons.
	 * ------------------------------------------------------------------ */

	function initCounters() {
		$$( '[data-count]' ).forEach( ( field ) => {
			const counter = $( '[data-counter-for="' + field.id + '"]' );
			const max = parseInt( field.dataset.count, 10 ) || 0;
			const target = field.dataset.seo === 'title' ? $( '[data-snippet-title]' ) : $( '[data-snippet-desc]' );
			const update = () => {
				const length = field.value.length;
				if ( counter ) {
					counter.textContent = t( 'characters' ).replace( '%1$d', String( length ) ).replace( '%2$d', String( max ) );
					counter.classList.toggle( 'is-over', max > 0 && length > max );
				}
				if ( target ) {
					target.textContent = field.value || target.dataset.fallback || '';
				}
			};
			field.addEventListener( 'input', update );
			update();
		} );
	}

	function initCommonClicks() {
		document.addEventListener( 'click', async ( event ) => {
			const generate = event.target.closest( '[data-generate-code]' );
			if ( generate ) {
				const title = document.getElementById( 'title' );
				if ( title ) {
					title.value = generateCode();
					title.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					const prompt = document.getElementById( 'title-prompt-text' );
					if ( prompt ) {
						prompt.classList.add( 'screen-reader-text' );
					}
					title.focus();
				}
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
	}

	/**
	 * The block editor collapses meta boxes into a bottom pane by default.
	 * Product data is the heart of a product: open the pane unless the
	 * merchant closed it during this session.
	 */
	function openMetaBoxPane() {
		if ( ! document.body.classList.contains( 'block-editor-page' ) || ! document.getElementById( 'wahou-product-data' ) ) {
			return;
		}
		const key = 'wahouMetaPaneClosed';
		document.addEventListener( 'click', ( event ) => {
			const toggle = event.isTrusted && event.target.closest( '.edit-post-meta-boxes-main__presenter button[aria-expanded]' );
			if ( toggle ) {
				window.setTimeout( () => {
					try {
						if ( toggle.getAttribute( 'aria-expanded' ) === 'false' ) {
							window.sessionStorage.setItem( key, '1' );
						} else {
							window.sessionStorage.removeItem( key );
						}
					} catch ( e ) {}
				}, 0 );
			}
		} );
		let closedByUser = false;
		try {
			closedByUser = window.sessionStorage.getItem( key ) === '1';
		} catch ( e ) {}
		if ( closedByUser ) {
			return;
		}
		let tries = 0;
		const timer = window.setInterval( () => {
			const toggle = document.querySelector( '.edit-post-meta-boxes-main__presenter button[aria-expanded]' );
			if ( toggle || ++tries > 60 ) {
				window.clearInterval( timer );
				if ( toggle && toggle.getAttribute( 'aria-expanded' ) === 'false' ) {
					toggle.click();
				}
			}
		}, 250 );
	}

	/* ------------------------------------------------------------------
	 * Boot.
	 * ------------------------------------------------------------------ */

	function init() {
		initPickers();
		initRepeaters();
		initCommonClicks();
		$$( '[data-wahou-pd]' ).forEach( initProductData );
		$$( '[data-wahou-variants]' ).forEach( initVariants );
		$$( '[data-wahou-files]' ).forEach( initFiles );
		$$( '[data-wahou-gallery]' ).forEach( initGallery );
		$$( '[data-wahou-coupon]' ).forEach( initCoupon );
		$$( '[data-wahou-offer]' ).forEach( initOffer );
		initCounters();
		openMetaBoxPane();
		applyShowIf();
		document.addEventListener( 'change', ( event ) => {
			if ( event.target.closest && event.target.closest( '.wahou-choice' ) ) {
				syncChoices( event.target.closest( '.wahou-choices' ) || document );
			}
			applyShowIf();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
