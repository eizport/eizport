/**
 * The docs' navigation, as Bootstrap's site/data/sidebar.yml: groups of
 * pages by title. A page's URL is /docs/<group slug>/<title slug>/ and its
 * Markdown lives at src/content/docs/<group slug>/<title slug>.md; the build
 * fails when one is missing. `generated` pages are Astro pages instead
 * (made from readme.txt).
 */
export interface SidebarPage {
	title: string;
	generated?: boolean;
}

export interface SidebarGroup {
	title: string;
	icon: 'start' | 'export' | 'archive' | 'settings' | 'code' | 'book';
	pages: SidebarPage[];
}

export const sidebar: SidebarGroup[] = [
	{
		title: 'Getting started',
		icon: 'start',
		pages: [ { title: 'Introduction' }, { title: 'Installation' }, { title: 'Your first export' }, { title: 'Importing' }, { title: 'Multisite' } ],
	},
	{
		title: 'Use cases',
		icon: 'start',
		pages: [ { title: 'Header menus to staging' }, { title: 'Theme look to staging' }, { title: 'Galleries to staging' }, { title: 'Internal links to staging' }, { title: 'Product categories to staging' }, { title: 'Second delivery to staging' }, { title: 'Subsite to subsite' }, { title: 'Client demo in Playground' }, { title: 'Playground in your language' }, { title: 'Custom post types in Playground' } ],
	},
	{
		title: 'Exporting',
		icon: 'export',
		pages: [
			{ title: 'Export screen' },
			{ title: 'Choosing items' },
			{ title: 'Options' },
			{ title: 'Menus' },
			{ title: 'Appearance' },
			{ title: 'Media' },
			{ title: 'File and formats' },
			{ title: 'As plugin' },
			{ title: 'Saved defaults' },
			{ title: 'Classic screen' },
		],
	},
	{
		title: 'Formats',
		icon: 'archive',
		pages: [ { title: 'WXRZ bundle' }, { title: 'ZIP bundle' }, { title: 'Playground zip' } ],
	},
	{
		title: 'Settings',
		icon: 'settings',
		pages: [ { title: 'General' }, { title: 'Export settings' }, { title: 'Copies' } ],
	},
	{
		title: 'Developers',
		icon: 'code',
		pages: [ { title: 'AI agents' }, { title: 'Abilities' }, { title: 'Hooks' }, { title: 'Compatibilities' }, { title: 'WP-CLI' } ],
	},
	{
		title: 'Reference',
		icon: 'book',
		pages: [ { title: 'FAQ', generated: true }, { title: 'Changelog', generated: true } ],
	},
];
