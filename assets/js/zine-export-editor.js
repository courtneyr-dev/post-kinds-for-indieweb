/**
 * Printable zine export editor registration.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender || ! wp.components ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const NAME = 'post-kinds-indieweb/zine-export';

	function ZineExportEdit( props ) {
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
		title: __(
			'Export as Zine',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'media-document',
		supports: { html: false, reusable: false },
		attributes: {
			source: {
				type: 'string',
				enum: [ 'archive', 'stream' ],
				default: 'archive',
			},
			defaultPages: { type: 'integer', enum: [ 8, 16 ], default: 8 },
		},
		edit: ZineExportEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
