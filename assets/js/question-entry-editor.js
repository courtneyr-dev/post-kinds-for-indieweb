/**
 * Question entry editor registration.
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
	const NAME = 'post-kinds-indieweb/question-entry';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const ToggleControl = wp.components && wp.components.ToggleControl;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;
	const TextControl = wp.components && wp.components.TextControl;
	const localized = window.pkiwQuestionEntry || {};
	const groups = localized.groups || {};

	function QuestionEntryEdit( props ) {
		return renderEdit(
			props,
			useBlockProps( { className: 'pkiw-question-entry-editor' } )
		);
	}

	function controls( props ) {
		const attributes = props.attributes;
		const set = props.setAttributes;

		if ( ! InspectorControls || ! PanelBody ) {
			return null;
		}
		const groupLabels = Object.assign( {}, groups, attributes.groupLabels );

		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __(
						'Sections',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
				RangeControl &&
					el( RangeControl, {
						label: __(
							'Lines per page',
							'post-kinds-for-indieweb-in-block-themes'
						),
						min: 1,
						max: 50,
						value: attributes.linesPerPage,
						onChange( value ) {
							set( { linesPerPage: value } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						label: __(
							'Section order',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.sectionOrder,
						options: [
							{
								label: __(
									'Answered first',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'asc',
							},
							{
								label: __(
									'Waiting first',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'desc',
							},
						],
						onChange( value ) {
							set( { sectionOrder: value } );
						},
					} ),
				ToggleControl &&
					el( ToggleControl, {
						label: __(
							'Show section headings',
							'post-kinds-for-indieweb-in-block-themes'
						),
						checked: attributes.showSections,
						onChange( value ) {
							set( { showSections: value } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						label: __(
							'Questions with no status',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.emptyGroup,
						options: [
							{
								label: __(
									'After the other sections',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'last',
							},
							{
								label: __(
									'Before the other sections',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'first',
							},
						],
						onChange( value ) {
							set( { emptyGroup: value } );
						},
					} ),
				TextControl &&
					el( TextControl, {
						label: __(
							'Questions with no status',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.emptyLabel,
						onChange( value ) {
							set( { emptyLabel: value } );
						},
					} )
			),
			el(
				PanelBody,
				{
					title: __(
						'Section headings',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
				Object.keys( groups ).map( ( key ) =>
					TextControl
						? el( TextControl, {
								key,
								label: groups[ key ],
								value: groupLabels[ key ],
								onChange( value ) {
									set( {
										groupLabels: Object.assign(
											{},
											groupLabels,
											{ [ key ]: value }
										),
									} );
								},
						  } )
						: null
				)
			)
		);
	}

	function renderEdit( props, blockProps ) {
		const postId = props.context && props.context.postId;

		return el(
			'div',
			blockProps,
			controls( props ),
			el( ServerSideRender, {
				block: NAME,
				attributes: props.attributes,
				urlQueryArgs: { post_id: postId, pkiw_kind: 'question' },
			} )
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __(
			'Question entry',
			'post-kinds-for-indieweb-in-block-themes'
		),
		description: __(
			'A question title for the current post. Use inside a Query Loop.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'editor-help',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 12 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
			groupLabels: { type: 'object', default: {} },
		},
		supports: { html: false, reusable: false },
		ancestor: [ 'core/post-template' ],
		edit: QuestionEntryEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
