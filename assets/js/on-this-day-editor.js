/**
 * On This Day block editor registrations.
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
		! wp.blockEditor ||
		! wp.components
	) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const PanelBody = wp.components.PanelBody;
	const SelectControl = wp.components.SelectControl;
	const BLOCK = 'post-kinds-indieweb/on-this-day';
	const ENTRY = 'post-kinds-indieweb/on-this-day-entry';

	function headingControl( props ) {
		return el( SelectControl, {
			label: __(
				'Heading level',
				'post-kinds-for-indieweb-in-block-themes'
			),
			value: props.attributes.headingLevel,
			options: [ 2, 3, 4 ].map( ( value ) => ( {
				label: 'H' + value,
				value,
			} ) ),
			onChange: ( value ) =>
				props.setAttributes( { headingLevel: Number( value ) } ),
		} );
	}

	function CardEdit( props ) {
		const message =
			'single' === props.attributes.context
				? __(
						'On this day card for this post’s date',
						'post-kinds-for-indieweb-in-block-themes'
					)
				: __(
						'On this day: appears first in the Stream list on page 1',
						'post-kinds-for-indieweb-in-block-themes'
					);

		return el(
			'div',
			useBlockProps( { className: 'pkiw-otd-editor' } ),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{
						title: __(
							'On This Day',
							'post-kinds-for-indieweb-in-block-themes'
						),
					},
					el( SelectControl, {
						label: __(
							'Context',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: props.attributes.context,
						options: [
							{
								label: __(
									'Stream',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'stream',
							},
							{
								label: __(
									'Single post',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'single',
							},
						],
						onChange: ( value ) =>
							props.setAttributes( { context: value } ),
					} ),
					headingControl( props )
				)
			),
			el( 'p', null, message )
		);
	}

	function EntryEdit( props ) {
		return el(
			'div',
			useBlockProps( { className: 'pkiw-otd-editor' } ),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{
						title: __(
							'On This Day entry',
							'post-kinds-for-indieweb-in-block-themes'
						),
					},
					headingControl( props )
				)
			),
			el(
				'p',
				null,
				__(
					'On this day archive entry',
					'post-kinds-for-indieweb-in-block-themes'
				)
			)
		);
	}

	wp.blocks.registerBlockType( BLOCK, {
		apiVersion: 3,
		title: __( 'On This Day', 'post-kinds-for-indieweb-in-block-themes' ),
		category: 'post-kinds-indieweb',
		icon: 'backup',
		attributes: {
			context: {
				type: 'string',
				enum: [ 'single', 'stream' ],
				default: 'stream',
			},
			headingLevel: { type: 'integer', default: 3 },
		},
		supports: { html: false, reusable: false },
		edit: CardEdit,
		save() {
			return null;
		},
	} );

	wp.blocks.registerBlockType( ENTRY, {
		apiVersion: 3,
		title: __(
			'On This Day entry',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'calendar-alt',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		attributes: { headingLevel: { type: 'integer', default: 3 } },
		supports: { html: false, reusable: false },
		edit: EntryEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
