/**
 * Kind menu entry — editor registration.
 *
 * The block is server-rendered (see includes/class-kind-archive-layouts.php).
 * A ServerSideRender edit component asks the block-renderer endpoint to
 * render the menu line for the loop post, so the canvas matches the front
 * end, privacy rule included.
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
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const ToggleControl = wp.components && wp.components.ToggleControl;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;
	const TextControl = wp.components && wp.components.TextControl;

	function MenuEntryEdit( props ) {
		const blockProps = useBlockProps( {
			className: 'pkiw-menu-entry-editor',
		} );
		return renderEdit( props, blockProps );
	}

	wp.blocks.registerBlockType( 'post-kinds-indieweb/menu-entry', {
		apiVersion: 3,
		title: __(
			'Kind menu entry',
			'post-kinds-for-indieweb-in-block-themes'
		),
		description: __(
			'A menu line for the current post: name, leader, rating, venue and date. Use inside a Query Loop.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'list-view',
		usesContext: [ 'postId', 'postType', 'queryId' ],
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 0 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
		},
		supports: { html: false, reusable: false },
		ancestor: [ 'core/post-template' ],
		edit: MenuEntryEdit,
		save() {
			return null;
		},
	} );

	function renderEdit( props, blockProps ) {
		const postId = props.context && props.context.postId;
		const controls =
			InspectorControls && PanelBody && ToggleControl
				? el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{
								title: __(
									'Menu',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
							el( ToggleControl, {
								label: __(
									'Show section headings',
									'post-kinds-for-indieweb-in-block-themes'
								),
								checked: props.attributes.showSections,
								onChange( value ) {
									props.setAttributes( {
										showSections: value,
									} );
								},
							} ),
							RangeControl &&
								el( RangeControl, {
									label: __(
										'Lines per page',
										'post-kinds-for-indieweb-in-block-themes'
									),
									help: __(
										'On the kind archive. 0 uses the site’s Reading setting.',
										'post-kinds-for-indieweb-in-block-themes'
									),
									min: 0,
									max: 50,
									value: props.attributes.linesPerPage,
									onChange( value ) {
										props.setAttributes( {
											linesPerPage: value || 0,
										} );
									},
								} ),
							SelectControl &&
								props.attributes.showSections &&
								el( SelectControl, {
									label: __(
										'Section order',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: props.attributes.sectionOrder,
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
										props.setAttributes( {
											sectionOrder: value,
										} );
									},
								} ),
							SelectControl &&
								props.attributes.showSections &&
								el( SelectControl, {
									label: __(
										'Posts with no cuisine or drink type',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: props.attributes.emptyGroup,
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
										props.setAttributes( {
											emptyGroup: value,
										} );
									},
								} ),
							TextControl &&
								props.attributes.showSections &&
								el( TextControl, {
									label: __(
										'Heading for those posts',
										'post-kinds-for-indieweb-in-block-themes'
									),
									help: __(
										'Empty uses “Other”.',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: props.attributes.emptyLabel,
									onChange( value ) {
										props.setAttributes( {
											emptyLabel: value,
										} );
									},
								} )
						)
				  )
				: null;

		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				__(
					'Kind menu entry: place this block inside a Query Loop’s Post Template.',
					'post-kinds-for-indieweb-in-block-themes'
				)
			);
		}

		return el(
			'div',
			blockProps,
			controls,
			el( ServerSideRender, {
				block: 'post-kinds-indieweb/menu-entry',
				attributes: props.attributes,
				urlQueryArgs: { post_id: postId },
			} )
		);
	}
} )( window.wp );
