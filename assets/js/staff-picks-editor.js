/**
 * Staff Picks — editor registration.
 *
 * The block is server-rendered (see PKIW\Staff_Picks::render()), so the
 * canvas prints the picks the archive's first page prints. They are wrapped
 * in Disabled: a click selects the block, it doesn't follow a link. The
 * inspector sets the heading text, the heading level and how many picks.
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
	const NAME = 'post-kinds-indieweb/staff-picks';
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const RangeControl = wp.components.RangeControl;
	const SelectControl = wp.components.SelectControl;
	const TextControl = wp.components.TextControl;
	const LEVELS = [ 2, 3, 4, 5 ];

	function EmptyPicks() {
		return el(
			'p',
			{ className: 'pkiw-staff-picks-editor__empty' },
			__(
				'No rated board game plays yet. Staff Picks prints nothing until a published board game play has a rating.',
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
						'Staff Picks',
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
							'Staff Picks',
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

	function StaffPicksEdit( props ) {
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
					EmptyResponsePlaceholder: EmptyPicks,
				} )
			)
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Staff Picks', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'The top-rated board game plays, one per game, on the first page of an archive.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'star-filled',
		supports: { html: false, reusable: false },
		edit: StaffPicksEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
