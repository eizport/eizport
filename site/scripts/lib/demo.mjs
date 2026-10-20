/**
 * Shared by the screenshot and video scripts: the demo Studio sites
 * (scripts/demo-sites.sh), a logged-in browser on them, and the steps that
 * put the plugin's screens in a given state. Uses the system Chrome, so no
 * browser download is needed.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const studio = ( path, ...args ) =>
	execFileSync( 'studio', [ 'wp', `--path=${ path }`, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();

export const sites = {
	source: { path: process.env.EIZPORT_DEMO_SOURCE ?? join( homedir(), 'Studio/eizport-demo' ) },
	target: { path: process.env.EIZPORT_DEMO_TARGET ?? join( homedir(), 'Studio/eizport-demo-target' ) },
	// A clean site without Eizport or the importer: the "as plugin" .zip brings them.
	client: { path: process.env.EIZPORT_DEMO_CLIENT ?? join( homedir(), 'Studio/eizport-demo-client' ) },
};
// Sites not made yet (npm run demo-sites) are left out.
for ( const [ key, site ] of Object.entries( sites ) ) {
	if ( ! existsSync( join( site.path, 'wp-config.php' ) ) ) delete sites[ key ];
}
// A stopped site is started for the recording and stopped again when the
// script ends, as it was found.
const stopAtExit = [];
process.on( 'exit', () => {
	for ( const path of stopAtExit ) execFileSync( 'studio', [ 'site', 'stop', '--path', path ], { stdio: 'ignore' } );
} );
for ( const site of Object.values( sites ) ) {
	const status = JSON.parse( execFileSync( 'studio', [ 'site', 'status', '--path', site.path, '--format', 'json' ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );
	if ( ! status.isOnline ) {
		execFileSync( 'studio', [ 'site', 'start', '--path', site.path, '--skip-browser' ], { stdio: 'ignore' } );
		stopAtExit.push( site.path );
	}
	site.url = studio( site.path, 'option', 'get', 'siteurl' ).replace( /\/$/, '' );
	site.wp = ( ...args ) => studio( site.path, ...args );
}

// The labels the steps look for, in the admin's language: from the language
// packs of WordPress, the importer and the plugin (its screens' strings are in
// the pack's .json files), plus the few WordPress keeps in its scripts' JSON, listed in that
// repository's labels.json ({ "Actions": "…" }).
let labels = {};
// The browser's language (native controls, Accept-Language), set by useLocale().
let browserLocale;

/** Reads a .po's translations: msgid (or "context\u0004msgid") → msgstr. */
const readPo = ( file ) => {
	const entries = {};
	const unquote = ( lines ) => lines.filter( ( line ) => line.trim() ).map( ( line ) => JSON.parse( line.replace( /^[a-z_\[\]0-9]+\s+/, '' ) ) ).join( '' );
	for ( const block of readFileSync( file, 'utf8' ).split( /\n\s*\n/ ) ) {
		const field = ( name ) => {
			const match = block.match( new RegExp( `(?:^|\\n)${ name } ("[\\s\\S]*?)(?=\\n(?:msg|#)|$)` ) );
			return match ? unquote( match[ 1 ].split( '\n' ) ) : undefined;
		};
		const id = field( 'msgid' );
		const str = field( 'msgstr' ) ?? field( 'msgstr\\[0\\]' );
		if ( id && str ) {
			entries[ ( field( 'msgctxt' ) ? field( 'msgctxt' ) + '\u0004' : '' ) + id ] = str;
		}
	}
	return entries;
};

/** Reads a script translation .json (a language pack's): msgid → msgstr. */
const readJed = ( file ) =>
	Object.fromEntries(
		Object.entries( JSON.parse( readFileSync( file, 'utf8' ) ).locale_data.messages )
			.filter( ( [ id ] ) => id )
			.map( ( [ id, [ str ] ] ) => [ id, str ] )
	);

/** A label as the admin shows it (the English text until useLocale()). */
export const l = ( text ) => labels[ text ] ?? text;

