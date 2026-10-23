/**
 * Every screenshot of the plugin: the feature and screen it shows, how it is
 * captured, the plugin files it depends on, and where it appears. The source
 * of truth for scripts/screenshots.mjs (captures them on the demo sites),
 * scripts/check-screenshots.mjs (run by `npm run check`) and SCREENSHOTS.md.
 *
 * When a feature changes, recapture what depends on it:
 *   npm run screenshots -- --changed    (or: npm run screenshots -- <id> …)
 *
 * - `sources`: plugin paths (relative to the plugin, a trailing / is a folder)
 *   whose changes can change the image.
 * - `usedIn`: files under src/ that show it. The check fails when a file
 *   shows a screenshot it doesn't list, or lists one it doesn't show.
 * - `wporg`: its number among the WordPress.org screenshots
 *   (.wordpress-org/screenshot-N.png and readme.txt's == Screenshots ==).
 */
export interface Screenshot {
	id: string;
	feature: string;
	screen: string;
	state: string;
	viewport: 'desktop' | 'phone';
	sources: string[];
	usedIn: string[];
	wporg?: { number: number; caption: string };
}

const exportApp = [ 'routes/export/', 'src/', 'includes/admin-app.php', 'includes/abilities.php' ];

export const screenshots: Screenshot[] = [
	{
		id: 'export-screen',
		feature: 'One list for every content type, attachments, product reviews and options; what each row exports, suggestions',
		screen: 'Tools → Export',
		state: 'Pages: 1 item (Home); Products and Options bring what the Home suggests; Details open',
		viewport: 'desktop',
		sources: [ ...exportApp, 'includes/selection.php', 'includes/options.php' ],
		usedIn: [ 'views/Home.astro', 'content/docs/getting-started/introduction.md', 'content/docs/exporting/export-screen.md' ],
		wporg: { number: 1, caption: 'Tools → Export: choose content types or exact items; the products and settings they use are suggested.' },
	},
	{
		id: 'items-panel',
		feature: 'Choosing items by selection; badges for the homepage and WooCommerce pages',
		screen: 'Tools → Export → Pages → Choose items',
		state: 'Home checked, the export state behind it',
		viewport: 'desktop',
		sources: [ ...exportApp, 'includes/selection.php', 'includes/compat/' ],
		usedIn: [ 'views/Home.astro', 'content/docs/exporting/choosing-items.md', 'content/docs/getting-started/your-first-export.md' ],
		wporg: { number: 2, caption: 'Pick the exact items to export; the homepage, shop and checkout wear badges.' },
	},
	{
		id: 'options-panel',
		feature: 'Options one by one; the settings the items chosen suggest',
		screen: 'Tools → Export → Options → Choose items',
		state: 'Selection is Selected: page_on_front and show_on_front, brought by the homepage',
		viewport: 'desktop',
		sources: [ ...exportApp, 'includes/options.php', 'includes/compat/' ],
		usedIn: [ 'views/Home.astro', 'content/docs/exporting/options.md' ],
		wporg: { number: 3, caption: 'Settings travel too: the ones your pages point to are suggested; add any other one by one.' },
	},
	{
		id: 'playground-options',
		feature: 'WordPress Playground .zip: themes and plugins to install',
		screen: 'Tools → Export → Format: Playground → Playground options',
		state: 'Playground themes and plugins modal with the active theme',
		viewport: 'desktop',
		sources: [ ...exportApp, 'includes/playground.php' ],
		usedIn: [ 'views/Home.astro', 'content/docs/formats/playground-zip.md' ],
		wporg: { number: 4, caption: 'Export a WordPress Playground .zip with the theme and plugins you choose.' },
	},
	{
		id: 'bundle-plugin',
		feature: 'Export as plugin: the plugin installed on the other site checks what it needs and imports its bundle with one click',
		screen: 'Tools → Import Eiz Coffee (the plugin made with "As plugin"), on the other site',
		state: 'Imported, with "Deactivate and delete this plugin"',
		viewport: 'desktop',
		sources: [ 'includes/bundle-plugin.php', 'templates/bundle-plugin.php', 'assets/importer.css', 'assets/bundle-plugin.js', 'includes/import.php' ],
		usedIn: [ 'views/Home.astro', 'content/docs/exporting/as-plugin.md' ],
	},
	{
		id: 'settings-general',
		feature: 'General settings: the Export and Import screens, saved defaults, how long copies are kept',
		screen: 'The plugin’s Settings → General',
		state: 'Defaults',
		viewport: 'desktop',
		sources: [ 'routes/settings-general/', 'routes/settings-home/', 'src/', 'includes/settings.php' ],
		usedIn: [ 'content/docs/settings/general.md' ],
	},
	{
		id: 'settings-export',
		feature: 'Export settings: attached media only, linked settings, .zip bundles, content as .html, defaults scope',
		screen: 'The plugin’s Settings → Export',
		state: 'Defaults',
		viewport: 'desktop',
		sources: [ 'routes/settings-export/', 'routes/settings-home/', 'src/', 'includes/settings.php' ],
		usedIn: [ 'views/Home.astro', 'content/docs/settings/export-settings.md' ],
		wporg: { number: 5, caption: 'Set it once: media, linked settings, .zip bundles and saved defaults.' },
	},
	{
		id: 'settings-copies',
		feature: 'Copies of exports: download, open in Playground, import; expiry',
		screen: 'The plugin’s Settings → Copies',
		state: 'Three copies: a .wxrz, a .zip bundle and a Playground .zip',
		viewport: 'desktop',
		sources: [ 'routes/settings-copies/', 'routes/settings-home/', 'src/', 'includes/settings.php', 'includes/copies.php' ],
		usedIn: [ 'views/Home.astro', 'content/docs/settings/copies.md' ],
		wporg: { number: 6, caption: 'Every export is kept as a private copy for a while: download it again, open it in Playground or import it.' },
	},
	{
		id: 'export-mobile',
		feature: 'The Export screen on a phone',
		screen: 'Tools → Export (390 px)',
		state: 'Pages: 1 item; Products and Options suggested',
		viewport: 'phone',
		sources: [ ...exportApp ],
		usedIn: [ 'views/Home.astro' ],
	},
	{
		id: 'classic-mobile',
		feature: 'The classic Export screen with the plugin’s additions, on a phone',
		screen: 'Tools → Export, classic (export.php?classic=1, 390 px)',
		state: 'Defaults',
		viewport: 'phone',
		sources: [ 'includes/export.php', 'includes/selection.php' ],
		usedIn: [ 'views/Home.astro', 'content/docs/exporting/classic-screen.md' ],
	},
];

/** The demo video's scenes and what they depend on (see video/STORYBOARD.md). */
export const video = {
	id: 'demo',
	sources: [ ...exportApp, 'includes/import.php', 'includes/export.php', 'includes/playground.php', 'includes/copies.php', 'includes/options.php' ],
	usedIn: [ 'views/Home.astro' ],
};
