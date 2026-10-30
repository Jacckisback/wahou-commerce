/**
 * Wahou Commerce – orders, dashboard and carts screens.
 * Confirmations, tracking link builder and copy-to-clipboard. Vanilla JS.
 */
( function () {
	'use strict';

	var config = window.wahouAdminOrders || {};
	var i18n = config.i18n || {};
	var carriers = config.carriers || {};

	// Confirm destructive or important actions.
	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest ? event.target.closest( '[data-wahou-confirm], [data-wahou-confirm-delete]' ) : null;
		if ( ! trigger ) {
			return;
		}
		var message = trigger.getAttribute( 'data-wahou-confirm' ) || i18n.confirmDelete;
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	// Refund: show the amount in the confirmation.
	document.querySelectorAll( '[data-wahou-refund]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var input = form.querySelector( '[name="amount"]' );
			var amount = input ? input.value.trim() + ' ' + ( input.getAttribute( 'data-currency' ) || '' ) : '';
			if ( ! window.confirm( String( i18n.confirmRefund || '%s' ).replace( '%s', amount.trim() ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// Bulk actions on the orders list.
	var listForm = document.querySelector( '.wahou-ap-orders-form' );
	if ( listForm ) {
		listForm.addEventListener( 'submit', function ( event ) {
			var submitter = event.submitter;
			if ( submitter && 'filter_action' === submitter.name ) {
				return;
			}
			var top = listForm.querySelector( '#bulk-action-selector-top' );
			var bottom = listForm.querySelector( '#bulk-action-selector-bottom' );
			var action = ( top && '-1' !== top.value ) ? top.value : ( bottom && '-1' !== bottom.value ? bottom.value : '' );
			var checked = listForm.querySelectorAll( 'input[name="order_ids[]"]:checked' ).length;
			if ( action && checked && ! window.confirm( i18n.confirmBulk || '' ) ) {
				event.preventDefault();
			}
		} );
	}

	// Tracking link builder.
	document.querySelectorAll( '[data-wahou-tracking]' ).forEach( function ( form ) {
		var carrier = form.querySelector( '[name="carrier"]' );
		var number = form.querySelector( '[name="tracking_number"]' );
		var url = form.querySelector( '[name="tracking_url"]' );
		if ( ! carrier || ! number || ! url ) {
			return;
		}
		var auto = ! url.value;

		function build() {
			var template = carriers[ carrier.value ] ? carriers[ carrier.value ].url : '';
			if ( ! auto ) {
				return;
			}
			url.value = template && number.value.trim() ? template.replace( '{n}', encodeURIComponent( number.value.trim() ) ) : '';
		}

		url.addEventListener( 'input', function () {
			auto = '' === url.value;
		} );
		carrier.addEventListener( 'change', function () {
			auto = true;
			build();
		} );
		number.addEventListener( 'input', build );
	} );

	// Copy to clipboard.
	document.addEventListener( 'click', function ( event ) {
		var code = event.target.closest ? event.target.closest( '.wahou-ap-copy' ) : null;
		if ( ! code || ! navigator.clipboard ) {
			return;
		}
		navigator.clipboard.writeText( code.getAttribute( 'data-copy' ) || code.textContent ).then( function () {
			code.classList.add( 'is-copied' );
			code.setAttribute( 'title', i18n.copied || '' );
			window.setTimeout( function () {
				code.classList.remove( 'is-copied' );
			}, 1200 );
		} );
	} );
}() );