/**
 * Shows the demo sites in a language: WordPress, WooCommerce and the
 * importer language packs (installed when missing), the plugin's .mo (npm run
 * plugin-languages), the admin user's locale, the site language (WPLANG) and
 * the browser's (launch(), inLocale()), and the demo store (useStore()). `wp`
 * is the WordPress locale ("pt_BR"), `repo` the language's repository (for
 * labels.json and demo/store.json); "en_US" goes back to English.
 */
export function useLocale( wp, repo = '' ) {
	for ( const site of Object.values( sites ) ) {
		if ( wp === 'en_US' ) {
			site.wp( 'user', 'meta', 'delete', '1', 'locale' );
			site.wp( 'option', 'update', 'WPLANG', '' );
			site.wp( 'option', 'update', 'date_format', 'F j, Y' );
			continue;
		}
		if ( ! site.wp( 'language', 'core', 'list', '--status=installed', '--field=language' ).split( '\n' ).includes( wp ) ) {
			site.wp( 'language', 'core', 'install', wp );
		}
		// Every pack the admin would otherwise offer as a translation update ("↻ 1" in the admin bar).
		site.wp( 'language', 'plugin', 'install', '--all', wp );
		site.wp( 'language', 'theme', 'install', '--all', wp );
		if ( ! existsSync( join( site.path, `wp-content/languages/plugins/eizport-${ wp }.mo` ) ) ) {
			throw new Error( `No eizport-${ wp }.mo on ${ site.path }: run npm run plugin-languages.` );
		}
		site.wp( 'user', 'meta', 'update', '1', 'locale', wp );
		// The site's language too: the front end (theme, admin bar) follows it.
		site.wp( 'option', 'update', 'WPLANG', wp );
		// Dates as a site installed in that language writes them ("13 de outubro de 2026").
		site.wp( 'eval', `switch_to_locale( '${ wp }' ); update_option( 'date_format', __( 'F j, Y' ) );` );
	}
	useStore( wp === 'en_US' ? '' : repo );
	browserLocale = wp === 'en_US' ? undefined : wp.replace( '_', '-' );
	// WordPress's admin, the importer and the plugin from the demo sites' language packs, then labels.json.
	const files = Object.values( sites ).flatMap( ( site ) => {
		const lang = join( site.path, 'wp-content/languages' );
		const scripts = existsSync( `${ lang }/plugins` ) ? readdirSync( `${ lang }/plugins` ).filter( ( file ) => file.startsWith( `eizport-${ wp }-` ) && file.endsWith( '.json' ) ) : [];
		return [ `${ lang }/admin-${ wp }.po`, `${ lang }/${ wp }.po`, `${ lang }/plugins/wordpress-importer-${ wp }.po`, `${ lang }/plugins/eizport-${ wp }.po`, ...scripts.map( ( file ) => `${ lang }/plugins/${ file }` ) ];
	} );
	const extra = join( repo, 'labels.json' );
	labels = wp === 'en_US' ? {} : Object.assign( {}, ...files.filter( existsSync ).map( ( file ) => ( file.endsWith( '.json' ) ? readJed( file ) : readPo( file ) ) ), existsSync( extra ) ? JSON.parse( readFileSync( extra, 'utf8' ) ) : {} );
}

/**
 * The demo store in a language: its repository's demo/store.json (currency,
 * units, products, categories, the menu and the home) on the source site. The
 * English store is kept aside first and put back on the way to English (`repo` '').
 */
function useStore( repo ) {
	const site = sites.source;
	if ( ! site ) {
		return;
	}
	const script = fileURLToPath( new URL( 'demo-store.php', import.meta.url ) );
	const backup = join( site.path, 'wp-content/eizport-demo-store-en.json' );
	const store = repo ? resolve( repo, 'demo/store.json' ) : '';
	if ( existsSync( backup ) ) {
		site.wp( 'eval-file', script, backup );
		rmSync( backup );
	}
	if ( store && existsSync( store ) ) {
		writeFileSync( backup, site.wp( 'eval-file', script, store ) );
	}
}

