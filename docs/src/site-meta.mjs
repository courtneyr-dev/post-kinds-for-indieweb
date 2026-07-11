// Single source of per-plugin parameters for the docs site.
// Everything else (astro.config.mjs, Head.astro, structured data) reads from here.
export default {
	name: 'Post Kinds for IndieWeb',
	slug: 'post-kinds-for-indieweb',
	site: 'https://courtneyr-dev.github.io',
	base: '/post-kinds-for-indieweb',
	description:
		'User documentation for Post Kinds for IndieWeb: log what you listen to, watch, read, play, and visit on your own WordPress site.',
	github: 'https://github.com/courtneyr-dev/post-kinds-for-indieweb',
	// Not published in the WordPress.org plugin directory. Set to the listing URL after publication.
	wporg: null,
	version: '1.0.0',
	requiresWP: '7.0',
	requiresPHP: '8.2',
	author: 'Courtney Robertson',
	authorUrl: 'https://courtneyr.dev/',
};
