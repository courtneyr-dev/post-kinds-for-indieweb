/**
 * Comic series: editor registration.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if (
		! wp ||
		! wp.blocks ||
		! wp.serverSideRender ||
		! wp.components ||
		! wp.blockEditor
	) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const NAME = 'post-kinds-indieweb/comic-series';

	function ComicSeriesEdit( props ) {
		return el(
			'div',
			wp.blockEditor.useBlockProps(),
			el(
				wp.blockEditor.InspectorControls,
				null,
				el(
					wp.components.PanelBody,
					{
						title: __(
							'Labels',
							'post-kinds-for-indieweb-in-block-themes'
						),
					},
					el( wp.components.TextControl, {
						label: __(
							'Navigation label',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: props.attributes.label,
						onChange: ( label ) => props.setAttributes( { label } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
					el( wp.components.TextControl, {
						label: __(
							'All series label',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: props.attributes.allLabel,
						onChange: ( allLabel ) =>
							props.setAttributes( { allLabel } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} )
				)
			),
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
		title: __( 'Comic series', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'Links that filter drawn comics by series.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'index-card',
		attributes: {
			label: {
				type: 'string',
				default: __(
					'Series',
					'post-kinds-for-indieweb-in-block-themes'
				),
			},
			allLabel: {
				type: 'string',
				default: __(
					'All series',
					'post-kinds-for-indieweb-in-block-themes'
				),
			},
		},
		supports: { html: false, reusable: false },
		edit: ComicSeriesEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
