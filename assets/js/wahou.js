/*!
 * Wahou Commerce – storefront script (vanilla, no dependencies).
 * Cart drawer, add to cart, variants, gallery, sticky bar, delivery estimate,
 * frequently bought together and GA4 dataLayer events.
 */
(function () {
	'use strict';

	if (window.Wahou) {
		return;
	}

	var D = window.wahouData || {};
	var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var state = { cart: null, count: 0 };

	var ICONS = {
		cart: '<svg class="wahou-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 8H6.2"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>',
		check: '<svg class="wahou-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12.5l4.2 4.2L19 7"/></svg>',
		alert: '<svg class="wahou-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/></svg>',
		close: '<svg class="wahou-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>',
		left: '<svg class="wahou-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6"/></svg>',
		right: '<svg class="wahou-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>'
	};

	/* --------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------- */

	function $(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function $$(sel, ctx) {
		return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
	}

	function t(key, fallback) {
		return (D.i18n && D.i18n[key]) || fallback || key;
	}

	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(str).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
			var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
			return v === undefined ? '' : String(v);
		});
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	var nf = null;
	var decimals = 2;

	function format(amount) {
		if (!nf) {
			try {
				nf = new Intl.NumberFormat(D.locale || undefined, { style: 'currency', currency: D.currency || 'EUR' });
				decimals = nf.resolvedOptions().maximumFractionDigits;
			} catch (e) {
				nf = { format: function (v) { return v.toFixed(2) + ' ' + (D.currency || ''); } };
			}
		}
		return nf.format((Number(amount) || 0) / Math.pow(10, decimals));
	}

	function toDecimal(amount) {
		format(0);
		return Math.round(Number(amount) || 0) / Math.pow(10, decimals);
	}

	function drawerMode() {
		return D.cartMode !== 'page' && D.context !== 'cart' && D.context !== 'checkout';
	}

	/* --------------------------------------------------------------
	 * REST client
	 * -------------------------------------------------------------- */

	function buildUrl(path) {
		var base = D.restUrl || '/wp-json/wahou/v1/';
		var parts = String(path).replace(/^\//, '').split('?');
		var sep = base.indexOf('?') === -1 ? '?' : '&';
		return base + parts[0] + (parts[1] ? sep + parts[1] : '');
	}

	function makeError(message, code, status, data) {
		var err = new Error(message || t('error'));
		err.code = code || 'error';
		err.status = status || 0;
		err.data = data || null;
		err.fields = (data && data.fields) || null;
		return err;
	}

	function isCart(data) {
		return data && typeof data === 'object' && typeof data.count === 'number' && Array.isArray(data.items);
	}

	function api(path, opts, retried) {
		opts = opts || {};
		var method = (opts.method || 'GET').toUpperCase();
		var headers = { Accept: 'application/json' };
		if (D.nonce) {
			headers['X-WP-Nonce'] = D.nonce;
		}
		if (method !== 'GET' && method !== 'POST') {
			headers['X-HTTP-Method-Override'] = method;
			method = 'POST';
		}
		var init = { method: method, headers: headers, credentials: 'same-origin', cache: 'no-store' };
		if (opts.body !== undefined) {
			headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		return fetch(buildUrl(path), init).then(function (res) {
			var fresh = res.headers.get('X-WP-Nonce');
			if (fresh && D.nonce) {
				D.nonce = fresh;
			}
			return res.json().catch(function () { return null; }).then(function (data) {
				if (!res.ok) {
					if (data && data.code === 'rest_cookie_invalid_nonce' && !retried) {
						D.nonce = '';
						return api(path, opts, true);
					}
					throw makeError(data && data.message, data && data.code, res.status, data && data.data);
				}
				if (isCart(data)) {
					applyCart(data);
				}
				return data;
			});
		}, function () {
			throw makeError(t('error'), 'network', 0, null);
		});
	}

	/* --------------------------------------------------------------
	 * Analytics (GA4 dataLayer)
	 * -------------------------------------------------------------- */

	function track(event, ecommerce) {
		if (!D.analytics || !ecommerce) {
			return;
		}
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push({ ecommerce: null });
		window.dataLayer.push({ event: event, ecommerce: ecommerce });
	}

	function cartItemToGa(item, qty) {
		var quantity = qty || item.qty || 1;
		return {
			item_id: String(item.product_id),
			item_name: item.name,
			item_variant: item.variant_label || undefined,
			price: toDecimal(item.unit_price),
			quantity: quantity
		};
	}

	/* --------------------------------------------------------------
	 * Count badges, floating cart button, toasts
	 * -------------------------------------------------------------- */

	function readCookieCount() {
		var m = document.cookie.match(/(?:^|;\s*)wahou_cart_count=(\d+)/);
		return m ? parseInt(m[1], 10) : 0;
	}

	function writeCookieCount(n) {
		var secure = location.protocol === 'https:' ? '; secure' : '';
		document.cookie = 'wahou_cart_count=' + n + '; path=/; max-age=' + (30 * 86400) + '; samesite=lax' + secure;
	}

	function setCount(n, silent) {
		n = Math.max(0, parseInt(n, 10) || 0);
		var grew = n > state.count;
		state.count = n;
		$$('[data-wahou-count]').forEach(function (el) {
			el.textContent = n;
			el.hidden = n === 0;
			if (grew && !silent && !reducedMotion) {
				el.classList.remove('wahou-bump');
				void el.offsetWidth;
				el.classList.add('wahou-bump');
			}
		});
		$$('[data-wahou-cart-icon]').forEach(function (el) {
			el.setAttribute('aria-label', t('openCart') + (n ? ' (' + n + ')' : ''));
		});
		if (readCookieCount() !== n) {
			writeCookieCount(n);
		}
		ensureFab();
	}

	function ensureFab() {
		var fab = $('.wahou-fab');
		var blocked = D.context === 'cart' || D.context === 'checkout' || D.context === 'account' || D.floatingCart === false;
		var headerIcon = $$('[data-wahou-cart-icon]').some(function (el) { return el.offsetParent !== null; });
		if (blocked || headerIcon || state.count === 0) {
			if (fab) {
				fab.hidden = true;
			}
			return;
		}
		if (!fab) {
			fab = document.createElement('a');
			fab.className = 'wahou-fab';
			fab.href = D.cartUrl || '#';
			fab.innerHTML = ICONS.cart + '<span class="wahou-fab__count" data-wahou-count>' + state.count + '</span>';
			document.body.appendChild(fab);
		}
		fab.setAttribute('data-wahou-cart-toggle', '');
		fab.setAttribute('aria-label', t('openCart') + ' (' + state.count + ')');
		var count = $('.wahou-fab__count', fab);
		if (count) {
			count.textContent = state.count;
			count.hidden = false;
		}
		fab.hidden = false;
	}

	function toastBox() {
		var box = $('.wahou-toasts');
		if (!box) {
			box = document.createElement('div');
			box.className = 'wahou-toasts';
			box.setAttribute('aria-live', 'polite');
			document.body.appendChild(box);
		}
		return box;
	}

	function toast(message, type, action) {
		var el = document.createElement('div');
		el.className = 'wahou-toast' + (type === 'error' ? ' wahou-toast--error' : '');
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');
		el.innerHTML = (type === 'error' ? ICONS.alert : ICONS.check) + '<span></span>';
		el.querySelector('span').textContent = message;
		if (action && action.href) {
			var a = document.createElement('a');
			a.href = action.href;
			a.textContent = action.label;
			el.appendChild(a);
		}
		toastBox().appendChild(el);
		setTimeout(function () {
			el.classList.add('is-leaving');
			setTimeout(function () { el.remove(); }, 250);
		}, type === 'error' ? 6000 : 3500);
	}

	var liveRegion = null;

	function announce(message) {
		if (!liveRegion) {
			liveRegion = document.createElement('div');
			liveRegion.className = 'screen-reader-text';
			liveRegion.setAttribute('aria-live', 'polite');
			liveRegion.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)';
			document.body.appendChild(liveRegion);
		}
		liveRegion.textContent = '';
		setTimeout(function () { liveRegion.textContent = message; }, 50);
	}

	/* --------------------------------------------------------------
	 * Cart state & drawer
	 * -------------------------------------------------------------- */

	function applyCart(data) {
		state.cart = data;
		setCount(data.count);
		if (data.html && typeof data.html.drawer === 'string') {
			drawer.setContent(data.html.drawer);
		} else {
			drawer.stale = true;
		}
		document.dispatchEvent(new CustomEvent('wahou:cart-updated', { detail: data }));
	}

	function refresh() {
		return api(drawerMode() ? 'cart?html=drawer' : 'cart');
	}

	var drawer = {
		el: null,
		panel: null,
		content: null,
		isOpen: false,
		loaded: false,
		stale: false,
		lastFocus: null,
		timer: null,

		ensure: function () {
			if (this.el) {
				return;
			}
			var el = document.getElementById('wahou-drawer');
			if (!el) {
				el = document.createElement('div');
				el.id = 'wahou-drawer';
				el.className = 'wahou-drawer';
				el.hidden = true;
				el.innerHTML = '<div class="wahou-drawer__overlay" data-wahou-close></div><div class="wahou-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="wahou-drawer-title" tabindex="-1"><div class="wahou-drawer__content" data-wahou-drawer-content></div></div>';
				document.body.appendChild(el);
			}
			this.el = el;
			this.panel = $('.wahou-drawer__panel', el);
			this.content = $('[data-wahou-drawer-content]', el);
			el.addEventListener('click', onDrawerClick);
			el.addEventListener('submit', onDrawerSubmit);
			el.addEventListener('change', onDrawerChange);
			el.addEventListener('keydown', onDrawerKeydown);
		},

		setContent: function (html) {
			this.ensure();
			var active = document.activeElement;
			var key = active && this.el.contains(active) ? active.getAttribute('data-wahou-focus') : null;
			this.content.innerHTML = html;
			this.content.removeAttribute('aria-busy');
			this.loaded = true;
			this.stale = false;
			if (this.isOpen && key) {
				var target = this.content.querySelector('[data-wahou-focus="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
				(target || this.content.querySelector('[data-wahou-close]') || this.panel).focus();
			}
		},

		busy: function (on) {
			this.ensure();
			if (on) {
				this.content.setAttribute('aria-busy', 'true');
			} else {
				this.content.removeAttribute('aria-busy');
			}
		},

		open: function () {
			if (!drawerMode()) {
				if (D.cartUrl) {
					location.href = D.cartUrl;
				}
				return Promise.resolve();
			}
			this.ensure();
			if (this.isOpen) {
				return Promise.resolve();
			}
			clearTimeout(this.timer);
			this.lastFocus = document.activeElement;
			this.isOpen = true;
			this.el.hidden = false;
			void this.el.offsetWidth;
			this.el.classList.add('is-open');
			document.documentElement.classList.add('wahou-scroll-lock');
			if (!this.loaded) {
				this.content.innerHTML = '<div class="wahou-drawer__loading"><span class="wahou-spinner" role="status" aria-label="' + escapeHtml(t('loading')) + '"></span></div>';
			}
			this.panel.focus();
			var self = this;
			var ready = (!this.loaded || this.stale) ? refresh() : Promise.resolve(state.cart);
			return ready.then(function () {
				var close = self.content.querySelector('[data-wahou-close]');
				if (close && self.isOpen) {
					close.focus();
				}
				if (state.cart && state.cart.items && state.cart.items.length && state.cart.totals) {
					track('view_cart', {
						currency: state.cart.totals.currency,
						value: toDecimal(state.cart.totals.subtotal - state.cart.totals.discount),
						items: state.cart.items.map(function (i) { return cartItemToGa(i); })
					});
				}
			}).catch(function (err) {
				toast(err.message, 'error');
			});
		},

		close: function () {
			if (!this.isOpen) {
				return;
			}
			this.isOpen = false;
			this.el.classList.remove('is-open');
			document.documentElement.classList.remove('wahou-scroll-lock');
			var self = this;
			this.timer = setTimeout(function () {
				if (!self.isOpen) {
					self.el.hidden = true;
				}
			}, reducedMotion ? 0 : 320);
			if (this.lastFocus && this.lastFocus.focus && document.contains(this.lastFocus)) {
				this.lastFocus.focus();
			}
		}
	};

	function lineRequest(path, method, body) {
		drawer.busy(true);
		return api(path + (drawerMode() ? '?html=drawer' : ''), { method: method, body: body }).then(function (data) {
			announce(t('cartUpdated'));
			return data;
		}).catch(function (err) {
			drawer.busy(false);
			toast(err.message, 'error');
		});
	}

	function updateLine(key, qty) {
		return lineRequest('cart/items/' + key, 'PATCH', { qty: qty });
	}

	function removeLine(key) {
		var item = state.cart && state.cart.items ? state.cart.items.filter(function (i) { return i.key === key; })[0] : null;
		return lineRequest('cart/items/' + key, 'DELETE').then(function (data) {
			if (item && data) {
				track('remove_from_cart', { currency: D.currency, value: toDecimal(item.line_total), items: [cartItemToGa(item)] });
			}
		});
	}

	function onDrawerClick(e) {
		var target = e.target;
		if (target.closest('[data-wahou-close]')) {
			e.preventDefault();
			drawer.close();
			return;
		}
		var line = target.closest('[data-key]');
		var step = target.closest('[data-wahou-line-step]');
		if (step && line) {
			var input = $('[data-wahou-line-input]', line);
			var max = input && input.max ? parseInt(input.max, 10) : 999;
			var next = Math.max(0, Math.min(max, (parseInt(input && input.value, 10) || 0) + parseInt(step.getAttribute('data-wahou-line-step'), 10)));
			updateLine(line.getAttribute('data-key'), next);
			return;
		}
		if (target.closest('[data-wahou-remove]') && line) {
			removeLine(line.getAttribute('data-key'));
			return;
		}
		var add = target.closest('[data-wahou-add]');
		if (add) {
			e.preventDefault();
			setBusy(add, true);
			addToCart({ product_id: parseInt(add.getAttribute('data-wahou-add'), 10), qty: 1 }, add).catch(function (err) {
				toast(err.message, 'error');
			}).then(function () {
				setBusy(add, false);
			});
			return;
		}
		if (target.closest('[data-wahou-coupon-remove]')) {
			lineRequest('cart/coupon', 'DELETE');
		}
	}

	function onDrawerSubmit(e) {
		var form = e.target.closest('[data-wahou-coupon-form]');
		if (!form) {
			return;
		}
		e.preventDefault();
		var input = form.querySelector('input[name="code"]');
		var error = drawer.content.querySelector('[data-wahou-coupon-error]');
		var code = input ? input.value.trim() : '';
		if (!code) {
			return;
		}
		var button = form.querySelector('button');
		setBusy(button, true);
		api('cart/coupon' + (drawerMode() ? '?html=drawer' : ''), { method: 'POST', body: { code: code } }).then(function () {
			announce(t('cartUpdated'));
		}).catch(function (err) {
			setBusy(button, false);
			if (error) {
				error.textContent = err.message;
				error.hidden = false;
			}
			if (input) {
				input.setAttribute('aria-invalid', 'true');
				input.focus();
			}
		});
	}

	var lineTimer = null;

	function onDrawerChange(e) {
		var input = e.target.closest('[data-wahou-line-input]');
		var line = input && input.closest('[data-key]');
		if (!line) {
			return;
		}
		clearTimeout(lineTimer);
		lineTimer = setTimeout(function () {
			var max = input.max ? parseInt(input.max, 10) : 999;
			updateLine(line.getAttribute('data-key'), Math.max(0, Math.min(max, parseInt(input.value, 10) || 0)));
		}, 350);
	}

	function onDrawerKeydown(e) {
		if (e.key === 'Escape') {
			e.preventDefault();
			drawer.close();
			return;
		}
		if (e.key !== 'Tab') {
			return;
		}
		var focusable = $$('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea, summary, [tabindex]:not([tabindex="-1"])', drawer.panel).filter(function (el) {
			return el.offsetParent !== null;
		});
		if (!focusable.length) {
			e.preventDefault();
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (e.shiftKey && (document.activeElement === first || document.activeElement === drawer.panel)) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	}

	/* --------------------------------------------------------------
	 * Add to cart
	 * -------------------------------------------------------------- */

	function setBusy(button, on) {
		if (!button) {
			return;
		}
		button.classList.toggle('is-busy', !!on);
		if (on) {
			button.setAttribute('aria-busy', 'true');
		} else {
			button.removeAttribute('aria-busy');
		}
	}

	function flashAdded(button) {
		if (!button) {
			return;
		}
		var label = button.querySelector('[data-wahou-atc-label]');
		var original = label ? label.textContent : null;
		button.classList.add('is-added');
		if (label) {
			label.textContent = t('added');
		}
		setTimeout(function () {
			button.classList.remove('is-added');
			if (label && original !== null) {
				label.textContent = original;
			}
		}, 1800);
	}

	function afterAdd(data) {
		if (drawerMode()) {
			drawer.open();
		} else {
			toast(t('added'), 'success', { href: D.cartUrl, label: t('viewCart') });
		}
		return data;
	}

	function trackAdd(data, qty) {
		if (!data || !data.items) {
			return;
		}
		var item = data.items.filter(function (i) { return i.key === data.added_key; })[0];
		if (item) {
			track('add_to_cart', {
				currency: (data.totals && data.totals.currency) || D.currency,
				value: toDecimal(item.unit_price * (qty || 1)),
				items: [cartItemToGa(item, qty || 1)]
			});
		}
	}

	/**
	 * POST cart/items. When `trigger` is given, the drawer or a toast confirms.
	 */
	function addToCart(payload, trigger, options) {
		options = options || {};
		var withHtml = drawerMode() && !options.noHtml;
		return api('cart/items' + (withHtml ? '?html=drawer' : ''), { method: 'POST', body: payload }).then(function (data) {
			trackAdd(data, payload.qty);
			if (!options.silent) {
				afterAdd(data);
			}
			return data;
		});
	}

	function showFormError(form, message) {
		var box = form && form.querySelector('[data-wahou-atc-error]');
		if (box) {
			box.textContent = message;
			box.hidden = false;
		} else {
			toast(message, 'error');
		}
		if (form && !reducedMotion) {
			form.classList.remove('is-shaking');
			void form.offsetWidth;
			form.classList.add('is-shaking');
		}
	}

	function hideFormError(form) {
		var box = form && form.querySelector('[data-wahou-atc-error]');
		if (box) {
			box.hidden = true;
			box.textContent = '';
		}
	}

	function onAtcSubmit(e) {
		var form = e.target.closest ? e.target.closest('form[data-wahou-atc]') : null;
		if (!form) {
			return;
		}
		e.preventDefault();
		var submitter = e.submitter || null;
		var buyNow = !!(submitter && submitter.hasAttribute('data-wahou-buy-now'));
		var fd = new FormData(form);
		var payload = {
			product_id: parseInt(fd.get('product_id'), 10),
			qty: Math.max(1, parseInt(fd.get('qty') || '1', 10) || 1)
		};
		var variant = fd.get('variant_id');
		if (variant) {
			payload.variant_id = String(variant);
		}
		if (form.hasAttribute('data-wahou-variants') && !payload.variant_id) {
			showFormError(form, t('chooseOption'));
			var first = form.querySelector('[data-wahou-attr] input');
			if (first) {
				first.focus();
			}
			return;
		}
		var button = buyNow ? submitter : (form.querySelector('[data-wahou-atc-button]') || submitter);
		hideFormError(form);
		setBusy(button, true);
		addToCart(payload, button, { silent: buyNow, noHtml: buyNow }).then(function () {
			if (buyNow) {
				location.href = D.checkoutUrl;
				return;
			}
			setBusy(button, false);
			flashAdded(button);
		}).catch(function (err) {
			setBusy(button, false);
			showFormError(form, err.message);
		});
	}

	/* --------------------------------------------------------------
	 * Variants
	 * -------------------------------------------------------------- */

	function initVariants(form) {
		var json = form.querySelector('[data-wahou-variants-json]');
		if (!json) {
			return;
		}
		var data;
		try {
			data = JSON.parse(json.textContent);
		} catch (e) {
			return;
		}
		var root = form.closest('[data-wahou-product]') || document;
		var fieldsets = $$('[data-wahou-attr]', form);
		var names = fieldsets.map(function (fs) { return fs.getAttribute('data-wahou-attr'); });
		var input = form.querySelector('[data-wahou-variant-input]');
		var priceTarget = root.querySelector('[data-wahou-price-target]');
		var stockBox = form.querySelector('[data-wahou-stock]');
		var sku = form.querySelector('[data-wahou-sku]');
		var buttons = $$('[data-wahou-atc-button], [data-wahou-buy-now]', form);
		var label = form.querySelector('[data-wahou-atc-label]');
		var qty = form.querySelector('.wahou-qty__input');

		function attr(variant, name) {
			return variant.attributes ? variant.attributes[name] : undefined;
		}

		function fits(variant, selection) {
			return names.every(function (n) {
				var v = attr(variant, n);
				return selection[n] === undefined || !v || v === selection[n];
			});
		}

		function selected() {
			var s = {};
			fieldsets.forEach(function (fs) {
				var checked = fs.querySelector('input:checked');
				if (checked) {
					s[fs.getAttribute('data-wahou-attr')] = checked.value;
				}
			});
			return s;
		}

		function update(initial) {
			var sel = selected();
			var complete = names.every(function (n) { return sel[n] !== undefined; });
			var variant = complete ? data.variants.filter(function (v) { return fits(v, sel); })[0] || null : null;

			fieldsets.forEach(function (fs) {
				var name = fs.getAttribute('data-wahou-attr');
				$$('input', fs).forEach(function (inp) {
					var test = {};
					Object.keys(sel).forEach(function (k) { test[k] = sel[k]; });
					test[name] = inp.value;
					var possible = data.variants.some(function (v) { return v.in_stock && fits(v, test); });
					var chip = inp.closest('.wahou-chip');
					if (chip) {
						chip.classList.toggle('is-unavailable', !possible);
					}
				});
				var valueEl = fs.querySelector('[data-wahou-attr-value]');
				if (valueEl) {
					valueEl.textContent = sel[name] || '';
					valueEl.hidden = !sel[name];
				}
			});

			if (input) {
				input.value = variant ? variant.id : '';
			}
			if (priceTarget) {
				priceTarget.innerHTML = variant ? variant.price_html : data.price_html;
			}
			if (stockBox) {
				stockBox.innerHTML = variant ? variant.stock_html : '';
			}
			if (sku) {
				sku.textContent = variant && variant.sku ? variant.sku : '';
				if (sku.parentElement) {
					sku.parentElement.hidden = !(variant && variant.sku);
				}
			}
			var inStock = variant ? !!variant.in_stock : true;
			buttons.forEach(function (b) { b.disabled = !inStock; });
			if (label) {
				label.textContent = inStock ? t('addToCart') : t('outOfStock');
			}
			if (qty) {
				if (variant && variant.max !== null && variant.max !== undefined) {
					qty.max = variant.max;
					if (parseInt(qty.value, 10) > variant.max) {
						qty.value = Math.max(1, variant.max);
					}
				} else {
					qty.removeAttribute('max');
				}
			}
			if (!initial) {
				if (variant && variant.image) {
					var gallery = root.querySelector('[data-wahou-gallery]');
					if (gallery && gallery.wahouShow) {
						gallery.wahouShow(variant.image);
					}
				}
				try {
					var url = new URL(location.href);
					if (variant) {
						url.searchParams.set('variant', variant.id);
					} else {
						url.searchParams.delete('variant');
					}
					history.replaceState(history.state, '', url.toString());
				} catch (e) { /* ignore */ }
				hideFormError(form);
			}
			root.dispatchEvent(new CustomEvent('wahou:variant-change', { bubbles: true, detail: { variant: variant, form: form } }));
		}

		form.addEventListener('change', function (e) {
			if (e.target.closest('[data-wahou-attr]')) {
				update(false);
			}
		});
		update(true);
	}

	/* --------------------------------------------------------------
	 * Gallery & lightbox
	 * -------------------------------------------------------------- */

	var lightbox = {
		dlg: null,
		urls: [],
		index: 0,

		build: function () {
			var d = document.createElement('dialog');
			d.className = 'wahou-lightbox';
			d.innerHTML = '<img class="wahou-lightbox__img" alt="">' +
				'<span class="wahou-lightbox__count" aria-live="polite"></span>' +
				'<button type="button" class="wahou-lightbox__btn wahou-lightbox__close" aria-label="' + escapeHtml(t('close')) + '">' + ICONS.close + '</button>' +
				'<button type="button" class="wahou-lightbox__btn wahou-lightbox__prev" aria-label="' + escapeHtml(t('previous')) + '">' + ICONS.left + '</button>' +
				'<button type="button" class="wahou-lightbox__btn wahou-lightbox__next" aria-label="' + escapeHtml(t('next')) + '">' + ICONS.right + '</button>';
			document.body.appendChild(d);
			var self = this;
			d.querySelector('.wahou-lightbox__close').addEventListener('click', function () { d.close(); });
			d.querySelector('.wahou-lightbox__prev').addEventListener('click', function () { self.show(self.index - 1); });
			d.querySelector('.wahou-lightbox__next').addEventListener('click', function () { self.show(self.index + 1); });
			d.addEventListener('click', function (e) {
				if (e.target === d) {
					d.close();
				}
			});
			d.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowRight') {
					self.show(self.index + 1);
				} else if (e.key === 'ArrowLeft') {
					self.show(self.index - 1);
				}
			});
			var startX = null;
			d.addEventListener('touchstart', function (e) {
				startX = e.touches.length === 1 ? e.touches[0].clientX : null;
			}, { passive: true });
			d.addEventListener('touchend', function (e) {
				if (startX === null || !e.changedTouches.length) {
					return;
				}
				var dx = e.changedTouches[0].clientX - startX;
				if (Math.abs(dx) > 50) {
					self.show(self.index + (dx < 0 ? 1 : -1));
				}
				startX = null;
			});
			d.addEventListener('close', function () {
				document.documentElement.classList.remove('wahou-scroll-lock');
			});
			this.dlg = d;
		},

		open: function (urls, index) {
			urls = urls.filter(Boolean);
			if (!urls.length) {
				return;
			}
			if (typeof HTMLDialogElement === 'undefined') {
				window.open(urls[index] || urls[0], '_blank', 'noopener');
				return;
			}
			if (!this.dlg) {
				this.build();
			}
			this.urls = urls;
			this.show(index || 0);
			this.dlg.showModal();
			document.documentElement.classList.add('wahou-scroll-lock');
		},

		show: function (i) {
			var n = this.urls.length;
			this.index = (i + n) % n;
			this.dlg.querySelector('.wahou-lightbox__img').src = this.urls[this.index];
			this.dlg.querySelector('.wahou-lightbox__count').textContent = n > 1 ? sprintf(t('imageOf', 'Image %1$d of %2$d'), this.index + 1, n) : '';
			this.dlg.querySelector('.wahou-lightbox__prev').hidden = n < 2;
			this.dlg.querySelector('.wahou-lightbox__next').hidden = n < 2;
		}
	};

	function initGallery(g) {
		var track = g.querySelector('[data-wahou-gallery-track]');
		if (!track) {
			return;
		}
		var slides = $$('.wahou-gallery__slide', track);
		var dots = $$('.wahou-gallery__dot', g);
		var thumbs = $$('.wahou-gallery__thumb', g);
		var prev = g.querySelector('[data-wahou-gallery-prev]');
		var next = g.querySelector('[data-wahou-gallery-next]');
		var rtl = getComputedStyle(track).direction === 'rtl';
		var index = 0;

		function setActive(i) {
			index = i;
			dots.forEach(function (d, k) { d.classList.toggle('is-active', k === i); });
			thumbs.forEach(function (th, k) {
				th.classList.toggle('is-active', k === i);
				th.setAttribute('aria-current', k === i ? 'true' : 'false');
			});
			if (prev) {
				prev.disabled = i === 0;
			}
			if (next) {
				next.disabled = i === slides.length - 1;
			}
		}

		function go(i) {
			i = Math.max(0, Math.min(slides.length - 1, i));
			var left = i * track.clientWidth * (rtl ? -1 : 1);
			if (track.scrollTo) {
				track.scrollTo({ left: left, behavior: reducedMotion ? 'auto' : 'smooth' });
			} else {
				track.scrollLeft = left;
			}
			setActive(i);
		}

		var raf = 0;
		track.addEventListener('scroll', function () {
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(function () {
				var i = Math.round(Math.abs(track.scrollLeft) / Math.max(1, track.clientWidth));
				if (i !== index && i < slides.length) {
					setActive(i);
				}
			});
		}, { passive: true });

		thumbs.forEach(function (th, k) {
			th.addEventListener('click', function () { go(k); });
		});
		if (prev) {
			prev.addEventListener('click', function () { go(index - 1); });
		}
		if (next) {
			next.addEventListener('click', function () { go(index + 1); });
		}
		track.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
				e.preventDefault();
				go(index + ((e.key === 'ArrowRight') !== rtl ? 1 : -1));
			}
		});
		g.addEventListener('click', function (e) {
			var link = e.target.closest('[data-wahou-zoom]');
			if (!link) {
				return;
			}
			e.preventDefault();
			lightbox.open(slides.map(function (s) {
				var a = s.querySelector('[data-wahou-zoom]');
				return a ? a.href : '';
			}), slides.indexOf(link.closest('.wahou-gallery__slide')));
		});

		g.wahouShow = function (image) {
			var k = -1;
			slides.forEach(function (s, i) {
				if (k === -1 && String(s.getAttribute('data-image-id')) === String(image.id)) {
					k = i;
				}
			});
			if (k >= 0) {
				go(k);
				return;
			}
			var img = slides[0] && slides[0].querySelector('img');
			if (img) {
				img.src = image.src;
				if (image.srcset) {
					img.srcset = image.srcset;
				} else {
					img.removeAttribute('srcset');
				}
				var a = slides[0].querySelector('[data-wahou-zoom]');
				if (a) {
					a.href = image.full || image.src;
				}
			}
			go(0);
		};

		setActive(0);
	}

	/* --------------------------------------------------------------
	 * Sticky add-to-cart bar
	 * -------------------------------------------------------------- */

	function initSticky(root) {
		var bar = root.querySelector('[data-wahou-sticky]');
		var form = root.querySelector('form[data-wahou-atc]');
		var mainButton = form && form.querySelector('[data-wahou-atc-button]');
		if (!bar || !mainButton || !('IntersectionObserver' in window)) {
			return;
		}
		var addButton = bar.querySelector('[data-wahou-sticky-add]');
		bar.hidden = false;
		bar.inert = true;
		bar.setAttribute('aria-hidden', 'true');

		var observer = new IntersectionObserver(function (entries) {
			var entry = entries[0];
			var show = !entry.isIntersecting && entry.boundingClientRect.top < 0;
			bar.classList.toggle('is-visible', show);
			bar.inert = !show;
			bar.setAttribute('aria-hidden', show ? 'false' : 'true');
			document.body.classList.toggle('wahou-sticky-on', show);
		});
		observer.observe(mainButton);

		addButton.addEventListener('click', function () {
			var variantInput = form.querySelector('[data-wahou-variant-input]');
			if (form.hasAttribute('data-wahou-variants') && variantInput && !variantInput.value) {
				form.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
				showFormError(form, t('chooseOption'));
				return;
			}
			if (form.requestSubmit) {
				form.requestSubmit(mainButton);
			} else {
				mainButton.click();
			}
		});

		root.addEventListener('wahou:variant-change', function (e) {
			var v = e.detail && e.detail.variant;
			var price = bar.querySelector('[data-wahou-sticky-price]');
			var label = addButton.querySelector('span');
			if (v) {
				if (price) {
					price.textContent = format(v.price);
				}
				if (label) {
					label.textContent = v.in_stock ? t('addToCart') : t('outOfStock');
				}
				addButton.disabled = !v.in_stock;
			}
		});
	}

	/* --------------------------------------------------------------
	 * Delivery estimate
	 * -------------------------------------------------------------- */

	function zonedNow(tz) {
		try {
			var parts = new Intl.DateTimeFormat('en-US', {
				timeZone: tz,
				year: 'numeric',
				month: 'numeric',
				day: 'numeric',
				hour: 'numeric',
				minute: 'numeric',
				hourCycle: 'h23'
			}).formatToParts(new Date());
			var o = {};
			parts.forEach(function (p) { o[p.type] = p.value; });
			return { y: +o.year, m: +o.month, d: +o.day, h: (+o.hour) % 24, mi: +o.minute };
		} catch (e) {
			return null;
		}
	}

	function initDelivery(el) {
		var cfg;
		try {
			cfg = JSON.parse(el.getAttribute('data-wahou-delivery'));
		} catch (e) {
			return;
		}
		if (!cfg || !cfg.tz || !cfg.i18n) {
			return;
		}
		var main = el.querySelector('[data-wahou-delivery-main]');
		var sub = el.querySelector('[data-wahou-delivery-sub]');
		var days = (cfg.days || [1, 2, 3, 4, 5]).map(function (d) { return d === 0 ? 7 : d; });

		function dayOf(date) {
			return date.getUTCDay() || 7;
		}

		function addDays(date, n) {
			return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + n));
		}

		function nextBusiness(date) {
			var d = addDays(date, 1);
			for (var i = 0; i < 14 && days.indexOf(dayOf(d)) === -1; i++) {
				d = addDays(d, 1);
			}
			return d;
		}

		function addBusiness(date, n) {
			var d = date;
			for (var i = 0; i < n; i++) {
				d = nextBusiness(d);
			}
			return d;
		}

		function render() {
			var now = zonedNow(cfg.tz);
			if (!now || !main) {
				return;
			}
			var cut = String(cfg.cutoff || '14:00').split(':');
			var cutoff = (parseInt(cut[0], 10) || 0) * 60 + (parseInt(cut[1], 10) || 0);
			var minutes = now.h * 60 + now.mi;
			var today = new Date(Date.UTC(now.y, now.m - 1, now.d));
			var ship = today;
			if (days.indexOf(dayOf(today)) === -1 || minutes >= cutoff) {
				ship = nextBusiness(today);
			}
			for (var i = 1; i < Math.max(1, cfg.processing || 1); i++) {
				ship = nextBusiness(ship);
			}
			var dMin = addBusiness(ship, cfg.min || 0);
			var dMax = addBusiness(ship, cfg.max || cfg.min || 0);
			var fmt;
			try {
				fmt = new Intl.DateTimeFormat(D.locale || undefined, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
			} catch (e) {
				return;
			}
			var text;
			if (ship.getTime() === today.getTime()) {
				var left = cutoff - minutes;
				var h = Math.floor(left / 60);
				var m = left % 60;
				text = sprintf(cfg.i18n.within, h > 0 ? sprintf(cfg.i18n.hm, h, m) : sprintf(cfg.i18n.m, m));
			} else if (ship.getTime() === addDays(today, 1).getTime()) {
				text = cfg.i18n.tomorrow;
			} else {
				text = sprintf(cfg.i18n.on, fmt.format(ship));
			}
			var range;
			if (dMin.getTime() === dMax.getTime()) {
				range = fmt.format(dMax);
			} else if (fmt.formatRange) {
				range = fmt.formatRange(dMin, dMax);
			} else {
				range = fmt.format(dMin) + ' – ' + fmt.format(dMax);
			}
			main.textContent = text;
			if (sub) {
				sub.textContent = sprintf(cfg.i18n.delivery, range);
				sub.hidden = false;
			}
		}

		render();
		setInterval(render, 60000);
	}

	/* --------------------------------------------------------------
	 * Frequently bought together
	 * -------------------------------------------------------------- */

	function initFbt(section) {
		var form = section.querySelector('form');
		var boxes = $$('[data-wahou-fbt-item]', section);
		var totalEl = section.querySelector('[data-wahou-fbt-total]');
		var regularEl = section.querySelector('[data-wahou-fbt-regular]');
		var mainEl = section.querySelector('[data-wahou-fbt-main]');
		var parent = parseInt(section.getAttribute('data-parent'), 10);
		var root = section.closest('[data-wahou-product]') || document;
		var mainForm = root.querySelector('form[data-wahou-atc][data-product-id="' + parent + '"]');

		function recompute() {
			var main = parseInt(section.getAttribute('data-main-price'), 10) || 0;
			var total = main;
			var regular = main;
			boxes.forEach(function (b) {
				if (b.checked) {
					total += parseInt(b.getAttribute('data-price'), 10) || 0;
					regular += parseInt(b.getAttribute('data-regular'), 10) || 0;
				}
			});
			if (totalEl) {
				totalEl.textContent = format(total);
			}
			if (regularEl) {
				regularEl.textContent = format(regular);
				regularEl.hidden = regular <= total;
			}
		}

		section.addEventListener('change', function (e) {
			if (e.target.matches('[data-wahou-fbt-item]')) {
				recompute();
			}
		});

		root.addEventListener('wahou:variant-change', function (e) {
			var v = e.detail && e.detail.variant;
			if (v) {
				section.setAttribute('data-main-price', v.price);
				if (mainEl) {
					mainEl.textContent = format(v.price);
				}
				recompute();
			}
		});

		if (!form) {
			return;
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var button = form.querySelector('[data-wahou-fbt-add]');
			var main = { product_id: parent, qty: 1 };
			if (mainForm && mainForm.hasAttribute('data-wahou-variants')) {
				var v = mainForm.querySelector('[data-wahou-variant-input]');
				if (!v || !v.value) {
					mainForm.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
					showFormError(mainForm, t('chooseOption'));
					return;
				}
				main.variant_id = v.value;
			}
			var ids = boxes.filter(function (b) { return b.checked; }).map(function (b) { return parseInt(b.value, 10); });
			setBusy(button, true);

			var current = state.cart ? Promise.resolve(state.cart) : api('cart');
			current.then(function (cart) {
				var already = (cart.items || []).some(function (i) {
					return i.product_id === parent && (!main.variant_id || i.variant_id === main.variant_id) && !i.is_bump;
				});
				var chain = already ? Promise.resolve() : addToCart(main, null, { silent: true, noHtml: ids.length > 0 });
				ids.forEach(function (id, k) {
					chain = chain.then(function () {
						return addToCart({ product_id: id, qty: 1, fbt_parent: parent }, null, { silent: true, noHtml: k < ids.length - 1 });
					});
				});
				return chain;
			}).then(function () {
				setBusy(button, false);
				afterAdd(state.cart);
			}).catch(function (err) {
				setBusy(button, false);
				toast(err.message, 'error');
			});
		});

		recompute();
	}

	/* --------------------------------------------------------------
	 * Misc: accordions targeted by hash, auto-submit selects, theme background
	 * -------------------------------------------------------------- */

	function openFromHash() {
		if (!location.hash || location.hash.length < 2) {
			return;
		}
		var target;
		try {
			target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		} catch (e) {
			return;
		}
		if (!target) {
			return;
		}
		var details = target.tagName === 'DETAILS' ? target : target.closest('details');
		if (details) {
			details.open = true;
			if (details !== target) {
				target.scrollIntoView({ block: 'start' });
			}
		}
	}

	function detectBackground() {
		function pick(el) {
			if (!el) {
				return null;
			}
			var c = getComputedStyle(el).backgroundColor;
			return c && c !== 'transparent' && !/^rgba\((\s*\d+\s*,){3}\s*0\s*\)$/.test(c) ? c : null;
		}
		var bg = pick(document.body) || pick(document.documentElement) || pick(document.querySelector('.wp-site-blocks'));
		if (bg) {
			document.documentElement.style.setProperty('--wahou-bg', bg);
		}
	}

	function onClick(e) {
		var toggle = e.target.closest('[data-wahou-cart-toggle]');
		if (toggle && drawerMode()) {
			e.preventDefault();
			drawer.open();
			return;
		}
		var step = e.target.closest('[data-wahou-qty-step]');
		if (step) {
			var wrap = step.closest('[data-wahou-qty]');
			var input = wrap && wrap.querySelector('input');
			if (input) {
				var min = parseInt(input.min || '1', 10);
				var max = input.max ? parseInt(input.max, 10) : Infinity;
				var value = (parseInt(input.value, 10) || min) + parseInt(step.getAttribute('data-wahou-qty-step'), 10);
				input.value = Math.max(min, Math.min(max, value));
				input.dispatchEvent(new Event('change', { bubbles: true }));
			}
			return;
		}
		var hashLink = e.target.closest('a[href^="#"]');
		if (hashLink && hashLink.getAttribute('href').length > 1) {
			var target = document.getElementById(hashLink.getAttribute('href').slice(1));
			var details = target && (target.tagName === 'DETAILS' ? target : target.closest('details'));
			if (details) {
				details.open = true;
			}
		}
	}

	function onChange(e) {
		if (e.target.matches && e.target.matches('select[data-wahou-autosubmit]') && e.target.form) {
			e.target.form.submit();
		}
	}

	/* --------------------------------------------------------------
	 * Boot
	 * -------------------------------------------------------------- */

	function init() {
		detectBackground();
		setCount(readCookieCount(), true);

		$$('form[data-wahou-variants]').forEach(initVariants);
		$$('[data-wahou-gallery]').forEach(initGallery);
		$$('[data-wahou-delivery]').forEach(initDelivery);
		$$('[data-wahou-product]').forEach(initSticky);
		$$('[data-wahou-fbt]').forEach(initFbt);
		openFromHash();

		var viewItem = document.querySelector('script[data-wahou-view-item]');
		if (viewItem) {
			try {
				track('view_item', JSON.parse(viewItem.textContent));
			} catch (e) { /* ignore */ }
		}

		try {
			var url = new URL(location.href);
			if (url.searchParams.get('wahou_open_cart')) {
				url.searchParams.delete('wahou_open_cart');
				history.replaceState(history.state, '', url.toString());
				drawer.open();
			}
		} catch (e) { /* ignore */ }

		window.addEventListener('hashchange', openFromHash);
	}

	document.addEventListener('submit', onAtcSubmit);
	document.addEventListener('click', onClick);
	document.addEventListener('change', onChange);
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && drawer.isOpen && !(drawer.el && drawer.el.contains(e.target))) {
			drawer.close();
		}
	});

	window.Wahou = {
		version: '1.0.0',
		api: api,
		format: format,
		track: track,
		toast: toast,
		sprintf: sprintf,
		addToCart: addToCart,
		cart: {
			open: function () { return drawer.open(); },
			close: function () { drawer.close(); },
			refresh: refresh,
			render: function (data) {
				if (isCart(data)) {
					applyCart(data);
				}
			},
			get: function () { return state.cart; }
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
