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
 *
 * Plain script on purpose: no build step, only WordPress globals.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;
	var useBlockProps = wp.blockEditor.useBlockProps;

	function Placeholder( props ) {
		return el(
			'div',
			{ className: 'pkiw-stream-card-editor__placeholder' },
			props.children
		);
	}

	wp.blocks.registerBlockType( 'post-kinds-indieweb/stream-card', {
		apiVersion: 3,
		title: __( 'Stream card', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __( 'Renders the current post as its Post Kinds stream card. Use inside a Query Loop.', 'post-kinds-for-indieweb-in-block-themes' ),
		category: 'post-kinds-indieweb',
		icon: 'index-card',
		usesContext: [ 'postId', 'postType' ],
		supports: { html: false, reusable: false, inserter: true },
		ancestor: [ 'core/post-template' ],
		edit: function ( props ) {
			var blockProps = useBlockProps( { className: 'pkiw-stream-card-editor' } );
			var postId = props.context && props.context.postId;

			if ( ! postId ) {
				return el(
					'div',
					blockProps,
					el( Placeholder, null, __( 'Stream card: place this block inside a Query Loop’s Post Template.', 'post-kinds-for-indieweb-in-block-themes' ) )
				);
			}

			return el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block: 'post-kinds-indieweb/stream-card',
					attributes: {},
					urlQueryArgs: { post_id: postId },
					LoadingResponsePlaceholder: function () {
						return el( Placeholder, null, __( 'Loading stream card…', 'post-kinds-for-indieweb-in-block-themes' ) );
					},
					EmptyResponsePlaceholder: function () {
						return el( Placeholder, null, __( 'This post renders no stream card.', 'post-kinds-for-indieweb-in-block-themes' ) );
					},
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
