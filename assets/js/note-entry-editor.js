/**
 * Note entry: editor registration.
 *
 * The block is server-rendered (see includes/class-note-archive.php). The
 * editor asks the block-renderer endpoint for the loop post's note, so the
 * canvas shows the same name, thought and date the archive prints.
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
	const NAME = 'post-kinds-indieweb/note-entry';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const SelectControl = wp.components && wp.components.SelectControl;

	// The kind a taxonomy-kind-<slug> template is the archive of, or ''.
	function templateKind( slug ) {
		return 'string' === typeof slug &&
			0 === slug.indexOf( 'taxonomy-kind-' )
			? slug.slice( 'taxonomy-kind-'.length )
			: '';
	}

	function controls( props ) {
		return InspectorControls && PanelBody && SelectControl
			? el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __(
								'Note entry',
								'post-kinds-for-indieweb-in-block-themes'
							),
						},
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
								{ label: '5', value: 5 },
							],
							onChange( value ) {
								props.setAttributes( {
									headingLevel: parseInt( value, 10 ),
								} );
							},
						} )
					)
			  )
			: null;
	}

	function NoteEntryEdit( props ) {
		const blockProps = useBlockProps( {
			className: 'pkiw-note-entry-editor',
		} );
		const postId = props.context && props.context.postId;
		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				__(
					'Note entry: place this block inside a Query Loop’s Post Template.',
					'post-kinds-for-indieweb-in-block-themes'
				)
			);
		}

		// A post can hold several kinds; preview it as the template's kind.
		const kind = templateKind( props.context.templateSlug );

		return el(
			'div',
			blockProps,
			controls( props ),
			el( ServerSideRender, {
				block: NAME,
				attributes: props.attributes,
				urlQueryArgs: kind
					? { post_id: postId, pkiw_kind: kind }
					: { post_id: postId },
			} )
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Note entry', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'One note for the current post: its name, a short thought and the date. Use inside a Query Loop.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'format-aside',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		supports: { html: false, reusable: false },
		attributes: {
			headingLevel: { type: 'integer', default: 3 },
		},
		edit: NoteEntryEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
