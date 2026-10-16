/**
 * Exercise entry: editor registration.
 *
 * The block is server-rendered (see PKIW\Exercise_Archive::render()), so the
 * canvas prints the week the archive prints for the previewed workout. The
 * inspector sets how many whole weeks a page holds, the section heading
 * level and whether weeks get headings. There's no lines-per-page control:
 * the engine ignores it beside weeksPerPage.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const NAME = 'post-kinds-indieweb/exercise-entry';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const ToggleControl = wp.components && wp.components.ToggleControl;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;

	function controls( props ) {
		if ( ! InspectorControls || ! PanelBody ) {
			return null;
		}

		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __(
						'Exercise entries',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
				RangeControl &&
					el( RangeControl, {
						label: __(
							'Weeks per page',
							'post-kinds-for-indieweb-in-block-themes'
						),
						min: 1,
						max: 12,
						value: props.attributes.weeksPerPage,
						onChange( value ) {
							props.setAttributes( { weeksPerPage: value } );
						},
					} ),
				ToggleControl &&
					el( ToggleControl, {
						label: __(
							'Show week headings',
							'post-kinds-for-indieweb-in-block-themes'
						),
						checked: props.attributes.showSections,
						onChange( value ) {
							props.setAttributes( { showSections: value } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						label: __(
							'Heading level',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: props.attributes.headingLevel,
						options: [
							{ label: '2', value: 2 },
							{ label: '3', value: 3 },
							{ label: '4', value: 4 },
						],
						onChange( value ) {
							props.setAttributes( {
								headingLevel: parseInt( value, 10 ),
							} );
						},
					} )
			)
		);
	}

	function ExerciseEntryEdit( props ) {
		const blockProps = useBlockProps( {
			className: 'pkiw-exercise-entry-editor',
		} );
		const postId = props.context && props.context.postId;

		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				__(
					'Exercise entries: place this block inside a Query Loop’s Post Template.',
					'post-kinds-for-indieweb-in-block-themes'
				)
			);
		}

		return el(
			'div',
			blockProps,
			controls( props ),
			el( ServerSideRender, {
				block: NAME,
				attributes: props.attributes,
				urlQueryArgs: { post_id: postId, pkiw_kind: 'exercise' },
			} )
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __(
			'Exercise entries',
			'post-kinds-for-indieweb-in-block-themes'
		),
		description: __(
			'Whole weeks of workouts, one day cell for each day.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'calendar-alt',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		supports: { html: false, reusable: false },
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 0 },
			weeksPerPage: { type: 'integer', default: 4 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
		},
		edit: ExerciseEntryEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
