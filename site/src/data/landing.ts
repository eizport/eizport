/**
 * The landing page's content (a sales page: promise, pains, the clean middle
 * way, benefits, who it's for, objections), shared by the page
 * (src/views/Home.astro, through src/i18n/en.ts) and its Markdown version (src/pages/index.md.ts).
 * Every claim is true of the plugin as it is: no invented numbers or quotes.
 */
export const hero = {
	title: 'Move your WordPress content. Not your mess.',
	lead: 'Pick the pages, products or posts you need — or just ask your AI agent. They arrive on the other site with their images, their settings and their homepage: no database dump, no broken images, no search-and-replace.',
	primary: 'Live Preview',
	secondary: 'Get it on WordPress.org',
	note: 'Free and open source. No account, no upsell, no “pro” version.',
};

export const pains = [
	{
		icon: 'media',
		title: 'Images break after the import',
		text: 'WordPress’s export only points to your media. The importer has to download every file from the old site — and if it’s offline, slow or behind a login, you get gray boxes.',
	},
	{
		icon: 'options',
		title: 'The homepage is gone',
		text: 'Settings never travel with WordPress’s export. Your static homepage, posts page, shop and checkout pages arrive as ordinary pages, and you rebuild the site by hand.',
	},
	{
		icon: 'archive',
		title: 'Migration plugins bring the junk',
		text: 'Copying the whole database brings transients, logs, sessions and the tables of plugins you deleted years ago — and overwrites the site you were importing into.',
	},
] as const;

export const steps = [
	{
		icon: 'export',
		title: 'Choose what moves',
		text: 'On Tools → Export, check the content types or the exact items. What they use — the products a page shows, the settings that point to it — comes up as a suggestion.',
	},
	{
		icon: 'archive',
		title: 'Get one complete file',
		text: 'Content, media files, the settings that point to it and each post as readable .html — in one .wxrz or .zip.',
	},
	{
		icon: 'import',
		title: 'Import it anywhere',
		text: 'Tools → Import opens the WordPress Importer you already know, dressed like the Export screen — or install the file as a plugin and click Run import. Media comes from the file, IDs are remapped, settings are set.',
	},
] as const;

