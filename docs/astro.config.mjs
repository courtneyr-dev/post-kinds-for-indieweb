import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import meta from './src/site-meta.mjs';

export default defineConfig({
	site: meta.site,
	base: meta.base,
	integrations: [
		starlight({
			title: `${meta.name} documentation`,
			description: meta.description,
			favicon: '/favicon.svg',
			logo: { src: './src/assets/site-icon.svg', alt: '' },
			social: [
				{ icon: 'github', label: 'GitHub repository', href: meta.github },
				...(meta.wporg
					? [{ icon: 'wordpress', label: 'WordPress.org listing', href: meta.wporg }]
					: []),
				{ icon: 'warning', label: 'Report an issue', href: `${meta.github}/issues` },
			],
			editLink: { baseUrl: `${meta.github}/edit/main/docs/` },
			lastUpdated: true,
			head: [
				{
					tag: 'meta',
					attrs: { property: 'og:image', content: `${meta.site}${meta.base}/social-card.png` },
				},
				{
					tag: 'meta',
					attrs: {
						property: 'og:image:alt',
						content: `${meta.name} — WordPress plugin documentation`,
					},
				},
				{ tag: 'meta', attrs: { name: 'twitter:card', content: 'summary_large_image' } },
			],
			components: {
				PageTitle: './src/components/Breadcrumbs.astro',
				Head: './src/components/Head.astro',
			},
			sidebar: [
				{
					label: 'Start here',
					items: ['installation', 'getting-started'],
				},
				{
					label: 'Using Post Kinds',
					items: ['settings', 'common-tasks'],
				},
				{
					label: 'Reference',
					items: ['screenshots', 'playground'],
				},
				{
					label: 'Help',
					items: [
						'faq',
						'troubleshooting',
						'accessibility',
						'privacy-and-data',
						'uninstall',
					],
				},
			],
		}),
	],
});
