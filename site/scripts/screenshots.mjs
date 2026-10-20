/**
 * Captures the plugin's screenshots on the demo source site (npm run
 * demo-sites), as src/data/screenshots.ts lists them:
 *
 *   npm run screenshots                 all of them
 *   npm run screenshots -- items-panel  only these ids
 *   npm run screenshots -- --changed    those whose `sources` changed since
 *                                       they were captured (git), and the
 *                                       video when its sources did
 *   npm run screenshots -- --doc        only rewrite SCREENSHOTS.md and readme.txt's
 *                                       == Screenshots ==
 *   npm run screenshots -- --locale br=../../eizport-ptb [ids]
 *                                       in another language (translations.json):
 *                                       the demo admin in that language (npm run
 *                                       plugin-languages first), written to that
 *                                       repository's assets/screenshots/
 *
 * Writes src/assets/screenshots/<id>.webp, .wordpress-org/screenshot-N.png
 * for those with `wporg`, the commit each was captured at
 * (src/data/screenshots-captured.json), SCREENSHOTS.md and readme.txt's
 * == Screenshots == section.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import sharp from 'sharp';
import { screenshots, video } from '../src/data/screenshots.ts';
import { exportPath, exportScreen, l, launch, open, settingsPath, settle, sites, useLocale, viewports } from './lib/demo.mjs';
import { capturedFile, plugin, readCaptured, root, writeFiles } from './lib/screenshots-files.mjs';

const captured = readCaptured();
const git = ( ...args ) => execFileSync( 'git', args, { cwd: plugin, encoding: 'utf8' } ).trim();

/** Plugin files changed since a commit (committed or not). */
const changedSince = ( commit ) =>
	commit
		? [ ...new Set( [ ...git( 'diff', '--name-only', commit, '--' ).split( '\n' ), ...git( 'ls-files', '--others', '--exclude-standard' ).split( '\n' ) ] ) ].filter( Boolean )
		: null;
const touches = ( sources, files ) => files === null || files.some( ( file ) => sources.some( ( source ) => ( source.endsWith( '/' ) ? file.startsWith( source ) : file === source ) ) );

