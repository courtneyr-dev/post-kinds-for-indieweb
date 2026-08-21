// Single source of per-plugin parameters for the docs site.
// Everything else (astro.config.mjs, Head.astro, structured data) reads from here.
export default {
	name: 'Post Kinds for IndieWeb in Block Themes',
	slug: 'post-kinds-for-indieweb',
	site: 'https://courtneyr-dev.github.io',
	base: '/post-kinds-for-indieweb',
	description:
		'User documentation for Post Kinds for IndieWeb in Block Themes: log what you listen to, watch, read, play, and visit on your own WordPress site.',
	github: 'https://github.com/courtneyr-dev/post-kinds-for-indieweb',
	wporg: 'https://wordpress.org/plugins/post-kinds-for-indieweb-in-block-themes/',
	version: '1.5.0',
	requiresWP: '7.0',
	requiresPHP: '8.2',
	author: 'Courtney Robertson',
	authorUrl: 'https://courtneyr.dev/',
};
