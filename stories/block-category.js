import { getCategories, setCategories } from '@wordpress/blocks';

// src/blocks/index.js adds this category before registering any block.
if ( ! getCategories().some( ( c ) => c.slug === 'post-kinds-indieweb' ) ) {
	setCategories( [
		{
			slug: 'post-kinds-indieweb',
			title: 'Post Kinds for IndieWeb in Block Themes',
		},
		...getCategories(),
	] );
}
