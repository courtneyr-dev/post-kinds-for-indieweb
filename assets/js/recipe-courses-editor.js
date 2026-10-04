/**
 * Recipe courses — editor registration.
 *
 * The block is server-rendered (see PKIW\Recipe_Archive::render_courses_block()),
 * so the canvas prints the same links the recipe archive does. They are wrapped
 * in Disabled: a click selects the block, it doesn't follow the link.
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
	const NAME = 'post-kinds-indieweb/recipe-courses';

	function RecipeCoursesEdit( props ) {
		return el(
			'div',
			wp.blockEditor.useBlockProps(),
			el(
				wp.components.Disabled,
				null,
				el( wp.serverSideRender, {
					block: NAME,
					attributes: props.attributes,
				} )
			)
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __(
			'Recipe courses',
			'post-kinds-for-indieweb-in-block-themes'
		),
		description: __(
			'Links that filter the recipe archive by course, with the full list and an A–Z order.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'food',
		supports: { html: false, reusable: false },
		edit: RecipeCoursesEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
