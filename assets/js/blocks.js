/*!
 * Wahou Commerce – block editor UI (no build step).
 * Blocks are registered server side (title, attributes, render callbacks);
 * this file only provides the editor controls and live previews.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var be = wp.blockEditor || {};
	var c = wp.components || {};
	var InspectorControls = be.InspectorControls;
	var useBlockProps = be.useBlockProps;
	var ServerSideRender = wp.serverSideRender;
	var useSelect = wp.data && wp.data.useSelect;
	var cfg = window.wahouBlocks || { i18n: {}, orderby: {} };
	var L = cfg.i18n || {};

	function blockProps(extra) {
		return useBlockProps ? useBlockProps(extra || {}) : (extra || {});
	}

	function preview(name, attributes) {
		if (!ServerSideRender) {
			return el('p', null, name);
		}
		return el(c.Disabled || 'div', null, el(ServerSideRender, { block: name, attributes: attributes, httpMethod: 'POST' }));
	}

	function useProducts() {
		if (!useSelect) {
			return null;
		}
		return useSelect(function (select) {
			return select('core').getEntityRecords('postType', 'wahou_product', { per_page: 100, status: 'publish', orderby: 'title', order: 'asc', _fields: 'id,title' });
		}, []);
	}

	function useCategories() {
		if (!useSelect) {
			return null;
		}
		return useSelect(function (select) {
			return select('core').getEntityRecords('taxonomy', 'wahou_product_cat', { per_page: 100, hide_empty: false, _fields: 'id,name,slug' });
		}, []);
	}

	function productOptions(products) {
		var options = [{ value: 0, label: '— ' + (L.chooseProduct || 'Choose a product') + ' —' }];
		(products || []).forEach(function (p) {
			var title = p.title && (p.title.rendered || p.title.raw);
			options.push({ value: p.id, label: (title || '#' + p.id).replace(/<[^>]+>/g, '') + ' (#' + p.id + ')' });
		});
		return options;
	}

	function ProductPicker(props) {
		var products = useProducts();
		if (products === null && !c.TextControl) {
			return null;
		}
		if (!products) {
			return el(c.TextControl, {
				label: L.product || 'Product',
				type: 'number',
				value: props.value || '',
				onChange: function (v) { props.onChange(parseInt(v, 10) || 0); }
			});
		}
		return el(c.SelectControl, {
			label: L.product || 'Product',
			value: props.value || 0,
			options: productOptions(products),
			onChange: function (v) { props.onChange(parseInt(v, 10) || 0); }
		});
	}

	function placeholder(icon, label, instructions) {
		if (!c.Placeholder) {
			return el('p', null, instructions);
		}
		return el(c.Placeholder, { icon: icon, label: label, instructions: instructions });
	}

	function register(name, settings) {
		var existing = wp.blocks.getBlockType && wp.blocks.getBlockType(name);
		if (existing) {
			return;
		}
		settings.save = function () { return null; };
		wp.blocks.registerBlockType(name, settings);
	}

	/* Products grid */
	register('wahou/products', {
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var categories = useCategories();
			var catOptions = [{ value: '', label: L.allCategories || 'All categories' }];
			(categories || []).forEach(function (t) { catOptions.push({ value: t.slug, label: t.name }); });
			var orderOptions = Object.keys(cfg.orderby || {}).map(function (k) { return { value: k, label: cfg.orderby[k] }; });

			return el(Fragment, null,
				InspectorControls && el(InspectorControls, null,
					el(c.PanelBody, { title: L.settings || 'Settings', initialOpen: true },
						el(c.SelectControl, { label: L.category, value: a.category, options: catOptions, onChange: function (v) { set({ category: v }); } }),
						el(c.TextControl, { label: L.ids, help: L.idsHelp, value: a.ids, onChange: function (v) { set({ ids: v.replace(/[^0-9,]/g, '') }); } }),
						el(c.RangeControl, { label: L.limit, min: 1, max: 48, value: a.limit, onChange: function (v) { set({ limit: v }); } }),
						el(c.RangeControl, { label: L.columns, min: 1, max: 6, value: a.columns, onChange: function (v) { set({ columns: v }); } }),
						orderOptions.length ? el(c.SelectControl, { label: L.orderby, value: a.orderby, options: orderOptions, onChange: function (v) { set({ orderby: v }); } }) : null,
						el(c.ToggleControl, { label: L.filters, checked: !!a.filters, onChange: function (v) { set({ filters: v }); } }),
						el(c.ToggleControl, { label: L.pagination, checked: !!a.pagination, onChange: function (v) { set({ pagination: v }); } })
					)
				),
				el('div', blockProps(), preview('wahou/products', a))
			);
		}
	});

	/* Product spotlight */
	register('wahou/product', {
		edit: function (props) {
			var a = props.attributes;
			return el(Fragment, null,
				InspectorControls && el(InspectorControls, null,
					el(c.PanelBody, { title: L.settings || 'Settings', initialOpen: true },
						el(ProductPicker, { value: a.id, onChange: function (v) { props.setAttributes({ id: v }); } })
					)
				),
				el('div', blockProps(), a.id ? preview('wahou/product', a) : el('div', null,
					placeholder('products', L.product, L.noProduct),
					el(ProductPicker, { value: a.id, onChange: function (v) { props.setAttributes({ id: v }); } })
				))
			);
		}
	});

	/* Add to cart & buy now buttons */
	function buttonEdit(name, withPrice) {
		return function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var controls = [
				el(ProductPicker, { key: 'p', value: a.id, onChange: function (v) { set({ id: v }); } }),
				el(c.TextControl, { key: 'l', label: L.label, value: a.label, onChange: function (v) { set({ label: v }); } })
			];
			if (withPrice) {
				controls.push(el(c.ToggleControl, { key: 's', label: L.showPrice, checked: !!a.showPrice, onChange: function (v) { set({ showPrice: v }); } }));
			} else {
				controls.push(el('p', { key: 'h', className: 'components-base-control__help' }, L.buyHelp));
			}
			return el(Fragment, null,
				InspectorControls && el(InspectorControls, null, el(c.PanelBody, { title: L.settings || 'Settings', initialOpen: true }, controls)),
				el('div', blockProps(), a.id ? preview(name, a) : el('div', null,
					placeholder('cart', L.product, L.noProduct),
					el(ProductPicker, { value: a.id, onChange: function (v) { set({ id: v }); } })
				))
			);
		};
	}

	register('wahou/add-to-cart', { edit: buttonEdit('wahou/add-to-cart', true) });
	register('wahou/buy-button', { edit: buttonEdit('wahou/buy-button', false) });

	/* Cart icon */
	register('wahou/cart-icon', {
		edit: function (props) {
			var a = props.attributes;
			return el(Fragment, null,
				InspectorControls && el(InspectorControls, null,
					el(c.PanelBody, { title: L.settings || 'Settings', initialOpen: true },
						el(c.ToggleControl, { label: L.showLabel, checked: !!a.showLabel, onChange: function (v) { props.setAttributes({ showLabel: v }); } })
					)
				),
				el('div', blockProps(), preview('wahou/cart-icon', a))
			);
		}
	});

	/* Transactional pages: static placeholders (live UI depends on the visitor). */
	register('wahou/cart', {
		edit: function () { return el('div', blockProps(), placeholder('cart', 'Wahou Commerce', L.cart)); }
	});
	register('wahou/checkout', {
		edit: function () { return el('div', blockProps(), placeholder('yes-alt', 'Wahou Commerce', L.checkout)); }
	});
	register('wahou/account', {
		edit: function () { return el('div', blockProps(), placeholder('admin-users', 'Wahou Commerce', L.account)); }
	});
})(window.wp);
