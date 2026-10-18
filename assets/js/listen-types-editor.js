/**
 * Listen types: editor registration.
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
	const NAME = 'post-kinds-indieweb/listen-types';

	function ListenTypesEdit( props ) {
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
		title: __( 'Listen types', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'Links to all listens and podcast listens.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'filter',
		supports: { html: false, reusable: false },
		edit: ListenTypesEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
