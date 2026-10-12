/**
 * Reading time — editor registration.
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
	const NAME = 'post-kinds-indieweb/reading-time';

	function ReadingTimeEdit( props ) {
		const blockProps = wp.blockEditor.useBlockProps();
		const postId = props.context && props.context.postId;

		return el(
			'div',
			blockProps,
			el(
				wp.components.Disabled,
				null,
				el( wp.serverSideRender, {
					block: NAME,
					attributes: props.attributes,
					urlQueryArgs: { post_id: postId },
				} )
			)
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Reading time', 'post-kinds-for-indieweb-in-block-themes' ),
		category: 'post-kinds-indieweb',
		ancestor: [ 'core/post-template' ],
		usesContext: [ 'postId', 'postType' ],
		supports: {
			html: false,
			reusable: false,
			typography: { fontSize: true },
		},
		edit: ReadingTimeEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
