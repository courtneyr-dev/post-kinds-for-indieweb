/**
 * Almanac month — editor registration.
 *
 * The block is server-rendered (see PKIW\Almanac_Month::render()), so the
 * canvas prints the newest month of weather posts the archive's first page
 * prints. The preview sits in Disabled: a click selects the block, it
 * doesn't follow a link. The inspector sets the heading level and whether
 * the year strip shows.
 *
 * The block sits in a taxonomy-kind-<slug> template; that slug rides the
 * preview request as `pkiw_kind`, the way the menu entry sends it.
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
	const NAME = 'post-kinds-indieweb/almanac-month';
	const PREFIX = 'taxonomy-kind-';
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const SelectControl = wp.components.SelectControl;
	const ToggleControl = wp.components.ToggleControl;
	const LEVELS = [ 2, 3, 4 ];

	// The kind a taxonomy-kind-<slug> template is the archive of, or ''.
	function templateKind( slug ) {
		return 'string' === typeof slug && 0 === slug.indexOf( PREFIX )
			? slug.slice( PREFIX.length )
			: '';
	}

	function EmptyMonth() {
		return el(
			'p',
			{ className: 'pkiw-almanac-editor__empty' },
			__(
				'No weather posts yet. The almanac prints nothing until a weather post is published.',
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
						'Almanac month',
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
							'Show year strip',
							'post-kinds-for-indieweb-in-block-themes'
						),
						checked: false !== attributes.showYearStrip,
						onChange( value ) {
							set( { showYearStrip: !! value } );
						},
					} )
			)
		);
	}

	function AlmanacMonthEdit( props ) {
		const blockProps = wp.blockEditor.useBlockProps();
		const kind = templateKind(
			props.context ? props.context.templateSlug : ''
		);

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
					urlQueryArgs: kind ? { pkiw_kind: kind } : {},
					EmptyResponsePlaceholder: EmptyMonth,
				} )
			)
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Almanac month', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'One calendar month of weather posts, a day at a time, with a strip of the year’s months.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'calendar-alt',
		supports: { html: false, reusable: false },
		usesContext: [ 'templateSlug' ],
		edit: AlmanacMonthEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