export const features = [
	{
		id: 'one-list',
		eyebrow: 'Choose',
		title: 'Take exactly what you need — nothing else',
		text: 'Posts, pages, products, forms, attachments and options sit in one list. Move a store’s catalog without the blog, or a landing page without the rest. You see what each row exports before you click.',
		points: [ 'Several content types at once', 'Variations come with their products; reviews when you want them', 'Nothing checked? Everything goes, as you expect' ],
		image: 'export-screen',
		alt: 'The Export screen: Pages with 1 item, and Products and Options bringing what the homepage suggests (4 and 2 suggested); the file details open above the list.',
		href: 'docs/exporting/export-screen/',
	},
	{
		id: 'items',
		eyebrow: 'Precision',
		title: 'Hand-pick items — what they use is suggested',
		text: 'Open any type in a side panel, search it, filter it by status or category, and tick exactly the items you want: they join the export at once. The homepage, the shop and the checkout wear badges, and the products a page shows come up as suggestions, so you never leave a key piece behind.',
		points: [ 'Your selection survives search and paging', 'Products shown by blocks, and their reviews, suggested', 'A Selected filter to review what you picked' ],
		image: 'items-panel',
		alt: 'The Pages side panel: Home checked with a Homepage badge; Cart, Checkout and Shop with their WooCommerce page badges.',
		href: 'docs/exporting/choosing-items/',
	},
	{
		id: 'options',
		eyebrow: 'Settings that follow',
		title: 'Your homepage is still your homepage',
		text: 'Pick a page that is the homepage, the shop or the checkout, and the settings that point to it are suggested — check Options and they come along, remapped to the new page on import. Add any other option one by one.',
		points: [ 'Linked settings: homepage, posts page, shop, cart, checkout…', 'IDs remapped to the imported pages', 'Site URLs, keys, users and plugins never leave' ],
		image: 'options-panel',
		alt: 'The Options side panel showing the settings the homepage brings: page_on_front and show_on_front, checked.',
		href: 'docs/exporting/options/',
	},
	{
		id: 'playground',
		eyebrow: 'Demos in seconds',
		title: 'Ship a whole working site in one .zip',
		text: 'Export a WordPress Playground bundle with your content, its media, the theme and the plugins you choose — premium and custom ones too, not only the directory’s. Playground runs in the browser, so even a copy made on your laptop’s local server opens on playground.wordpress.net with one link.',
		points: [ 'Works from localhost: Playground runs in your browser', 'Any installed theme or plugin, premium or custom', 'Or run it offline: npx @wp-playground/cli server --blueprint=site.zip' ],
		image: 'playground-options',
		alt: 'The Playground themes and plugins modal: Twenty Twenty-Five chosen to install.',
		href: 'docs/formats/playground-zip/',
	},
	{
		id: 'as-plugin',
		eyebrow: 'One click on the other site',
		title: 'Send a plugin that imports itself',
		text: 'Turn on “As plugin” and the file becomes a plugin with the bundle inside. On the other site, install it like any plugin: its screen under Tools checks that Eizport and the WordPress Importer are there — with links to install them — and imports the bundle with Run import. No file to upload, no importer screen; when it’s done, it offers to delete itself.',
		points: [ 'No upload, no importer screen', 'Checks what it needs, with install links', 'Deactivates and deletes itself when done' ],
		image: 'bundle-plugin',
		alt: 'The imported plugin’s screen on the other site: what it needs, all checked, the result of Run import and “Deactivate and delete this plugin”.',
		href: 'docs/exporting/as-plugin/',
	},
	{
		id: 'settings',
		eyebrow: 'Your way',
		title: 'Set it once. Every export follows.',
		text: 'Bring the media your content uses or only what’s attached, choose when linked settings join, make bundles plain .zip files anyone can open — and save your favorite export as the default, for you or the whole team.',
		points: [ 'Save as default, per user or site-wide', 'Plain .zip bundles on request', 'WordPress’s Export screen untouched, or classic, if you prefer' ],
		image: 'settings-export',
		alt: 'The plugin’s Settings → Export: Only attached media, Linked settings, Bundle as .zip and Content as .html files.',
		href: 'docs/settings/export-settings/',
	},
	{
		id: 'copies',
		eyebrow: 'Nothing lost',
		title: 'Every export kept — briefly, and out of sight',
		text: 'Each export is kept as a copy you can download again, open in Playground or import back. Online, its link works like a password: a random token no one can guess. Search engines are told to stay away, direct access to the folder is blocked, and a cron job deletes the copy when its time is up.',
		points: [ 'Random 16-character token in every link', 'robots.txt, noindex and a locked folder', 'Expires on its own — 1 hour to 1 week, set in Settings' ],
		image: 'settings-copies',
		alt: 'The plugin’s Settings → Copies: a .wxrz bundle, a .zip bundle and a Playground .zip with sizes and expiry dates.',
		href: 'docs/settings/copies/',
	},
];

export const audiences = [
	{ icon: 'start', title: 'Agencies and freelancers', text: 'Move a finished section from staging to production, or a template page set from one client site to the next — without touching the rest.' },
	{ icon: 'archive', title: 'Store owners', text: 'Copy a product catalog with its images, variations and shop pages to a new store, merged into what’s already there.' },
	{ icon: 'play', title: 'Theme and plugin makers', text: 'Package demo content with your theme or plugin as a Playground .zip, ready for reviewers and buyers.' },
	{ icon: 'code', title: 'Developers and AI agents', text: 'Script every export and import through the Abilities API, REST or WP-CLI — or let an agent do it over MCP.' },
] as const;

export const objections = [
	{ question: 'Is it really free?', answer: 'Yes. Free and open source under the GPL, with no account, no license key and no paid version holding features back.' },
	{ question: 'Will it overwrite the site I import into?', answer: 'No. It imports with the WordPress Importer, which adds the content to the site. Only the settings you chose to export are set.' },
	{ question: 'Does the old site need to be online?', answer: 'No. The media files travel inside the bundle, so the importer never downloads anything from the old site.' },
	{ question: 'What about my private settings?', answer: 'Site URLs, the admin email, keys and salts, users and roles, active plugins, transients and caches are never exported — whatever you pick.' },
	{ question: 'Do I need the plugin on the other site?', answer: 'To import a bundle, yes (plus the free WordPress Importer). Exported “as plugin”, the file tells you so and links to both. Plain .xml exports import anywhere, as usual.' },
	{ question: 'Can the other site import without uploading a file?', answer: 'Yes: export “as plugin”. Install that .zip like any plugin, click Run import on its screen under Tools, and it deletes itself when done.' },
	{ question: 'Which plugins are supported?', answer: 'WooCommerce: products, orders, coupons, shop pages, galleries and reviews. Advanced Custom Fields and Elementor aren’t officially supported yet and weren’t tested: their data travels with the posts, but IDs inside it aren’t remapped.' },
] as const;

