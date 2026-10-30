/**
 * Wahou Commerce – PayPal gateway.
 *
 * Renders the PayPal buttons inside #wahou-paypal-buttons (the gateway owns
 * the submit action). The PayPal SDK (~150 KB) is loaded lazily: when PayPal
 * is selected, when the buttons scroll into view or when the browser is idle.
 */
( function () {
	'use strict';

	var GATEWAY_ID = 'paypal';
	var NAMESPACE = 'wahouPaypal';
	var sdkPromise = null;

	function config() {
		var data = window.wahouCheckoutData;
		return ( data && data.gateways && data.gateways[ GATEWAY_ID ] ) || null;
	}

	function strings( cfg ) {
		return ( cfg && cfg.i18n ) || {};
	}

	function sdk() {
		var ns = window[ NAMESPACE ];
		return ns && typeof ns.Buttons === 'function' ? ns : null;
	}

	function loadSdk( cfg ) {
		if ( sdk() ) {
			return Promise.resolve( sdk() );
		}
		if ( sdkPromise ) {
			return sdkPromise;
		}
		var params = new URLSearchParams();
		params.set( 'client-id', cfg.clientId );
		params.set( 'currency', cfg.currency );
		params.set( 'intent', 'capture' );
		params.set( 'commit', 'true' );
		params.set( 'components', 'buttons' );
		params.set( cfg.payLater ? 'enable-funding' : 'disable-funding', 'paylater' );
		if ( cfg.locale ) {
			params.set( 'locale', cfg.locale );
		}
		sdkPromise = new Promise( function ( resolve, reject ) {
			var script = document.createElement( 'script' );
			script.src = 'https://www.paypal.com/sdk/js?' + params.toString();
			script.async = true;
			// Own namespace: no clash with another plugin loading the PayPal SDK.
			script.setAttribute( 'data-namespace', NAMESPACE );
			script.onload = function () {
				if ( sdk() ) {
					resolve( sdk() );
				} else {
					sdkPromise = null;
					reject( new Error( strings( cfg ).loadFailed || 'PayPal could not load.' ) );
				}
			};
			script.onerror = function () {
				sdkPromise = null;
				reject( new Error( strings( cfg ).loadFailed || 'PayPal could not load.' ) );
			};
			document.head.appendChild( script );
		} );
		return sdkPromise;
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

	function errorCode( error ) {
		if ( ! error ) {
			return '';
		}
		return error.code || ( error.data && error.data.code ) || ( error.response && error.response.code ) || '';
	}

	function busy( api, state ) {
		if ( api && typeof api.setBusy === 'function' ) {
			api.setBusy( state );
		}
	}

	var gateway = {
		ownsSubmit: true,
		rendering: false,
		rendered: false,

		mount: function ( ctx ) {
			ctx = ctx || {};
			this.api = ctx.api || this.api;
			this.cfg = ctx.config || this.cfg || config();

			var root = ctx.container;
			var container = root && root.id === 'wahou-paypal-buttons' ? root : null;
			if ( ! container && root && root.querySelector ) {
				container = root.querySelector( '#wahou-paypal-buttons' );
			}
			container = container || document.getElementById( 'wahou-paypal-buttons' );
			if ( ! container || ! this.cfg || ! this.cfg.clientId || this.rendering || this.rendered ) {
				return;
			}
			this.container = container;

			var self = this;
			var started = false;
			var start = function () {
				if ( ! started ) {
					started = true;
					self.render();
				}
			};
			if ( ! this.cfg.lazy ) {
				start();
				return;
			}

			var radio = document.querySelector( 'input[name="payment_method"][value="' + GATEWAY_ID + '"]' );
			if ( radio ) {
				if ( radio.checked ) {
					start();
					return;
				}
				radio.addEventListener( 'change', start, { once: true } );
			}
			if ( 'IntersectionObserver' in window ) {
				var observer = new IntersectionObserver( function ( entries ) {
					if ( entries.some( function ( entry ) { return entry.isIntersecting; } ) ) {
						observer.disconnect();
						start();
					}
				} );
				observer.observe( container );
			}
			var idle = window.requestIdleCallback
				? function ( callback ) {
						window.requestIdleCallback( callback, { timeout: 4000 } );
				  }
				: function ( callback ) {
						setTimeout( callback, 2500 );
				  };
			if ( document.readyState === 'complete' ) {
				idle( start );
			} else {
				window.addEventListener( 'load', function () {
					idle( start );
				} );
			}
		},

		render: function () {
			if ( this.rendering || this.rendered || ! this.container ) {
				return;
			}
			this.rendering = true;
			var self = this;
			var cfg = this.cfg;
			var i18n = strings( cfg );

			loadSdk( cfg )
				.then( function ( paypal ) {
					var buttons = paypal.Buttons( {
						style: {
							layout: 'vertical',
							shape: ( parseInt( cfg.radius, 10 ) || 0 ) >= 20 ? 'pill' : 'rect',
							height: 48,
							label: 'paypal',
						},
						onClick: function ( data, actions ) {
							return self.onClick( data, actions );
						},
						createOrder: function ( data ) {
							return self.createOrder( data );
						},
						onApprove: function ( data, actions ) {
							return self.onApprove( data, actions );
						},
						onCancel: function () {
							busy( self.api, false );
						},
						onError: function () {
							busy( self.api, false );
							if ( ! self.errorShown && self.api && typeof self.api.showError === 'function' ) {
								self.api.showError( i18n.genericError || 'PayPal error.' );
							}
							self.errorShown = false;
						},
					} );
					if ( typeof buttons.isEligible === 'function' && ! buttons.isEligible() ) {
						self.rendering = false;
						showMessage( self.container, i18n.notEligible || 'PayPal is not available.' );
						return null;
					}
					return buttons.render( self.container ).then( function () {
						self.rendered = true;
						self.rendering = false;
					} );
				} )
				.catch( function ( error ) {
					self.rendering = false;
					showMessage( self.container, ( error && error.message ) || i18n.loadFailed );
				} );
		},

		/**
		 * Validate the form before the PayPal window opens.
		 */
		onClick: function ( data, actions ) {
			var api = this.api;
			var finish = function ( valid ) {
				if ( valid && api && typeof api.checkConsents === 'function' ) {
					valid = api.checkConsents();
				}
				return valid ? actions.resolve() : actions.reject();
			};
			var result = true;
			try {
				if ( api && typeof api.validateForm === 'function' ) {
					result = api.validateForm();
				}
			} catch ( e ) {
				result = false;
			}
			if ( result && typeof result.then === 'function' ) {
				return result.then( finish, function () {
					return actions.reject();
				} );
			}
			return finish( !! result );
		},

		createOrder: function ( data ) {
			var self = this;
			var api = this.api;
			var i18n = strings( this.cfg );
			this.errorShown = false;
			if ( ! api || typeof api.submitOrder !== 'function' ) {
				return Promise.reject( new Error( i18n.createFailed ) );
			}
			busy( api, true );
			return Promise.resolve(
				api.submitOrder( {
					payment_method: GATEWAY_ID,
					paypal_funding: ( data && data.paymentSource ) || 'paypal',
				} )
			)
				.then( function ( response ) {
					var payment = response && response.payment;
					if ( payment && payment.action === 'redirect' && payment.redirect ) {
						// Free order: nothing to pay.
						api.redirect( payment.redirect );
						self.errorShown = true;
						throw new Error( 'redirect' );
					}
					if ( ! payment || ! payment.paypal_order_id ) {
						throw new Error( i18n.createFailed );
					}
					self.orderKey = response.order_key;
					return payment.paypal_order_id;
				} )
				.catch( function ( error ) {
					busy( api, false );
					if ( ! self.errorShown && typeof api.showError === 'function' ) {
						api.showError( ( error && error.message ) || i18n.createFailed );
						self.errorShown = true;
					}
					throw error;
				} );
		},

		onApprove: function ( data, actions ) {
			var self = this;
			var api = this.api;
			var i18n = strings( this.cfg );
			busy( api, true );
			return request( 'paypal/capture', { order_key: self.orderKey, paypal_order_id: data.orderID } )
				.then( function ( result ) {
					if ( result && result.redirect ) {
						api.redirect( result.redirect );
						return;
					}
					throw new Error( i18n.captureFailed );
				} )
				.catch( function ( error ) {
					busy( api, false );
					// Card or balance declined: let the buyer pick another funding source.
					if ( errorCode( error ) === 'wahou_paypal_declined' && actions && typeof actions.restart === 'function' ) {
						return actions.restart();
					}
					if ( api && typeof api.showError === 'function' ) {
						api.showError( ( error && error.message ) || i18n.captureFailed );
					}
				} );
		},

		onTotalsChange: function () {
			// The amount is set server side when the PayPal order is created.
		},

		validate: async function () {
			return true;
		},

		pay: async function () {
			throw new Error( strings( this.cfg ).useButton || 'Use the PayPal button.' );
		},

		unmount: function () {
			if ( this.container ) {
				this.container.textContent = '';
			}
			this.rendered = false;
			this.rendering = false;
		},
	};

	Object.keys( gateway ).forEach( function ( key ) {
		if ( typeof gateway[ key ] === 'function' ) {
			gateway[ key ] = gateway[ key ].bind( gateway );
		}
	} );

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
