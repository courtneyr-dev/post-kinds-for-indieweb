/**
 * Four Stars — editor registration.
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
	const NAME = 'post-kinds-indieweb/four-stars';
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const RangeControl = wp.components.RangeControl;
	const SelectControl = wp.components.SelectControl;
	const TextControl = wp.components.TextControl;
	const LEVELS = [ 2, 3, 4 ];

	function EmptyFourStars() {
		return el(
			'p',
			{ className: 'pkiw-four-stars-editor__empty' },
			__(
				'No review is rated four out of five yet. Four Stars prints nothing until a published review has a 4 of 5 rating.',
				'post-kinds-for-indieweb-in-block-themes'
			)
		);
	}

	function controls( props ) {
		const attributes = props.attributes;
		const set = props.setAttributes;

		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __(
						'Four Stars',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
				TextControl &&
					el( TextControl, {
						label: __(
							'Heading',
							'post-kinds-for-indieweb-in-block-themes'
						),
						placeholder: __(
							'Four Stars',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.heading || '',
						onChange( value ) {
							set( { heading: value } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						label: __(
							'Heading level',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: String( attributes.headingLevel || 2 ),
						options: LEVELS.map( function ( level ) {
							return {
								label: 'H' + level,
								value: String( level ),
							};
						} ),
						onChange( value ) {
							set( { headingLevel: parseInt( value, 10 ) } );
						},
					} ),
				RangeControl &&
					el( RangeControl, {
						label: __(
							'How many',
							'post-kinds-for-indieweb-in-block-themes'
						),
						min: 1,
						max: 6,
						value: attributes.count,
						onChange( value ) {
							set( { count: value } );
						},
					} )
			)
		);
	}

	function FourStarsEdit( props ) {
		const blockProps = wp.blockEditor.useBlockProps();

		return el(
			'div',
			blockProps,
			InspectorControls && PanelBody ? controls( props ) : null,
			el(
				wp.components.Disabled,
				null,
				el( wp.serverSideRender, {
					block: NAME,
					attributes: props.attributes,
					EmptyResponsePlaceholder: EmptyFourStars,
				} )
			)
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Four Stars', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'The newest reviews rated four out of five, on the first page of the review archive.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'star-filled',
		supports: { html: false, reusable: false },
		edit: FourStarsEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