// The state each screenshot shows, on a logged-in page.
const scenes = {
	async 'export-screen'( page ) {
		await page.goto( sites.source.url + exportPath );
		await exportScreen.ready( page );
		await pagesHome( page );
		await suggested( page );
		await exportScreen.details( page );
	},
	async 'items-panel'( page ) {
		await page.goto( sites.source.url + exportPath );
		await exportScreen.ready( page );
		await exportScreen.chooseItems( page, l( 'Pages' ) );
		await exportScreen.checkItem( page, l( 'Pages' ), 'Home' );
	},
	async 'options-panel'( page ) {
		await page.goto( sites.source.url + exportPath );
		await exportScreen.ready( page );
		await pagesHome( page );
		await suggested( page );
		// Opens on Selection is Selected: the settings the homepage brings; show the filter chips.
		await exportScreen.chooseItems( page, l( 'Options' ) );
		await exportScreen.panel( page, l( 'Options' ) ).getByRole( 'button', { name: l( 'Filter' ), exact: true } ).click();
		await settle( page );
	},
	async 'playground-options'( page ) {
		await page.goto( sites.source.url + exportPath );
		await exportScreen.ready( page );
		await pagesHome( page );
		await suggested( page );
		await exportScreen.format( page, 'Playground' );
		await page.locator( '.eizport-file-bar__more' ).getByRole( 'button', { name: l( 'Themes and plugins' ) } ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.waitFor();
		await dialog.getByRole( 'combobox' ).first().fill( 'Twenty' );
		await page.getByRole( 'option' ).first().click();
		await dialog.getByRole( 'heading' ).first().click();
		await settle( page );
	},
	async 'bundle-plugin'( page ) {
		// Exported "as plugin" on the source, installed on the target, imported there.
		// The plugin's folder ends in the copy's random part, lowercase.
		const [ zip, slug ] = sites.source
			.wp(
				'eval',
				"wp_set_current_user( 1 ); $c = wp_get_ability( 'eizport/export' )->execute( array( 'as_plugin' => true, 'types' => array( 'page' => array( 'ids' => array( (int) get_option( 'page_on_front' ) ) ) ), 'options' => array( 'groups' => array( 'basics' ) ), 'filename' => 'eiz-coffee' ) ); echo \\Eizport\\Copies\\path( $c['name'] ), \"\\n\", \\Eizport\\BundlePlugin\\slug( 'eiz-coffee', \\Eizport\\BundlePlugin\\copy_hash( $c['name'] ) );"
			)
			.split( '\n' );
		sites.target.wp( 'plugin', 'install', zip, '--activate', '--force' );
		afterShot.push( () => {
			sites.target.wp( 'plugin', 'deactivate', slug );
			sites.target.wp( 'plugin', 'delete', slug );
			sites.target.wp( 'option', 'delete', 'eizport_bundle_plugins_imported' );
			sites.source.wp( 'eval', "wp_set_current_user( 1 ); wp_get_ability( 'eizport/delete-copies' )->execute( array( 'all' => true ) );" );
		} );
		await page.goto( `${ sites.target.url }/studio-auto-login?redirect_to=${ encodeURIComponent( `/wp-admin/tools.php?page=eizport-bundle-${ slug }` ) }` );
		await page.getByRole( 'button', { name: l( 'Run import' ) } ).click();
		await page.getByRole( 'button', { name: l( 'Deactivate and delete' ) } ).waitFor( { timeout: 180000 } );
		await settle( page, 800 );
	},
	async 'settings-general'( page ) {
		await page.goto( sites.source.url + settingsPath( 'general' ) );
		await settle( page, 1200 );
		await openCards( page, [ 'Export screen', 'Import screen', 'Copies' ] );
	},
	async 'settings-export'( page ) {
		await page.goto( sites.source.url + settingsPath( 'export' ) );
		await settle( page, 1200 );
		await openCards( page, [ 'With the items exported' ] );
	},
	async 'settings-copies'( page ) {
		makeCopies();
		await page.goto( sites.source.url + settingsPath( 'copies' ) );
		await page.getByRole( 'table' ).waitFor();
		await settle( page, 1200 );
	},
	async 'export-mobile'( page ) {
		await page.goto( sites.source.url + exportPath );
		await exportScreen.ready( page );
		await pagesHome( page );
		await suggested( page );
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await settle( page );
	},
	async 'classic-mobile'( page ) {
		// Scrolled to the plugin's additions: the bundle and its settings.
		await page.goto( sites.source.url + '/wp-admin/export.php?classic=1' );
		const bundle = l( 'Bundle (.%s): the WXR and, optionally, its media and content files, compressed' ).replace( '%s', 'wxrz' ).split( ':' )[ 0 ];
		await page.getByText( bundle ).first().scrollIntoViewIfNeeded();
		await page.evaluate( () => window.scrollBy( 0, -140 ) );
		await settle( page );
	},
};

/** The Home page, chosen in the Pages panel. */
async function pagesHome( page ) {
	await exportScreen.chooseItems( page, l( 'Pages' ) );
	await exportScreen.checkItem( page, l( 'Pages' ), 'Home' );
	await exportScreen.close( page, l( 'Pages' ) );
}
/** What the Home suggests: the products its block shows and its settings. */
async function suggested( page ) {
	await exportScreen.checkRow( page, l( 'Products' ) );
	await exportScreen.checkRow( page, l( 'Options' ) );
}
/** Opens a settings page's cards (closed to start with) by their titles. */
async function openCards( page, titles ) {
	for ( const title of titles ) {
		await page.locator( '.eizport-form' ).getByText( l( title ), { exact: true } ).first().click();
		await settle( page, 300 );
	}
}

/** Three copies on the source site (a .wxrz, a .zip bundle, a Playground .zip); one export per request. */
function makeCopies() {
	const run = ( input ) =>
		sites.source.wp( 'eval', `wp_set_current_user( 1 ); wp_get_ability( 'eizport/export' )->execute( json_decode( '${ JSON.stringify( input ) }', true ) );` );
	sites.source.wp( 'eval', "wp_set_current_user( 1 ); wp_get_ability( 'eizport/delete-copies' )->execute( array( 'all' => true ) );" );
	run( { blueprint: { themes: [ sites.source.wp( 'option', 'get', 'stylesheet' ) ], plugins: [] }, filename: 'site-demo' } );
	run( { types: { product: {} }, zip: true, filename: 'store-products' } );
	run( { types: { page: { ids: [ 43 ] } }, options: { groups: [ 'basics' ] }, filename: 'homepage-launch' } );
}

const args = process.argv.slice( 2 );
if ( args.includes( '--doc' ) ) {
	writeFiles( captured );
	process.exit( 0 );
}
// --locale <code>=<its repository>: the screenshots in that language, written there.
const localeArg = args.includes( '--locale' ) ? args[ args.indexOf( '--locale' ) + 1 ] : '';
const [ localeCode, localeRepo ] = localeArg ? localeArg.split( '=' ) : [];
const locale = localeCode
	? JSON.parse( readFileSync( new URL( 'translations.json', root ), 'utf8' ) ).locales.find( ( item ) => item.code === localeCode )
	: null;
if ( localeCode && ( ! locale || ! localeRepo ) ) {
	console.error( `--locale needs a language of translations.json and its repository: --locale br=../../eizport-ptb` );
	process.exit( 1 );
}
const ids = args.filter( ( a, index ) => ! a.startsWith( '--' ) && args[ index - 1 ] !== '--locale' );
const pick = screenshots.filter( ( shot ) =>
	ids.length ? ids.includes( shot.id ) : ! args.includes( '--changed' ) || touches( shot.sources, changedSince( captured[ shot.id ]?.commit ) )
);
if ( args.includes( '--changed' ) && touches( video.sources, changedSince( captured.demo?.commit ) ) ) {
	console.log( 'The demo video depends on changed files too: npm run video' );
}

const outDir = locale ? resolve( localeRepo, 'assets/screenshots' ) : new URL( 'src/assets/screenshots', root ).pathname;
if ( locale ) {
	mkdirSync( outDir, { recursive: true } );
	useLocale( locale.wp, resolve( localeRepo ) );
}
const browser = await launch();
// Cleanups a scene leaves for after its screenshot.
const afterShot = [];
const commit = git( 'rev-parse', 'HEAD' );
const date = new Date().toISOString().slice( 0, 10 );
for ( const shot of pick ) {
	const page = await open( browser, { path: '/wp-admin/', context: viewports[ shot.viewport ] } );
	await scenes[ shot.id ]( page );
	const png = await page.screenshot();
	await sharp( png ).webp( { quality: 92 } ).toFile( `${ outDir }/${ shot.id }.webp` );
	afterShot.splice( 0 ).forEach( ( cleanup ) => cleanup() );
	// WordPress.org and the capture log are the English screenshots'.
	if ( locale ) {
		await page.context().close();
		console.log( `✓ ${ shot.id } (${ locale.code })` );
		continue;
	}
	if ( shot.wporg ) {
		await sharp( png ).resize( { width: 1440 } ).png( { compressionLevel: 9 } ).toFile( new URL( `.wordpress-org/screenshot-${ shot.wporg.number }.png`, plugin ).pathname );
	}
	captured[ shot.id ] = { commit, date };
	await page.context().close();
	console.log( `✓ ${ shot.id }` );
}
await browser.close();
if ( pick.some( ( shot ) => shot.id === 'settings-copies' ) ) {
	sites.source.wp( 'eval', "wp_set_current_user( 1 ); wp_get_ability( 'eizport/delete-copies' )->execute( array( 'all' => true ) );" );
}
if ( locale ) {
	useLocale( 'en_US' );
	console.log( `${ pick.length } captured in ${ locale.code }, in ${ outDir }.` );
	process.exit( 0 );
}
writeFileSync( capturedFile, JSON.stringify( captured, null, '\t' ) + '\n' );
writeFiles( captured );
console.log( pick.length ? `${ pick.length } captured.` : 'Nothing to capture.' );