export const viewports = {
	desktop: { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 },
	phone: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true },
};

export const exportPath = '/wp-admin/tools.php?page=eizport-export-wp-admin&p=%2Fexport';
export const settingsPath = ( tab ) => `/wp-admin/plugins.php?page=eizport-settings-wp-admin&p=%2F${ tab }`;

/** The system Chrome, in the language useLocale() set (native controls follow --lang). */
export const launch = ( options = {} ) =>
	chromium.launch( { channel: 'chrome', ...options, args: [ ...( options.args ?? [] ), ...( browserLocale ? [ `--lang=${ browserLocale }` ] : [] ) ] } );

/** Context options in that language. */
export const inLocale = ( options = {} ) => ( browserLocale ? { ...options, locale: browserLocale } : options );

/** A page logged in (Studio's auto-login: no password) and open at `path`. */
export async function open( browser, { site = sites.source, path = '/wp-admin/', context = viewports.desktop } = {} ) {
	const ctx = await browser.newContext( inLocale( { ...context, acceptDownloads: true } ) );
	const page = await ctx.newPage();
	await page.goto( `${ site.url }/studio-auto-login?redirect_to=${ encodeURIComponent( path ) }` );
	await settle( page );
	return page;
}

/** Waits for the page and its fonts, then a beat for animations. */
export async function settle( page, ms = 600 ) {
	await page.waitForLoadState( 'networkidle' ).catch( () => {} );
	await page.evaluate( () => document.fonts?.ready );
	await page.waitForTimeout( ms );
}

// Steps on the Export screen (Tools → Export). Each leaves the screen settled.
export const exportScreen = {
	async ready( page ) {
		await page.getByRole( 'columnheader', { name: l( 'Content' ) } ).waitFor();
		await settle( page );
	},
	row: ( page, type ) => page.getByRole( 'row', { name: new RegExp( `^${ type } ` ) } ),
	async chooseItems( page, type ) {
		// Wide screens show the button; phones keep it in the Actions menu.
		const row = exportScreen.row( page, type );
		const button = row.getByRole( 'button', { name: l( 'Choose items' ) } );
		if ( await button.count() ) {
			await button.click();
		} else {
			await row.getByRole( 'button', { name: l( 'Actions' ) } ).click();
			await page.getByRole( 'menuitem', { name: l( 'Choose items' ) } ).click();
		}
		await page.getByRole( 'region', { name: type } ).getByRole( 'table' ).waitFor();
		await settle( page );
	},
	panel: ( page, type ) => page.getByRole( 'region', { name: type } ),
	async checkItem( page, type, title ) {
		await exportScreen.panel( page, type ).getByRole( 'checkbox', { name: title, exact: true } ).check();
		await settle( page, 300 );
	},
	async addFilter( page, type, filter, value ) {
		const panel = exportScreen.panel( page, type );
		await panel.getByRole( 'button', { name: l( 'Add filter' ) } ).click();
		await page.getByRole( 'menuitem', { name: filter } ).click();
		await page.getByRole( 'option', { name: value } ).or( page.getByRole( 'menuitemcheckbox', { name: value } ) ).first().click();
		await page.keyboard.press( 'Escape' );
		await settle( page );
	},
	/** Closes the side panel (what was checked already counts). */
	async close( page, type ) {
		await exportScreen.panel( page, type ).getByRole( 'button', { name: l( 'Close' ), exact: true } ).click();
		await settle( page );
	},
	/** Checks a row: with suggestions, it brings them ("4 suggested"). */
	async checkRow( page, type ) {
		await exportScreen.row( page, type ).getByRole( 'checkbox' ).first().check();
		await settle( page, 600 );
	},
	/** Opens the Details panel (format, file name, media…), closed to start with. */
	async details( page ) {
		const toggle = page.locator( '.eizport-file-panel__toggle' );
		if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
			await toggle.click();
			await settle( page, 300 );
		}
	},
	async format( page, name ) {
		await exportScreen.details( page );
		await page.getByRole( 'radio', { name } ).check();
		await settle( page, 300 );
	},
};
