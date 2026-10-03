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