/** Plugins and how far they're supported (docs/developers/compatibilities/). */
export const compatibility = [
	{
		name: 'WooCommerce',
		status: 'supported',
		points: [ 'Products, variations, orders and coupons', 'Shop, cart, checkout and account pages follow their pages', 'Products shown by blocks suggested; reviews in their own row' ],
	},
	{
		name: 'Advanced Custom Fields',
		status: 'unknown',
		points: [ 'Field values travel with the posts (custom fields)', 'IDs inside fields (images, relationships) aren’t remapped', 'Field groups: export them with ACF’s own tools' ],
	},
	{
		name: 'Elementor',
		status: 'unknown',
		points: [ 'Designs travel with their pages (_elementor_data)', 'Image IDs and URLs inside designs aren’t remapped', 'Site kit and templates: Elementor’s Import / Export Kit' ],
	},
] as const;

export const finale = {
	title: 'Stop rebuilding after every import.',
	text: 'Install it in a minute, export what you need, and let it arrive complete.',
};

export const formats = [
	{ name: 'WXR XML', file: '.xml', media: false, settings: false, html: false, use: 'WordPress’s own export, unchanged' },
	{ name: 'WXRZ', file: '.wxrz', media: true, settings: true, html: true, use: 'Moving content between sites' },
	{ name: 'WXR ZIP', file: '.zip', media: true, settings: true, html: true, use: 'When people will open the file' },
	{ name: 'Playground', file: '.zip', media: true, settings: true, html: true, use: 'A runnable site: content, theme, plugins' },
	{ name: 'As plugin', file: '.zip', media: true, settings: true, html: true, use: 'Import with one click on the other site' },
];

export const hookExample = `add_filter( 'eizport_linked_options', function ( $linked ) {
	$linked['my_landing_page'] = array(
		'label' => 'Landing page',
		'with'  => array( 'my_landing_enabled' ),
	);
	return $linked;
} );`;

export const abilityExample = `curl -u admin:APP_PASSWORD -X POST \\
  https://example.com/wp-json/wp-abilities/v1/abilities/eizport/export/run \\
  -H "Content-Type: application/json" \\
  -d '{"input":{"types":{"page":{"ids":[43]}},"zip":true}}'`;


/** Why not a full migration, nor WordPress's export alone: the comparison. */
export const comparison = {
	columns: [ 'Full-site migration', 'WordPress’s export', 'Eizport' ],
	rows: [
		{ label: 'What goes', cells: [ 'The whole database and every file', 'One content type, or everything', 'What you choose, and what it depends on' ] },
		{ label: 'Leftovers', cells: [ 'Transients, logs, sessions, old plugins’ tables, spam', 'None', 'None: never transients, keys or caches' ] },
		{ label: 'Media files', cells: [ 'All of them', 'Not in the file — downloaded from the old site', 'Only the items’ media, inside the file' ] },
		{ label: 'Media used in content', cells: [ 'Yes, with everything else', 'Only attached and featured', 'Yes, attached or not' ] },
		{ label: 'Settings', cells: [ 'All, including the site’s URLs and users', 'None', 'The ones you choose; homepage, shop… follow their pages' ] },
		{ label: 'IDs and URLs', cells: [ 'Search-and-replace afterwards', 'Posts remapped; settings lost', 'Posts and settings remapped on import' ] },
		{ label: 'Target site', cells: [ 'Overwritten', 'Merged', 'Merged' ] },
	],
};

/** Agent-first: what an AI agent can do with the plugin, and how it looks. */
export const agent = {
	title: 'Built for AI agents, not bolted on',
	text: 'Every export, import, copy and setting is an ability of the WordPress Abilities API — typed input, typed output, permissions and safety hints. Connect an agent over MCP and ask in plain words; or call the same abilities over REST, PHP and WP-CLI.',
	points: [
		'12 abilities with JSON schemas an agent can read',
		'Read-only and destructive actions flagged for the agent',
		'Permissions are WordPress’s: export, import, manage_options',
		'Docs for agents too: llms.txt and every page as Markdown',
	],
	chat: [
		{ who: 'You', text: 'Export the homepage with its images and the basic settings as a .zip, and give me the link.' },
		{ who: 'Agent', tool: 'eizport/get-items', input: '{ "type": "page", "search": "Home" }', text: 'Found “Home” (ID 43) — it’s the homepage.' },
		{ who: 'Agent', tool: 'eizport/export', input: '{ "types": { "page": { "ids": [ 43 ] } }, "options": { "groups": [ "basics" ] }, "zip": true }', text: 'Done: home.zip (31 KB) with the page, 3 images, the homepage setting and the Basics. The link expires in a day.' },
	],
};
