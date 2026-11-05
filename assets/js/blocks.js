/**
 * Card to card in the WooCommerce block checkout. Plain script (no build step): registers the
 * payment method with its title, description and place-order button text from the plugin panel.
 */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement: el } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const data = getSetting( 'card_to_card_data', {} );
	const title = decodeEntities( data.title || 'کارت به کارت' );

	/** Description under the selected method. */
	const Content = () => el( 'div', { className: 'ctc-blocks-desc' }, decodeEntities( data.description || '' ) );

	/** Label with a small card icon. */
	const Label = ( props ) => {
		const { PaymentMethodLabel } = props.components;
		return el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
			el(
				'svg',
				{ viewBox: '0 0 24 24', width: 22, height: 22, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, 'aria-hidden': true },
				el( 'rect', { x: 2.5, y: 5, width: 19, height: 14, rx: 3 } ),
				el( 'path', { d: 'M2.5 10h19M6.5 15h4' } )
			),
			el( PaymentMethodLabel, { text: title } )
		);
	};

	registerPaymentMethod( {
		name: 'card_to_card',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: () => true,
		ariaLabel: title,
		placeOrderButtonLabel: decodeEntities( data.button || '' ) || undefined,
		supports: { features: data.supports || [ 'products' ] },
	} );
}() );
