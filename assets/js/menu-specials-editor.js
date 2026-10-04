/**
 * Recent Specials — editor registration.
 *
 * The block is server-rendered (see PKIW\Kind_Archive_Layouts::render_menu_specials()),
 * so the canvas prints the specials the menu's first page prints. They are
 * wrapped in Disabled: a click selects the block, it doesn't follow a link.
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
	const NAME = 'post-kinds-indieweb/menu-specials';
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const RangeControl = wp.components.RangeControl;
	const ToggleControl = wp.components.ToggleControl;

	function MenuSpecialsEdit( props ) {
		const blockProps = wp.blockEditor.useBlockProps();
		const controls =
			InspectorControls && PanelBody && RangeControl && ToggleControl
				? el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{
								title: __(
									'Recent Specials',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
							el( RangeControl, {
								label: __(
									'How many',
									'post-kinds-for-indieweb-in-block-themes'
								),
								min: 1,
								max: 6,
								value: props.attributes.count,
								onChange( value ) {
									props.setAttributes( { count: value } );
								},
							} ),
							el( ToggleControl, {
								label: __(
									'Show photos',
									'post-kinds-for-indieweb-in-block-themes'
								),
								checked: props.attributes.showPhotos,
								onChange( value ) {
									props.setAttributes( {
										showPhotos: value,
									} );
								},
							} )
						)
				  )
				: null;

		return el(
			'div',
			blockProps,
			controls,
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
			'Recent Specials',
			'post-kinds-for-indieweb-in-block-themes'
		),
		description: __(
			'The newest eat or drink posts, for the top of a menu archive.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'star-filled',
		supports: { html: false, reusable: false },
		edit: MenuSpecialsEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
