/**
 * Event calendar editor registration.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if (
		! wp ||
		! wp.blocks ||
		! wp.element ||
		! wp.i18n ||
		! wp.blockEditor ||
		! wp.components ||
		! wp.serverSideRender
	) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const Disabled = wp.components.Disabled;
	const PanelBody = wp.components.PanelBody;
	const SelectControl = wp.components.SelectControl;
	const TextControl = wp.components.TextControl;
	const ServerSideRender = wp.serverSideRender;
	const BLOCK = 'post-kinds-indieweb/event-calendar';

	wp.blocks.registerBlockType( BLOCK, {
		apiVersion: 3,
		title: __(
			'Event calendar',
			'post-kinds-for-indieweb-in-block-themes'
		),
		icon: 'calendar-alt',
		category: 'post-kinds-indieweb',
		supports: { html: false, reusable: false },
		attributes: {
			format: {
				type: 'string',
				enum: [ 'calendar', 'list', 'mini' ],
				default: 'calendar',
			},
			label: {
				type: 'string',
				default: __(
					'Event calendar',
					'post-kinds-for-indieweb-in-block-themes'
				),
				role: 'content',
			},
		},
		edit: function EventCalendarEdit( props ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __(
								'Calendar settings',
								'post-kinds-for-indieweb-in-block-themes'
							),
						},
						el( SelectControl, {
							label: __(
								'Format',
								'post-kinds-for-indieweb-in-block-themes'
							),
							value: props.attributes.format,
							options: [
								{
									label: __(
										'Calendar',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: 'calendar',
								},
								{
									label: __(
										'List',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: 'list',
								},
								{
									label: __(
										'Mini calendar',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: 'mini',
								},
							],
							onChange: ( value ) =>
								props.setAttributes( { format: value } ),
						} ),
						el( TextControl, {
							label: __(
								'Accessible label',
								'post-kinds-for-indieweb-in-block-themes'
							),
							value: props.attributes.label,
							onChange: ( value ) =>
								props.setAttributes( { label: value } ),
						} )
					)
				),
				el(
					Disabled,
					null,
					el( ServerSideRender, {
						block: BLOCK,
						attributes: props.attributes,
					} )
				)
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );
