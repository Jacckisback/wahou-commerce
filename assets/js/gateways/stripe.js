/**
 * Wahou Commerce – Stripe gateway.
 *
 * Payment Element in "deferred intent" mode (the PaymentIntent is created
 * server side only when the customer pays, with the exact order amount) and
 * Express Checkout Element (Apple Pay, Google Pay, Link) in #wahou-express.
 *
 * Also exposes window.WahouStripe.handleNextAction( clientSecret ) for the
 * 3-D Secure step of one-click post-purchase offers.
 */
( function () {
	'use strict';

	var GATEWAY_ID = 'stripe';
	var stripeInstance = null;
	var stripeInstanceKey = '';

	function config() {
		var data = window.wahouCheckoutData;
		if ( data && data.gateways && data.gateways[ GATEWAY_ID ] ) {
			return data.gateways[ GATEWAY_ID ];
		}
		return window.wahouStripeConfig || null;
	}

	function strings( cfg ) {
		var base = ( window.wahouStripeConfig && window.wahouStripeConfig.i18n ) || {};
		return Object.assign( {}, base, ( cfg && cfg.i18n ) || {} );
	}

	function getStripe( cfg ) {
		if ( ! cfg || ! cfg.publishableKey || typeof window.Stripe !== 'function' ) {
			return null;
		}
		if ( ! stripeInstance || stripeInstanceKey !== cfg.publishableKey ) {
			stripeInstance = window.Stripe( cfg.publishableKey, cfg.locale ? { locale: cfg.locale } : {} );
			stripeInstanceKey = cfg.publishableKey;
		}
		return stripeInstance;
	}

	// Elements needs a positive amount; the server always charges the real order total.
	function amountFrom( totals, cfg ) {
		var amount = totals && totals.total !== undefined ? totals.total : cfg && cfg.amount;
		return Math.max( 1, parseInt( amount, 10 ) || 0 );
	}

	function showMessage( container, message ) {
		if ( ! container ) {
			return;
		}
		var notice = document.createElement( 'p' );
		notice.className = 'wahou-notice wahou-notice--error';
		notice.setAttribute( 'role', 'alert' );
		notice.textContent = message;
		container.textContent = '';
		container.appendChild( notice );
	}

	function clean( object ) {
		var out = {};
		Object.keys( object ).forEach( function ( key ) {
			var value = object[ key ];
			if ( value !== undefined && value !== null && value !== '' ) {
				out[ key ] = value;
			}
		} );
		return out;
	}

	/**
	 * Billing details from the checkout payload (email and country are
	 * collected by the store, so the Payment Element does not ask again).
	 */
	function billingDetails( data, cfg ) {
		data = data || {};
		var billing = data.billing || {};
		var shipping = data.shipping || {};
		var source = billing.address_1 ? billing : shipping;
		var first = billing.first_name || shipping.first_name || '';
		var last = billing.last_name || shipping.last_name || '';
		var address = clean( {
			line1: source.address_1,
			line2: source.address_2,
			postal_code: source.postcode,
			city: source.city,
			state: source.state,
		} );
		address.country = billing.country || shipping.country || ( cfg && cfg.country ) || '';
		return clean( {
			name: ( first + ' ' + last ).trim(),
			email: data.email,
			phone: billing.phone || shipping.phone,
			address: address,
		} );
	}

	function splitName( name ) {
		var parts = String( name || '' ).trim().split( /\s+/ ).filter( Boolean );
		if ( parts.length < 2 ) {
			return { first: parts[ 0 ] || '', last: parts[ 0 ] || '' };
		}
		var last = parts.pop();
		return { first: parts.join( ' ' ), last: last };
	}

	function toAddress( name, address, phone ) {
		var split = splitName( name );
		address = address || {};
		return {
			first_name: split.first,
			last_name: split.last,
			address_1: address.line1 || '',
			address_2: address.line2 || '',
			postcode: address.postal_code || '',
			city: address.city || '',
			state: address.state || '',
			country: address.country || '',
			phone: phone || '',
		};
	}

	/**
	 * Wallet data mapped to the checkout field names (api.fill()).
	 */
	function expressDetails( event ) {
		var billing = event.billingDetails || {};
		var shipping = event.shippingAddress || null;
		var details = {
			email: billing.email || '',
			phone: billing.phone || '',
			billing: toAddress( billing.name || ( shipping && shipping.name ), billing.address, billing.phone ),
		};
		if ( shipping ) {
			details.shipping = toAddress( shipping.name || billing.name, shipping.address, billing.phone );
			details.billing_different = !! ( billing.address && shipping.address && billing.address.line1 && billing.address.line1 !== shipping.address.line1 );
		}
		if ( event.shippingRate && event.shippingRate.id ) {
			details.shipping_method = event.shippingRate.id;
		}
		return details;
	}

	function shippingRates( totals ) {
		var options = ( totals && totals.shipping_options ) || [];
		var chosen = totals && totals.shipping_method;
		var rates = options.map( function ( option ) {
			var rate = {
				id: String( option.id ),
				displayName: String( option.title || option.id ),
				amount: parseInt( option.cost, 10 ) || 0,
			};
			if ( option.max_days ) {
				rate.deliveryEstimate = {
					minimum: { unit: 'business_day', value: Math.max( 1, parseInt( option.min_days, 10 ) || 1 ) },
					maximum: { unit: 'business_day', value: Math.max( 1, parseInt( option.max_days, 10 ) || 1 ) },
				};
			}
			return rate;
		} );
		// The first rate is the default one in the wallet sheet.
		rates.sort( function ( a, b ) {
			return ( b.id === chosen ? 1 : 0 ) - ( a.id === chosen ? 1 : 0 );
		} );
		return rates;
	}

	function request( path, body ) {
		if ( window.Wahou && typeof window.Wahou.api === 'function' ) {
			return window.Wahou.api( path, { method: 'POST', body: body } );
		}
		var data = window.wahouData || {};
		var headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
		if ( data.nonce ) {
			headers[ 'X-WP-Nonce' ] = data.nonce;
		}
		return fetch( ( data.restUrl || '/wp-json/wahou/v1/' ) + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( body || {} ),
		} ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( json ) {
					if ( ! response.ok ) {
						var error = new Error( ( json && json.message ) || 'Request failed' );
						error.code = json && json.code;
						error.status = response.status;
						throw error;
					}
					return json;
				} );
		} );
	}

	function notifyCart( cart ) {
		if ( cart ) {
			document.dispatchEvent( new CustomEvent( 'wahou:cart-updated', { detail: cart } ) );
		}
	}

	var gateway = {
		ownsSubmit: false,
		mounted: false,
		expressMounted: false,

		mount: function ( ctx ) {
			ctx = ctx || {};
			var cfg = ctx.config || config();
			this.cfg = cfg;
			this.api = ctx.api || this.api;
			this.lastTotals = ctx.totals || this.lastTotals;

			var root = ctx.container;
			var container = root && root.id === 'wahou-stripe-element' ? root : null;
			if ( ! container && root && root.querySelector ) {
				container = root.querySelector( '#wahou-stripe-element' );
			}
			container = container || document.getElementById( 'wahou-stripe-element' );
			if ( ! container || ! cfg ) {
				return;
			}
			if ( this.mounted ) {
				return;
			}

			var stripe = getStripe( cfg );
			if ( ! stripe ) {
				showMessage( container, strings( cfg ).loadFailed || 'Stripe could not load.' );
				return;
			}
			this.stripe = stripe;

			var options = {
				mode: 'payment',
				amount: amountFrom( this.lastTotals, cfg ),
				currency: cfg.currency,
				appearance: cfg.appearance || {},
			};
			if ( cfg.setupFutureUsage ) {
				options.setupFutureUsage = cfg.setupFutureUsage;
			}
			this.elements = stripe.elements( options );

			var elementOptions = {
				layout: { type: 'accordion', defaultCollapsed: false },
				fields: { billingDetails: { email: 'never', address: { country: 'never' } } },
				// Wallets get their own one-tap buttons in the express area.
				wallets: cfg.express ? { applePay: 'never', googlePay: 'never' } : { applePay: 'auto', googlePay: 'auto' },
			};
			if ( cfg.storeName ) {
				elementOptions.business = { name: cfg.storeName };
			}
			this.paymentElement = this.elements.create( 'payment', elementOptions );
			// Invalid key, network filter or ad blocker: tell the shopper instead of an empty box.
			this.paymentElement.on( 'loaderror', function () {
				showMessage( container, strings( cfg ).loadFailed || 'Stripe could not load.' );
			} );
			this.paymentElement.mount( container );
			this.mounted = true;

			// Fallback when the checkout does not call expressMount() itself.
			var self = this;
			if ( cfg.express && ! this.expressMounted ) {
				var express = document.getElementById( 'wahou-express' );
				if ( express ) {
					setTimeout( function () {
						if ( ! self.expressMounted ) {
							self.expressMount( { container: express, config: cfg, totals: self.lastTotals, api: self.api } );
						}
					}, 0 );
				}
			}
		},

		onTotalsChange: function ( totals ) {
			this.lastTotals = totals || this.lastTotals;
			var amount = amountFrom( this.lastTotals, this.cfg );
			try {
				if ( this.elements ) {
					this.elements.update( { amount: amount } );
				}
				if ( this.expressElements ) {
					this.expressElements.update( { amount: amount } );
				}
			} catch ( e ) {
				// Elements may be mid-update; the next change fixes it.
			}
		},

		validate: async function () {
			var i18n = strings( this.cfg );
			if ( ! this.elements ) {
				throw new Error( i18n.notReady || 'Stripe is not ready.' );
			}
			var result = await this.elements.submit();
			if ( result && result.error ) {
				throw new Error( result.error.message || i18n.paymentFailed );
			}
			return true;
		},

		pay: async function ( payment, api ) {
			api = api || this.api;
			var i18n = strings( this.cfg );
			if ( payment && payment.action === 'redirect' && payment.redirect ) {
				api.redirect( payment.redirect );
				return;
			}
			if ( ! payment || payment.action !== 'stripe_confirm' || ! payment.client_secret || ! this.stripe ) {
				throw new Error( i18n.paymentFailed || 'Payment failed.' );
			}
			var data = api && typeof api.collect === 'function' ? api.collect() : {};
			var result = await this.stripe.confirmPayment( {
				elements: this.elements,
				clientSecret: payment.client_secret,
				confirmParams: {
					return_url: payment.return_url,
					payment_method_data: { billing_details: billingDetails( data, this.cfg ) },
				},
			} );
			// confirmPayment only resolves when the customer was not redirected.
			if ( result && result.error ) {
				throw new Error( result.error.message || i18n.paymentFailed );
			}
		},

		expressMount: function ( ctx ) {
			ctx = ctx || {};
			var cfg = ctx.config || this.cfg || config();
			var container = ctx.container || document.getElementById( 'wahou-express' );
			if ( ! cfg || ! cfg.express || ! container || this.expressMounted ) {
				return;
			}
			var stripe = getStripe( cfg );
			if ( ! stripe ) {
				return;
			}
			this.cfg = this.cfg || cfg;
			this.stripe = stripe;
			this.api = ctx.api || this.api;
			this.lastTotals = ctx.totals || this.lastTotals;
			this.expressMounted = true;

			var self = this;
			var options = {
				mode: 'payment',
				amount: amountFrom( this.lastTotals, cfg ),
				currency: cfg.currency,
				appearance: cfg.appearance || {},
			};
			if ( cfg.setupFutureUsage ) {
				options.setupFutureUsage = cfg.setupFutureUsage;
			}
			this.expressElements = stripe.elements( options );

			var slot = document.createElement( 'div' );
			slot.className = 'wahou-express__stripe';
			container.appendChild( slot );

			var element = this.expressElements.create( 'expressCheckout', {
				buttonHeight: 48,
				buttonType: { applePay: 'buy', googlePay: 'buy' },
				// PayPal has its own gateway in the store.
				paymentMethods: { paypal: 'never' },
				layout: { maxColumns: 2, maxRows: 2, overflow: 'auto' },
			} );
			this.expressElement = element;

			element.on( 'ready', function ( event ) {
				var methods = ( event && event.availablePaymentMethods ) || {};
				var available = Object.keys( methods ).some( function ( key ) {
					return !! methods[ key ];
				} );
				slot.hidden = ! available;
				container.classList.toggle( 'wahou-express--ready', available );
				document.dispatchEvent( new CustomEvent( 'wahou:express-ready', { detail: { gateway: GATEWAY_ID, available: available } } ) );
			} );
			element.on( 'loaderror', function () {
				slot.hidden = true;
				document.dispatchEvent( new CustomEvent( 'wahou:express-ready', { detail: { gateway: GATEWAY_ID, available: false } } ) );
			} );
			element.on( 'click', function ( event ) {
				self.onExpressClick( event );
			} );
			element.on( 'shippingaddresschange', function ( event ) {
				self.onShippingAddressChange( event );
			} );
			element.on( 'shippingratechange', function ( event ) {
				self.onShippingRateChange( event );
			} );
			element.on( 'confirm', function ( event ) {
				self.onExpressConfirm( event );
			} );
			element.on( 'cancel', function () {
				if ( self.api && typeof self.api.setBusy === 'function' ) {
					self.api.setBusy( false );
				}
			} );
			element.mount( slot );
		},

		onExpressClick: function ( event ) {
			var api = this.api;
			// Terms / digital content consent first: without resolve() the sheet stays closed.
			if ( api && typeof api.checkConsents === 'function' && ! api.checkConsents() ) {
				return;
			}
			var cfg = this.cfg || {};
			var totals = ( api && typeof api.totals === 'function' && api.totals() ) || this.lastTotals || {};
			var options = { emailRequired: true, billingAddressRequired: true };
			if ( cfg.needsShipping ) {
				options.shippingAddressRequired = true;
				options.phoneNumberRequired = !! cfg.phoneRequired;
				var rates = shippingRates( totals );
				if ( rates.length ) {
					options.shippingRates = rates;
				}
			}
			event.resolve( options );
		},

		onShippingAddressChange: async function ( event ) {
			try {
				var address = event.address || {};
				var cart = await request( 'cart/customer', {
					country: address.country || '',
					postcode: address.postal_code || '',
					city: address.city || '',
				} );
				var totals = cart && cart.totals;
				var rates = shippingRates( totals );
				if ( ! totals || ! rates.length || ( totals.errors && totals.errors.shipping ) ) {
					event.reject();
					return;
				}
				this.lastTotals = totals;
				this.expressElements.update( { amount: amountFrom( totals, this.cfg ) } );
				event.resolve( { shippingRates: rates } );
				notifyCart( cart );
			} catch ( e ) {
				event.reject();
			}
		},

		onShippingRateChange: async function ( event ) {
			try {
				var cart = await request( 'cart/customer', { shipping_method: event.shippingRate && event.shippingRate.id } );
				var totals = cart && cart.totals;
				if ( ! totals ) {
					event.reject();
					return;
				}
				this.lastTotals = totals;
				this.expressElements.update( { amount: amountFrom( totals, this.cfg ) } );
				event.resolve();
				notifyCart( cart );
			} catch ( e ) {
				event.reject();
			}
		},

		onExpressConfirm: async function ( event ) {
			var api = this.api;
			var i18n = strings( this.cfg );
			var fail = function ( message ) {
				if ( typeof event.paymentFailed === 'function' ) {
					event.paymentFailed( { reason: 'fail' } );
				}
				if ( api && typeof api.setBusy === 'function' ) {
					api.setBusy( false );
				}
				if ( message && api && typeof api.showError === 'function' ) {
					api.showError( message );
				}
			};
			if ( ! api || typeof api.submitOrder !== 'function' ) {
				fail( i18n.paymentFailed );
				return;
			}
			try {
				if ( typeof api.setBusy === 'function' ) {
					api.setBusy( true );
				}
				var submitted = await this.expressElements.submit();
				if ( submitted && submitted.error ) {
					fail( submitted.error.message );
					return;
				}
				if ( typeof api.fill === 'function' ) {
					api.fill( expressDetails( event ) );
				}
				var response = await api.submitOrder( {
					payment_method: GATEWAY_ID,
					express: true,
					stripe_sfu: ( this.cfg && this.cfg.setupFutureUsage ) || '',
				} );
				var payment = response && response.payment;
				if ( payment && payment.action === 'redirect' && payment.redirect ) {
					api.redirect( payment.redirect );
					return;
				}
				if ( ! payment || payment.action !== 'stripe_confirm' || ! payment.client_secret ) {
					fail( i18n.paymentFailed );
					return;
				}
				var result = await this.stripe.confirmPayment( {
					elements: this.expressElements,
					clientSecret: payment.client_secret,
					confirmParams: { return_url: payment.return_url },
				} );
				if ( result && result.error ) {
					fail( result.error.message || i18n.paymentFailed );
				}
			} catch ( error ) {
				fail( ( error && error.message ) || i18n.paymentFailed );
			}
		},

		unmount: function () {
			if ( this.paymentElement ) {
				this.paymentElement.unmount();
			}
			this.mounted = false;
		},
	};

	// Methods keep their context even when the checkout stores them separately.
	Object.keys( gateway ).forEach( function ( key ) {
		if ( typeof gateway[ key ] === 'function' ) {
			gateway[ key ] = gateway[ key ].bind( gateway );
		}
	} );

	window.WahouStripe = {
		instance: function () {
			return getStripe( config() );
		},
		/**
		 * 3-D Secure for one-click offers: resolves with the PaymentIntent.
		 */
		handleNextAction: function ( clientSecret ) {
			var cfg = config();
			var stripe = getStripe( cfg );
			var i18n = strings( cfg );
			if ( ! stripe ) {
				return Promise.reject( new Error( i18n.loadFailed || 'Stripe could not load.' ) );
			}
			return stripe.handleNextAction( { clientSecret: clientSecret } ).then( function ( result ) {
				if ( result.error ) {
					throw new Error( result.error.message || i18n.authFailed );
				}
				return result.paymentIntent;
			} );
		},
	};

	var registered = false;
	function register() {
		if ( registered ) {
			return true;
		}
		if ( window.WahouCheckout && typeof window.WahouCheckout.registerGateway === 'function' ) {
			window.WahouCheckout.registerGateway( GATEWAY_ID, gateway );
			registered = true;
			return true;
		}
		return false;
	}

	if ( ! register() ) {
		// Picked up by checkout.js when it loads later, or registered on ready.
		window.wahouGatewayQueue = window.wahouGatewayQueue || [];
		window.wahouGatewayQueue.push( [ GATEWAY_ID, gateway ] );
		document.addEventListener( 'wahou:checkout-ready', register );
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', register );
		} else {
			setTimeout( register, 0 );
		}
	}
} )();
