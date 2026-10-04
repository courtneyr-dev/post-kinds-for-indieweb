/**
 * Stream card — editor registration.
 *
 * The block is server-rendered (see includes/functions-stream-card.php). Until
 * 1.8.1 nothing registered it on the editor side, so a Query Loop that carries
 * it showed "Your site doesn't include support for the … block" in the canvas.
 * This registers the same block name with a ServerSideRender edit component:
 * the editor asks the block-renderer endpoint to render the card for the loop
 * post (post_id → postId/postType context), so the canvas shows the real card,
 * including theme adapters hooked on render_block_post-kinds-indieweb/stream-card.
 * The block's own attributes go with the request, so the heading level and a
 * block style chosen for the card apply in the canvas as they do on the front end.
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

	function Placeholder( props ) {
		return el(
			'div',
			{ className: 'pkiw-stream-card-editor__placeholder' },
			props.children
		);
	}

	function StreamCardEdit( props ) {
		const blockProps = useBlockProps( {
			className: 'pkiw-stream-card-editor',
		} );
		const postId = props.context && props.context.postId;

		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					null,
					__(
						'Stream card: place this block inside a Query Loop’s Post Template.',
						'post-kinds-for-indieweb-in-block-themes'
					)
				)
			);
		}

		return el(
			'div',
			blockProps,
			el( ServerSideRender, {
				block: 'post-kinds-indieweb/stream-card',
				attributes: props.attributes,
				urlQueryArgs: { post_id: postId },
				LoadingResponsePlaceholder() {
					return el(
						Placeholder,
						null,
						__(
							'Loading stream card…',
							'post-kinds-for-indieweb-in-block-themes'
						)
					);
				},
				EmptyResponsePlaceholder() {
					return el(
						Placeholder,
						null,
						__(
							'This post renders no stream card.',
							'post-kinds-for-indieweb-in-block-themes'
						)
					);
				},
			} )
		);
	}

	wp.blocks.registerBlockType( 'post-kinds-indieweb/stream-card', {
		apiVersion: 3,
		title: __( 'Stream card', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'Renders the current post as its Post Kinds stream card. Use inside a Query Loop.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'index-card',
		usesContext: [ 'postId', 'postType' ],
		supports: { html: false, reusable: false, inserter: true },
		ancestor: [ 'core/post-template' ],
		edit: StreamCardEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
