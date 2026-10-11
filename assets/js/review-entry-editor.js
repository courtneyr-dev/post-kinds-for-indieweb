/**
 * Review entry — editor registration.
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
	const NAME = 'post-kinds-indieweb/review-entry';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const ToggleControl = wp.components && wp.components.ToggleControl;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;
	const TextControl = wp.components && wp.components.TextControl;

	function ReviewEntryEdit( props ) {
		return renderEdit(
			props,
			useBlockProps( { className: 'pkiw-review-entry-editor' } )
		);
	}

	function controls( props ) {
		const attributes = props.attributes;
		const set = props.setAttributes;

		return InspectorControls && PanelBody && ToggleControl
			? el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __(
								'Reviews',
								'post-kinds-for-indieweb-in-block-themes'
							),
						},
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
							attributes.showSections &&
							el( SelectControl, {
								label: __(
									'Section order',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: attributes.sectionOrder,
								options: [
									{
										label: __(
											'A to Z',
											'post-kinds-for-indieweb-in-block-themes'
										),
										value: 'asc',
									},
									{
										label: __(
											'Z to A',
											'post-kinds-for-indieweb-in-block-themes'
										),
										value: 'desc',
									},
								],
								onChange( value ) {
									set( { sectionOrder: value } );
								},
							} ),
						SelectControl &&
							attributes.showSections &&
							el( SelectControl, {
								label: __(
									'Reviews with no category',
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
							attributes.showSections &&
							el( TextControl, {
								label: __(
									'Heading for those reviews',
									'post-kinds-for-indieweb-in-block-themes'
								),
								help: __(
									'Empty uses “Other”.',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: attributes.emptyLabel,
								onChange( value ) {
									set( { emptyLabel: value } );
								},
							} )
					)
				)
			: null;
	}

	function renderEdit( props, blockProps ) {
		const postId = props.context && props.context.postId;
		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				__(
					'Review entry: place this block inside a Query Loop’s Post Template.',
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
				urlQueryArgs: { post_id: postId, pkiw_kind: 'review' },
			} )
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Review entry', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'A review for the current post: title, item and cover. Use inside a Query Loop.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'star-filled',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 9 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
		},
		supports: { html: false, reusable: false },
		ancestor: [ 'core/post-template' ],
		edit: ReviewEntryEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
