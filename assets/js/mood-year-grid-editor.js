/**
 * Mood Year Grid — editor registration.
 *
 * The block is server-rendered (see PKIW\Mood_Year_Grid::render()), so the
 * canvas prints the newest year of visible moods. It is wrapped in Disabled:
 * a click selects the block, it doesn't follow a link. The inspector sets
 * the heading level and whether the legend prints.
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
	const NAME = 'post-kinds-indieweb/mood-year-grid';
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const SelectControl = wp.components.SelectControl;
	const ToggleControl = wp.components.ToggleControl;
	const LEVELS = [ 2, 3, 4 ];

	function EmptyGrid() {
		return el(
			'p',
			{ className: 'pkiw-mood-year-editor__empty' },
			__(
				'No moods yet. The grid prints nothing until a published mood exists.',
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
						'Mood year grid',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
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
				ToggleControl &&
					el( ToggleControl, {
						label: __(
							'Show legend',
							'post-kinds-for-indieweb-in-block-themes'
						),
						checked: false !== attributes.showLegend,
						onChange( value ) {
							set( { showLegend: !! value } );
						},
					} )
			)
		);
	}

	function MoodYearGridEdit( props ) {
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
					EmptyResponsePlaceholder: EmptyGrid,
				} )
			)
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Mood Year Grid', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'A year of moods on a grid, one calendar year per page of the mood archive.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'calendar-alt',
		supports: { html: false, reusable: false },
		attributes: {
			headingLevel: { type: 'integer', default: 2 },
			showLegend: { type: 'boolean', default: true },
		},
		edit: MoodYearGridEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
