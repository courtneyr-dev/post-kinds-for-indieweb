/**
 * Read order — editor registration.
 *
 * The block is server-rendered (see PKIW\Read_Archive::render_order_block()),
 * so the canvas prints the same links the read archive does, with All
 * current. They are wrapped in Disabled: a click selects the block, it
 * doesn't follow the link.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender || ! wp.components ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const NAME = 'post-kinds-indieweb/read-order';

	function ReadOrderEdit( props ) {
		return el(
			'div',
			wp.blockEditor.useBlockProps(),
			el(
				wp.components.Disabled,
				null,
				el( wp.serverSideRender, {
					block: NAME,
					attributes: props.attributes,
				} )
			)
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Read order', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'Links that show the read archive on shelves by status, or A–Z by author.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'book',
		supports: { html: false, reusable: false },
		edit: ReadOrderEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
