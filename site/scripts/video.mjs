/**
 * Records the demo video on the demo Studio sites (npm run demo-sites),
 * following video/STORYBOARD.md: each scene's caption and hold come from
 * there, its actions from `scenes` below (same ids).
 *
 *   npm run video
 *   npm run video -- --locale br=../../eizport-ptb
 *       in another language (translations.json): the demo admin in that
 *       language (npm run plugin-languages first), its captions and voice
 *       from that repository's video/captions.txt ("id: text" lines) and
 *       video/voice.json, written to its assets/video/ (demo.{mp4,webm},
 *       demo.<code>.vtt, demo-poster.webp). Nothing of another language
 *       lives in this repository.
 *
 * Writes public/video/demo.{mp4,webm}, demo.en.vtt, src/assets/video/demo-poster.webp
 * (the site's hero), and video/out/ (git-ignored, for YouTube): demo.mp4,
 * demo.en.vtt and thumbnail.jpg. demo.mp4 and demo.webm aren't kept in git:
 * they're uploaded to the repository's media-X.Y.Z release (scripts/media.mjs),
 * and media.json points the site at it. One video, no text burned in: captions are WebVTT.
 * Everything is real (Chrome's screencast of the actual screens, with a
 * cursor drawn over them); waits are sped up where marked.
 *
 * Sound: each caption is narrated (macOS `say`: Samantha in English, the
 * translation's voice.json in another language; a scene lasts at least its
 * narration) over a
 * quiet ambient track synthesized here (no third-party music), ducked under
 * the voice. Before recording, the demo sites go back to the plugin's
 * default settings and no saved export defaults, and the client site to no
 * Eizport and no importer, so every language records the same screens.
 *
 * The "as plugin" scene shows Install Now taking Eizport from its GitHub
 * release, which the plugin itself doesn't do (WordPress.org guideline 8):
 * the exported .zip is made with the Install Now from GitHub patch applied
 * to the source site's copy of the template only — never to this
 * repository. EIZPORT_GITHUB_PATCH names the patch (default:
 * ~/workspace/eizport-notes/patches/install-now-from-github.patch).
 */
import { execFileSync, spawn } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { createConnection } from 'node:net';
import { homedir, tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import ffmpeg from 'ffmpeg-static';
import sharp from 'sharp';
import { exportPath, exportScreen, inLocale, l, launch, settingsPath, sites, useLocale } from './lib/demo.mjs';
import { capturedFile, readCaptured, writeFiles } from './lib/screenshots-files.mjs';
import { uploadVideo, useTag, versionTag } from './media.mjs';

const root = new URL( '../', import.meta.url );
// The video's output, and the screen captured for it. Every captured screen
// has the output's aspect (16:9): a different one would be stretched or
// letterboxed. Change them together; the recorder checks every frame.
const OUT = { width: 1920, height: 1080 };
const W = 1280;
const H = 720;
const SCALE = OUT.width / W; // 1920×1080 frames.
if ( Math.abs( W / H - OUT.width / OUT.height ) > 0.001 || H * SCALE !== OUT.height ) {
	throw new Error( `The captured screen (${ W }×${ H }) doesn't have the video's aspect (${ OUT.width }×${ OUT.height }).` );
}
// Playground CLI's default port: the command on screen is the one to type.
const CLI_PORT = 9400;
const GITHUB_PATCH = process.env.EIZPORT_GITHUB_PATCH ?? join( homedir(), 'workspace/eizport-notes/patches/install-now-from-github.patch' );
// The pace a person can follow (about 1 min 30 s in all): cursor moves,
// the beat after each click, and the time to read each caption.
const PACE = { moveSteps: 32, stepMs: 22, afterClick: 1.5, readChars: 32, readMax: 3.5 };

// --locale <code>=<its repository>: the video in that language, written there.
const args = process.argv.slice( 2 );
const [ localeCode, localeRepo ] = args.includes( '--locale' ) ? args[ args.indexOf( '--locale' ) + 1 ].split( '=' ) : [];
const locale = localeCode
	? JSON.parse( readFileSync( new URL( 'translations.json', root ), 'utf8' ) ).locales.find( ( item ) => item.code === localeCode )
	: null;
if ( localeCode && ( ! locale || ! localeRepo ) ) {
	console.error( '--locale needs a language of translations.json and its repository: --locale br=../../eizport-ptb' );
	process.exit( 1 );
}

/** Scenes from video/STORYBOARD.md: "## N. id — Title", "- Caption:", "- Hold:". */
export function readStoryboard() {
	const text = readFileSync( new URL( 'video/STORYBOARD.md', root ), 'utf8' );
	return [ ...text.matchAll( /^## \d+\. ([a-z-]+) — .+\n([\s\S]*?)(?=^## |(?![\s\S]))/gm ) ].map( ( [ , id, body ] ) => {
		const field = ( name ) => body.match( new RegExp( `^- ${ name }: (.+)$`, 'm' ) )?.[ 1 ].trim() ?? '';
		return { id, caption: field( 'Caption' ), hold: Number( field( 'Hold' ) || 1 ) };
	} );
}

/**
 * A translation's video files: video/captions.txt ("id: text" lines) and
 * video/voice.json ({ voice, rate, dot, say: { WORD: "spoken" } }).
 *
 * @param {string} repo The translation's repository.
 */
function translationVideo( repo ) {
	const file = ( name ) => resolve( repo, 'video', name );
	if ( ! existsSync( file( 'captions.txt' ) ) ) throw new Error( `No ${ file( 'captions.txt' ) }.` );
	const captions = Object.fromEntries(
		readFileSync( file( 'captions.txt' ), 'utf8' )
			.split( '\n' )
			.map( ( l ) => l.match( /^([a-z-]+):\s*(.+)$/ ) )
			.filter( Boolean )
			.map( ( m ) => [ m[ 1 ], m[ 2 ].trim() ] )
	);
	const voice = existsSync( file( 'voice.json' ) ) ? JSON.parse( readFileSync( file( 'voice.json' ), 'utf8' ) ) : {};
	return { captions, voice };
}

// ── Overlay: a cursor and a click ripple, drawn in every page. ─────────────
const overlay = () => {
	const install = () => {
		if ( document.getElementById( 'eiz-overlay' ) || ! document.body ) return;
		const style = document.createElement( 'style' );
		style.textContent = `
			#eiz-overlay{position:fixed;inset:0;pointer-events:none;z-index:2147483647}
			#eiz-cursor{position:absolute;left:0;top:0;width:26px;height:26px;transform:translate(-3px,-2px)}
			.eiz-ripple{position:absolute;width:44px;height:44px;margin:-22px 0 0 -22px;border-radius:50%;border:3px solid #3858e9;background:rgba(56,88,233,.18);animation:eiz-r .55s ease-out forwards}
			@keyframes eiz-r{from{transform:scale(.3);opacity:1}to{transform:scale(1.4);opacity:0}}`;
		const layer = document.createElement( 'div' );
		layer.id = 'eiz-overlay';
		layer.innerHTML =
			'<svg id="eiz-cursor" viewBox="0 0 24 24"><path d="M3 2l7.5 19 2.6-7.6L21 10.8z" fill="#1e1e1e" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>';
		document.documentElement.append( style, layer );
		const pos = JSON.parse( sessionStorage.getItem( 'eiz-pos' ) || '[640,360]' );
		layer.firstChild.style.translate = `${ pos[ 0 ] }px ${ pos[ 1 ] }px`;
	};
	window.__eiz = {
		move( x, y ) {
			install();
			document.getElementById( 'eiz-cursor' ).style.translate = `${ x }px ${ y }px`;
			sessionStorage.setItem( 'eiz-pos', JSON.stringify( [ x, y ] ) );
		},
		ripple( x, y ) {
			install();
			const r = document.createElement( 'div' );
			r.className = 'eiz-ripple';
			r.style.left = x + 'px';
			r.style.top = y + 'px';
			document.getElementById( 'eiz-overlay' ).append( r );
			setTimeout( () => r.remove(), 700 );
		},
	};
	document.addEventListener( 'DOMContentLoaded', install );
	if ( document.readyState !== 'loading' ) install();
};

// ── Recorder: Chrome's screencast frames, with sped-up stretches. ──────────
class Recorder {
	constructor( page, dir ) {
		this.page = page;
		this.dir = dir;
		this.frames = [];
		this.speed = [];
		this.marks = {};
	}
	now() {
		return performance.now() / 1000;
	}
	async start() {
		this.cdp = await this.page.context().newCDPSession( this.page );
		this.cdp.on( 'Page.screencastFrame', async ( { data, metadata, sessionId } ) => {
			// A screen in another aspect (a resized viewport, a scene's own page) fails the recording.
			if ( ! this.wrongAspect && Math.abs( metadata.deviceWidth / metadata.deviceHeight - OUT.width / OUT.height ) > 0.01 ) {
				const scene = Object.keys( this.marks ).at( -1 ) ?? 'the start';
				this.wrongAspect = `${ metadata.deviceWidth }×${ metadata.deviceHeight } (in ${ scene })`;
			}
			const file = join( this.dir, `f${ String( this.frames.length ).padStart( 6, '0' ) }.jpg` );
			writeFileSync( file, Buffer.from( data, 'base64' ) );
			this.frames.push( { file, t: this.now() } );
			await this.cdp.send( 'Page.screencastFrameAck', { sessionId } ).catch( () => {} );
		} );
		await this.cdp.send( 'Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: OUT.width, maxHeight: OUT.height } );
		this.t0 = this.now();
		this.speed.push( { t: this.t0, factor: 1 } );
	}
	fast( factor = 4 ) {
		this.speed.push( { t: this.now(), factor } );
	}
	normal() {
		this.speed.push( { t: this.now(), factor: 1 } );
	}
	mark( name ) {
		this.marks[ name ] = this.now();
	}
	async stop() {
		await this.page.waitForTimeout( 300 );
		this.t1 = this.now();
		await this.cdp.send( 'Page.stopScreencast' );
		if ( this.wrongAspect ) {
			throw new Error( `A captured screen was ${ this.wrongAspect }, not the video's aspect (${ OUT.width }×${ OUT.height }): keep every scene at ${ W }×${ H }.` );
		}
	}
	/** Wall time → video time, sped-up stretches compressed. */
	warp( t ) {
		let out = 0;
		for ( let i = 0; i < this.speed.length; i++ ) {
			const from = this.speed[ i ].t;
			const to = Math.min( this.speed[ i + 1 ]?.t ?? Infinity, t );
			if ( to > from ) out += ( to - from ) / this.speed[ i ].factor;
		}
		return out;
	}
}

// ── Acting: a cursor that moves and clicks like a person. ──────────────────
const pause = ( page, s ) => page.waitForTimeout( s * 1000 );
async function moveTo( page, locator ) {
	await locator.scrollIntoViewIfNeeded();
	const box = await locator.boundingBox();
	const x = box.x + Math.min( box.width / 2, 60 );
	const y = box.y + box.height / 2;
	const [ fx, fy ] = await page.evaluate( () => JSON.parse( sessionStorage.getItem( 'eiz-pos' ) || '[640,360]' ) );
	for ( let i = 1; i <= PACE.moveSteps; i++ ) {
		const k = i / PACE.moveSteps;
		const e = k < 0.5 ? 2 * k * k : 1 - Math.pow( -2 * k + 2, 2 ) / 2;
		await page.evaluate( ( [ a, b ] ) => window.__eiz?.move( a, b ), [ fx + ( x - fx ) * e, fy + ( y - fy ) * e ] );
		await page.waitForTimeout( PACE.stepMs );
	}
	return [ x, y ];
}
async function click( page, locator, { after = 0.4 } = {} ) {
	const [ x, y ] = await moveTo( page, locator );
	await pause( page, 0.2 );
	await page.evaluate( ( [ a, b ] ) => window.__eiz?.ripple( a, b ), [ x, y ] );
	await locator.click();
	await pause( page, after * PACE.afterClick );
}
/** Navigates with the loading sped up. */
async function goto( page, url, rec ) {
	rec.fast( 5 );
	await page.goto( url );
	await page.waitForLoadState( 'networkidle' ).catch( () => {} );
	rec.normal();
	await pause( page, 0.3 );
}
/** Waits sped up until `done` resolves. */
async function fastUntil( rec, factor, done ) {
	rec.fast( factor );
	try {
		return await done;
	} finally {
		rec.normal();
	}
}

// ── Cards and the terminal: drawn by the page itself. ──────────────────────
const mark = readFileSync( new URL( 'src/assets/brand/mark.svg', root ), 'utf8' );
const card = ( body ) => `<!doctype html><html><head><meta charset="utf-8"><style>
	html,body{margin:0;height:100%}
	body{display:grid;place-items:center;background:linear-gradient(120deg,#1d35b4,#3858e9);color:#fff;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
	.c{display:grid;justify-items:center;gap:22px;text-align:center}
	.mark svg{width:120px;height:120px}
	h1{margin:0;font-size:62px;letter-spacing:-.02em}h1 b{color:#f2994a}
	p{margin:0;font-size:30px;opacity:.92}
	.url{font-size:34px;font-weight:700;background:#fff;color:#1d35b4;border-radius:12px;padding:10px 24px}
	.term{width:1120px;max-height:620px;background:#0f1115;border-radius:16px;box-shadow:0 30px 60px rgba(0,0,0,.4);text-align:left;overflow:hidden}
	.term .bar{display:flex;gap:8px;padding:14px 16px;background:#1b1e24}.term .bar i{width:12px;height:12px;border-radius:50%;background:#555;display:block}
	.term pre{margin:0;padding:20px 26px;font:17px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace;color:#e6e6e6;white-space:pre-wrap;word-break:break-all}
	.p{color:#f2994a}.o{color:#9ec1ff}.ok{color:#7ee2a8}
	</style></head><body><div class="c">${ body }</div></body></html>`;
const esc = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' );

/** Shows a terminal and types `command` into it. */
async function terminal( page, command ) {
	await page.setContent( card( '<div class="term"><div class="bar"><i></i><i></i><i></i></div><pre id="t"></pre></div>' ) );
	for ( let i = 1; i <= command.length; i += 2 ) {
		await page.evaluate( ( html ) => ( document.getElementById( 't' ).innerHTML = html ), `<span class="p">$</span> ${ esc( command.slice( 0, i ) ) }` );
		await page.waitForTimeout( 14 );
	}
	await pause( page, 0.4 );
}
const print = ( page, html ) => page.evaluate( ( h ) => ( document.getElementById( 't' ).innerHTML += h ), html );

// ── The scenes (ids as in STORYBOARD.md). ──────────────────────────────────
const scenes = {
	async intro( { page } ) {
		await page.setContent( card( `<div class="mark">${ mark }</div><h1>Ei<b>z</b>port</h1>` ) );
	},
	async choose( { page, rec } ) {
		await goto( page, sites.source.url + exportPath, rec );
		await exportScreen.ready( page );
		rec.mark( 'poster' );
		await click( page, exportScreen.row( page, l( 'Pages' ) ).getByRole( 'button', { name: l( 'Choose items' ) } ), { after: 0.8 } );
		await click( page, exportScreen.panel( page, l( 'Pages' ) ).getByRole( 'checkbox', { name: 'Home', exact: true } ), { after: 0.7 } );
		await click( page, exportScreen.panel( page, l( 'Pages' ) ).getByRole( 'button', { name: l( 'Close' ), exact: true } ), { after: 1 } );
		// The Home suggests the products its block shows and its settings: check those rows.
		await click( page, exportScreen.row( page, l( 'Products' ) ).getByRole( 'checkbox' ).first(), { after: 0.8 } );
		await click( page, exportScreen.row( page, l( 'Options' ) ).getByRole( 'checkbox' ).first() );
	},
	async download( { page, rec, files } ) {
		// Details (closed to start with): the format, the name and the media files.
		await click( page, page.locator( '.eizport-file-panel__toggle' ), { after: 0.6 } );
		await moveTo( page, page.getByRole( 'checkbox', { name: l( 'Media files' ) } ) );
		await pause( page, 0.4 );
		await click( page, page.getByRole( 'button', { name: l( 'Export' ), exact: true } ).first(), { after: 0 } );
		const snackbar = page.getByTestId( 'snackbar' );
		await fastUntil( rec, 3, snackbar.waitFor( { timeout: 120000 } ) );
		await pause( page, 0.6 );
		const download = page.waitForEvent( 'download' );
		await click( page, snackbar.getByText( l( 'Download' ), { exact: true } ) );
		files.wxrz = join( files.dir, ( await download ).suggestedFilename() );
		await ( await download ).saveAs( files.wxrz );
	},
	async import( { page, rec, files } ) {
		// Tools → Import opens the WordPress Importer directly.
		await goto( page, sites.target.url + '/wp-admin/import.php', rec );
		if ( ! /import=wordpress/.test( page.url() ) ) {
			await click( page, page.locator( 'a[href*="import=wordpress"]' ).first(), { after: 0 } );
		}
		await page.waitForLoadState( 'networkidle' );
		// The plugin's file button (the browser's field is hidden behind it).
		const chooser = page.waitForEvent( 'filechooser' );
		await click( page, page.getByRole( 'button', { name: l( 'Choose file' ) } ), { after: 0 } );
		await ( await chooser ).setFiles( files.wxrz );
		await pause( page, 0.8 );
		await click( page, page.getByRole( 'button', { name: l( 'Upload file and import' ) } ), { after: 0 } );
		await page.waitForLoadState( 'networkidle' );
		await page.getByRole( 'combobox' ).first().selectOption( { label: 'Admin (admin)' } );
		await pause( page, 0.5 );
		await click( page, page.getByRole( 'button', { name: l( 'Submit' ) } ), { after: 0 } );
		await fastUntil( rec, 4, page.getByText( l( 'All done.' ) ).first().waitFor( { timeout: 180000 } ) );
	},
	async result( { page, rec } ) {
		await goto( page, sites.target.url + '/', rec );
		await pause( page, 1 );
		await page.mouse.wheel( 0, 380 );
	},
	async 'as-plugin'( { page, rec, files } ) {
		// The same choices, saved as default (off camera), then the file as a plugin.
		saveHomeAsDefault();
		await goto( page, sites.source.url + exportPath, rec );
		await exportScreen.ready( page );
		await click( page, page.locator( '.eizport-file-panel__toggle' ), { after: 0.4 } );
		await click( page, page.getByRole( 'checkbox', { name: l( 'As plugin' ) } ), { after: 0.6 } );
		await withGithubPatch( async () => {
			await click( page, page.getByRole( 'button', { name: l( 'Export' ), exact: true } ).first(), { after: 0 } );
			const snackbar = page.getByTestId( 'snackbar' );
			await fastUntil( rec, 3, snackbar.waitFor( { timeout: 120000 } ) );
			await pause( page, 0.5 );
			const download = page.waitForEvent( 'download' );
			await click( page, snackbar.getByText( l( 'Download' ), { exact: true } ) );
			files.plugin = join( files.dir, ( await download ).suggestedFilename() );
			await ( await download ).saveAs( files.plugin );
		} );
		// Client Site: no Eizport, no importer. Plugins → Add Plugin → Upload Plugin.
		await goto( page, sites.client.url + '/wp-admin/plugin-install.php?tab=upload', rec );
		const chooser = page.waitForEvent( 'filechooser' );
		await click( page, page.locator( '#pluginzip' ), { after: 0 } );
		await ( await chooser ).setFiles( files.plugin );
		await pause( page, 0.5 );
		await click( page, page.locator( '#install-plugin-submit' ), { after: 0 } );
		await page.waitForLoadState( 'networkidle' );
		await click( page, page.getByRole( 'link', { name: l( 'Activate Plugin' ) } ), { after: 0 } );
		await page.waitForLoadState( 'networkidle' );
		await click( page, page.getByRole( 'link', { name: l( 'open the import screen' ) } ).first(), { after: 0 } );
		await page.waitForLoadState( 'networkidle' );
		// Install Now: Eizport, installed and activated on the same screen.
		await click( page, page.locator( '.eizport-install-now' ), { after: 0 } );
		await fastUntil( rec, 4, page.locator( '.eizport-requirement__after' ).waitFor( { timeout: 180000 } ) );
		await pause( page, 0.6 );
		await click( page, page.locator( '.eizport-requirement__after a' ), { after: 0 } );
		await page.waitForLoadState( 'networkidle' );
		// Then the WordPress Importer, the same way; Run import is ready.
		await click( page, page.locator( '.eizport-install-now' ), { after: 0 } );
		const run = page.locator( '.eizport-bundle-import button[type="submit"]' );
		await fastUntil( rec, 4, page.waitForFunction( () => ! document.querySelector( '.eizport-bundle-import button[type="submit"]' )?.disabled, null, { timeout: 180000 } ) );
		await pause( page, 0.6 );
		await click( page, run, { after: 0 } );
		await fastUntil( rec, 4, page.getByRole( 'heading', { name: l( 'Imported' ), exact: true } ).waitFor( { timeout: 180000 } ) );
		await pause( page, 0.6 );
	},
	async 'playground-link'( { page, rec, files } ) {
		// The same choices, saved as default (off camera), so the screen opens with them: now as a Playground .zip.
		saveHomeAsDefault();
		await goto( page, sites.source.url + exportPath, rec );
		await exportScreen.ready( page );
		if ( ! /1 item/.test( await exportScreen.row( page, l( 'Pages' ) ).innerText() ) ) throw new Error( 'The Export screen lost the Home selection.' );
		await click( page, page.locator( '.eizport-file-panel__toggle' ), { after: 0.4 } );
		await click( page, page.getByRole( 'radio', { name: 'Playground' } ) );
		await click( page, page.getByRole( 'button', { name: l( 'Export' ), exact: true } ).first(), { after: 0 } );
		await fastUntil( rec, 4, page.getByTestId( 'snackbar' ).waitFor( { timeout: 180000 } ) );
		files.zip = newestCopy( '.zip' );
		await goto( page, sites.source.url + settingsPath( 'copies' ), rec );
		await page.getByRole( 'table' ).waitFor();
		const row = page.getByRole( 'row' ).filter( { hasText: l( 'Playground .zip' ) } ).first();
		await click( page, row.getByRole( 'button', { name: l( 'Actions' ) } ) );
		const popup = page.context().waitForEvent( 'page', { timeout: 5000 } ).catch( () => null );
		await click( page, page.getByRole( 'menuitem', { name: /Playground/ } ).first(), { after: 0 } );
		const other = await popup;
		if ( other ) await other.waitForURL( /playground\.wordpress\.net/, { waitUntil: 'commit', timeout: 15000 } );
		const url = other ? other.url() : page.url();
		if ( other ) await other.close();
		rec.fast( 10 );
		await page.goto( url );
		await waitForFrame( page, ( f ) => f.locator( 'h1, .wp-block-post-title' ).first().isVisible().catch( () => false ), 240000 );
		await page.waitForTimeout( 3000 );
		rec.normal();
		await pause( page, 0.8 );
	},
	async 'playground-cli'( { page, rec, files } ) {
		copyFileSync( files.zip, join( files.dir, 'eiz-coffee.zip' ) );
		if ( await portInUse( CLI_PORT ) ) throw new Error( `Port ${ CLI_PORT } is in use: Playground CLI's default port must be free for the recording.` );
		await terminal( page, 'npx @wp-playground/cli server --blueprint=eiz-coffee.zip' );
		const cli = spawn( 'npx', [ '-y', '@wp-playground/cli@latest', 'server', '--blueprint=eiz-coffee.zip' ], { cwd: files.dir } );
		files.cli = cli;
		const ready = new Promise( ( resolve, reject ) => {
			let out = '';
			const onData = async ( chunk ) => {
				out += chunk;
				const lines = String( chunk ).split( '\n' ).filter( ( l ) => l.trim() && ! /^npm (notice|warn)/.test( l ) );
				if ( lines.length ) await print( page, '\n' + lines.map( ( l ) => ( /Ready!/.test( l ) ? `<span class="ok">${ esc( l ) }</span>` : esc( l ) ) ).join( '\n' ) ).catch( () => {} );
				if ( /Ready!/.test( out ) ) resolve();
			};
			cli.stdout.on( 'data', onData );
			cli.stderr.on( 'data', onData );
			cli.on( 'exit', ( code ) => reject( new Error( `Playground CLI exited (${ code }): ${ out }` ) ) );
		} );
		await fastUntil( rec, 12, ready );
		await pause( page, 1 );
		await goto( page, `http://127.0.0.1:${ CLI_PORT }/`, rec );
		await pause( page, 0.8 );
	},
	async outro( { page } ) {
		await page.setContent(
			card( `<div class="mark">${ mark }</div><h1>Ei<b>z</b>port</h1><p>${ free }</p><div class="url">eizport.github.io${ locale ? `/${ locale.code }` : '' }</div>` )
		);
	},
};

/** Polls the page's frames (Playground nests the site in iframes). */
async function waitForFrame( page, test, timeout ) {
	const end = Date.now() + timeout;
	while ( Date.now() < end ) {
		for ( const frame of page.frames() ) {
			if ( frame !== page.mainFrame() && /scope:|127\.0\.0\.1|wordpress/.test( frame.url() ) && ( await test( frame ) ) ) return;
		}
		await page.waitForTimeout( 1000 );
	}
	throw new Error( 'Playground did not show the site in time.' );
}

/** The newest copy on the source site with this extension. */
function newestCopy( ext ) {
	const dir = join( sites.source.path, 'wp-content/uploads/eizport/tmp' );
	return readdirSync( dir )
		.filter( ( f ) => f.endsWith( ext ) )
		.map( ( f ) => join( dir, f ) )
		.sort( ( a, b ) => statSync( b ).mtimeMs - statSync( a ).mtimeMs )[ 0 ];
}

/** Saves the Home with what it suggests as the export default (off camera). */
function saveHomeAsDefault() {
	sites.source.wp(
		'eval',
		"wp_set_current_user( 1 ); \\Eizport\\Defaults\\save( array( 'types' => array( 'page' => array( 'ids' => array( (int) get_option( 'page_on_front' ) ) ) ), 'settings' => array( 'include_suggested' => array( 'product', '__options', '__reviews' ) ) ) );"
	);
}

/**
 * Runs `step` with the Install Now from GitHub patch applied to the source
 * site's copy of the bundle plugin template, then puts the copy back.
 */
async function withGithubPatch( step ) {
	if ( ! existsSync( GITHUB_PATCH ) ) throw new Error( `No ${ GITHUB_PATCH }: set EIZPORT_GITHUB_PATCH.` );
	const template = join( sites.source.path, 'wp-content/plugins/eizport/templates/bundle-plugin.php.tpl' );
	const original = readFileSync( template );
	const work = join( tmpdir(), `eizport-patch-${ process.pid }` );
	mkdirSync( join( work, 'templates' ), { recursive: true } );
	writeFileSync( join( work, 'templates/bundle-plugin.php.tpl' ), original );
	execFileSync( 'patch', [ '-s', '-p1', '-d', work, '-i', GITHUB_PATCH ] );
	copyFileSync( join( work, 'templates/bundle-plugin.php.tpl' ), template );
	try {
		await step();
	} finally {
		writeFileSync( template, original );
		rmSync( work, { recursive: true, force: true } );
	}
}

/** Whether something already listens on a local port. */
const portInUse = ( port ) =>
	new Promise( ( resolve ) => {
		const socket = createConnection( { host: '127.0.0.1', port } );
		socket.once( 'connect', () => ( socket.destroy(), resolve( true ) ) );
		socket.once( 'error', () => resolve( false ) );
	} );

function resetSites() {
	sites.target.wp( 'site', 'empty', '--uploads', '--yes' );
	// The client: WooCommerce only (no Eizport, no importer, no bundle plugin) and no content.
	for ( const plugin of sites.client.wp( 'plugin', 'list', '--field=name', '--status=active,inactive' ).split( '\n' ).filter( ( name ) => name && name !== 'woocommerce' ) ) {
		sites.client.wp( 'plugin', 'deactivate', plugin, '--quiet' );
		sites.client.wp( 'plugin', 'delete', plugin, '--quiet' );
	}
	sites.client.wp( 'site', 'empty', '--uploads', '--yes' );
	sites.client.wp( 'eval', "foreach ( array( 'eizport_settings', 'eizport_bundle_plugins_imported', 'eizport_bundle_plugins_before' ) as $name ) { delete_option( $name ); }" );
	// The plugin's defaults: no settings or export defaults saved by an earlier recording.
	for ( const site of [ sites.source, sites.target ] ) {
		site.wp( 'eval', "delete_option( 'eizport_settings' ); delete_option( 'eizport_export_defaults' ); delete_metadata( 'user', 0, 'eizport_export_defaults', '', true );" );
	}
	sites.source.wp( 'eval', "wp_set_current_user( 1 ); wp_get_ability( 'eizport/delete-copies' )->execute( array( 'all' => true ) );" );
}

const vttTime = ( s ) => new Date( Math.max( 0, s ) * 1000 ).toISOString().slice( 11, 23 );
const vtt = ( cues ) => 'WEBVTT\n\n' + cues.map( ( c, i ) => `${ i + 1 }\n${ vttTime( c.start ) } --> ${ vttTime( c.end ) }\n${ c.text }\n` ).join( '\n' );

// ── Sound: narration and an ambient track. ────────────────────────────────
// English; another language brings its own (its repository's video/voice.json).
const ENGLISH_VOICE = { voice: 'Samantha', rate: 180, dot: ' dot ', say: { AI: 'A.I.' } };

/** A caption as it should be spoken: addresses, symbols and acronyms read aloud. */
function speakable( text, voice ) {
	const dot = voice.dot ?? ENGLISH_VOICE.dot;
	let spoken = text
		.replace( /\s*·\s*/g, '. ' )
		.replace( /\s*—\s*/g, ', ' )
		.replace( /([a-z0-9-]+)\.(github\.io|wordpress\.net|se)\b(\/[a-z]+)?/gi, ( match ) => match.split( '.' ).join( dot ).replace( '/', ' ' ) )
		.replace( /\.zip\b/g, ' zip' );
	for ( const [ word, said ] of Object.entries( voice.say ?? {} ) ) {
		spoken = spoken.replace( new RegExp( `\\b${ word }\\b`, 'g' ), said );
	}
	return spoken;
}

/** Narrates each scene with `say`: file and length, by scene id. */
function narrate( scenes, voice, dir ) {
	const out = {};
	for ( const scene of scenes ) {
		const file = join( dir, `voice-${ scene.id }.aiff` );
		execFileSync( 'say', [ '-v', voice.voice, '-r', String( voice.rate ?? 180 ), '-o', file, speakable( scene.caption, voice ) ] );
		const seconds = Number( execFileSync( 'afinfo', [ file ], { encoding: 'utf8' } ).match( /estimated duration: ([\d.]+)/ )?.[ 1 ] ?? 0 );
		out[ scene.id ] = { file, seconds };
	}
	return out;
}

/**
 * The soundtrack: an ambient pad (four soft chords, synthesized), ducked
 * under the narration placed at each scene's start. A WAV of `length` s.
 */
function soundtrack( voices, starts, length, dir ) {
	const chords = [
		[ 130.81, 196.0, 261.63, 329.63, 493.88 ], // Cmaj7
		[ 110.0, 164.81, 220.0, 261.63, 392.0 ], // Am7
		[ 87.31, 130.81, 174.61, 220.0, 329.63 ], // Fmaj7
		[ 98.0, 146.83, 196.0, 246.94, 329.63 ], // G6
	];
	const pad = chords.map( ( notes, i ) => {
		const file = join( dir, `chord-${ i }.wav` );
		const wave = notes.map( ( f ) => `sin(2*PI*${ f }*t)` ).join( '+' );
		execFileSync( ffmpeg, [ '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', `aevalsrc=0.05*(${ wave })*(0.75+0.25*sin(2*PI*0.2*t)):s=48000:d=8`, '-af', 'afade=t=in:d=2,afade=t=out:st=6:d=2,lowpass=f=1400', '-ac', '2', file ] );
		return file;
	} );
	const loop = join( dir, 'pad.wav' );
	writeFileSync( join( dir, 'pad.txt' ), pad.map( ( f ) => `file '${ f }'` ).join( '\n' ) );
	execFileSync( ffmpeg, [ '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', join( dir, 'pad.txt' ), '-af', 'aecho=0.8:0.6:120|240:0.3|0.2', loop ] );

	const ids = Object.keys( voices );
	const inputs = [ '-stream_loop', '-1', '-i', loop, ...ids.flatMap( ( id ) => [ '-i', voices[ id ].file ] ) ];
	const placed = ids.map( ( id, i ) => {
		const ms = Math.round( ( starts[ id ] + 0.3 ) * 1000 );
		return `[${ i + 1 }:a]aformat=sample_rates=48000:channel_layouts=stereo,adelay=${ ms }|${ ms }[v${ i }]`;
	} );
	const graph = [
		...placed,
		`${ ids.map( ( _, i ) => `[v${ i }]` ).join( '' ) }amix=inputs=${ ids.length }:normalize=0,apad[voice]`,
		'[voice]asplit[v][key]',
		`[0:a]atrim=0:${ length },afade=t=in:d=2,afade=t=out:st=${ Math.max( 0, length - 3 ) }:d=3,volume=1.6[pad]`,
		'[pad][key]sidechaincompress=threshold=0.02:ratio=6:attack=40:release=600[bed]',
		`[bed][v]amix=inputs=2:normalize=0,atrim=0:${ length },alimiter=limit=0.9[out]`,
	];
	const file = join( dir, 'soundtrack.wav' );
	execFileSync( ffmpeg, [ '-y', '-loglevel', 'error', ...inputs, '-filter_complex', graph.join( ';' ), '-map', '[out]', '-ar', '48000', file ] );
	return file;
}

// ── Run. ───────────────────────────────────────────────────────────────────
// In another language: its strings (the outro's "Free and open source" is the
// first part of the landing's badges), captions and voice, all in its repository.
const strings = locale ? JSON.parse( readFileSync( resolve( localeRepo, 'strings.json' ), 'utf8' ) ) : {};
const free = strings.home?.badges?.split( ' · ' )[ 0 ] ?? 'Free and open source';
const translation = locale ? translationVideo( localeRepo ) : null;
const captionsIn = translation?.captions;
const voice = translation ? { ...ENGLISH_VOICE, say: {}, ...translation.voice } : ENGLISH_VOICE;
const storyboard = readStoryboard().map( ( scene ) => ( captionsIn?.[ scene.id ] ? { ...scene, caption: captionsIn[ scene.id ] } : scene ) );
if ( locale ) useLocale( locale.wp, resolve( localeRepo ) );
const missing = storyboard.filter( ( s ) => ! scenes[ s.id ] || ! s.caption ).map( ( s ) => s.id );
if ( missing.length ) throw new Error( `STORYBOARD.md scenes without an action or a caption: ${ missing.join( ', ' ) }` );

const dir = join( tmpdir(), `eizport-video-${ process.pid }` );
mkdirSync( join( dir, 'frames' ), { recursive: true } );
const voices = narrate( storyboard, voice, dir );
resetSites();

// Playground on playground.wordpress.net fetches the copy from localhost: allow
// local network access, as a person does when Chrome asks.
// Headless Chrome's screencast captures its window, not the page's viewport:
// without a window of the viewport's size the frames come out 1280×633 and
// get stretched to 16:9. The scale factor makes them 1920×1080 frames.
const browser = await launch( {
	args: [
		'--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessRespectPreflightResults,BlockInsecurePrivateNetworkRequests',
		`--window-size=${ W },${ H }`,
		`--force-device-scale-factor=${ SCALE }`,
	],
} );
const context = await browser.newContext( inLocale( { viewport: { width: W, height: H }, deviceScaleFactor: SCALE, acceptDownloads: true } ) );
await context.addInitScript( overlay );
const page = await context.newPage();
for ( const site of [ sites.source, sites.target, sites.client ] ) await page.goto( `${ site.url }/studio-auto-login?redirect_to=${ encodeURIComponent( '/wp-admin/' ) }` );
await page.setContent( card( '' ) );

const rec = new Recorder( page, join( dir, 'frames' ) );
const files = { dir };
const times = [];
try {
	await rec.start();
	for ( const scene of storyboard ) {
		console.log( `  ${ scene.id }` );
		const start = rec.now();
		await scenes[ scene.id ]( { page, rec, files } );
		// Long enough to read the caption, and to hear its narration to the end.
		const shown = rec.warp( rec.now() ) - rec.warp( start );
		const spoken = voices[ scene.id ].seconds + 0.3 + 0.6 - shown;
		await pause( page, Math.max( scene.hold + Math.min( PACE.readMax, scene.caption.length / PACE.readChars ), spoken ) );
		times.push( { id: scene.id, start, end: rec.now() } );
	}
	await rec.stop();
} finally {
	files.cli?.kill();
	await browser.close();
	resetSites();
	if ( locale ) useLocale( 'en_US' );
}

// Frames → MP4/WebM, each frame for its (sped-up) duration.
const list = [];
rec.frames.forEach( ( frame, i ) => {
	const next = rec.frames[ i + 1 ]?.t ?? rec.t1;
	list.push( `file '${ frame.file }'`, `duration ${ Math.max( 0.001, rec.warp( next ) - rec.warp( frame.t ) ).toFixed( 4 ) }` );
} );
list.push( `file '${ rec.frames.at( -1 ).file }'` );
writeFileSync( join( dir, 'frames.txt' ), list.join( '\n' ) );

// Another language's video goes to its repository; English to the site and video/out/.
const publicDir = locale ? resolve( localeRepo, 'assets/video' ) + '/' : new URL( 'public/video/', root ).pathname;
const outDir = new URL( 'video/out/', root ).pathname;
mkdirSync( publicDir, { recursive: true } );
mkdirSync( outDir, { recursive: true } );
const starts = Object.fromEntries( times.map( ( t ) => [ t.id, rec.warp( t.start ) ] ) );
const sound = soundtrack( voices, starts, rec.warp( rec.t1 ), dir );
const input = [ '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', join( dir, 'frames.txt' ), '-i', sound, '-map', '0:v', '-map', '1:a', '-vf', `fps=30,scale=${ OUT.width }:${ OUT.height }:flags=lanczos,format=yuv420p` ];
execFileSync( ffmpeg, [ ...input, '-c:v', 'libx264', '-preset', 'slow', '-crf', '24', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', join( publicDir, 'demo.mp4' ) ] );
execFileSync( ffmpeg, [ ...input, '-c:v', 'libvpx-vp9', '-b:v', '0', '-crf', '38', '-row-mt', '1', '-c:a', 'libopus', '-b:a', '96k', join( publicDir, 'demo.webm' ) ] );
if ( locale ) {
	const captions = vtt( times.map( ( t ) => ( { start: rec.warp( t.start ), end: rec.warp( t.end ), text: storyboard.find( ( s ) => s.id === t.id ).caption } ) ) );
	writeFileSync( join( publicDir, `demo.${ locale.code }.vtt` ), captions );
	const posterFrame = join( dir, 'poster.png' );
	execFileSync( ffmpeg, [ '-y', '-loglevel', 'error', '-ss', String( rec.warp( rec.marks.poster ?? rec.t0 ) + 1 ), '-i', join( publicDir, 'demo.mp4' ), '-frames:v', '1', posterFrame ] );
	await sharp( posterFrame ).webp( { quality: 90 } ).toFile( join( publicDir, 'demo-poster.webp' ) );
	rmSync( dir, { recursive: true, force: true } );
	uploadVideo( locale.repo, publicDir, versionTag() );
	console.log( `Done (${ rec.warp( rec.t1 ).toFixed( 1 ) } s, ${ locale.code }): ${ publicDir }` );
	process.exit( 0 );
}
copyFileSync( join( publicDir, 'demo.mp4' ), join( outDir, 'demo.mp4' ) );

// Captions: English (the site and YouTube). Other languages: their own recording, in their repository.
const cues = ( text ) => times.map( ( t ) => ( { start: rec.warp( t.start ), end: rec.warp( t.end ), text: text( t.id ) } ) );
const english = vtt( cues( ( id ) => storyboard.find( ( s ) => s.id === id ).caption ) );
writeFileSync( join( publicDir, 'demo.en.vtt' ), english );
writeFileSync( join( outDir, 'demo.en.vtt' ), english );

// Poster (the Export screen) and the YouTube thumbnail.
const poster = join( dir, 'poster.png' );
execFileSync( ffmpeg, [ '-y', '-loglevel', 'error', '-ss', String( rec.warp( rec.marks.poster ?? rec.t0 ) + 1 ), '-i', join( publicDir, 'demo.mp4' ), '-frames:v', '1', poster ] );
// The hero's cover (components/DemoVideo.astro): Astro makes its AVIF/WebP sizes.
await sharp( poster ).webp( { quality: 90 } ).toFile( new URL( 'src/assets/video/demo-poster.webp', root ).pathname );
await sharp( poster ).resize( 1280, 720 ).jpeg( { quality: 88 } ).toFile( join( outDir, 'thumbnail.jpg' ) );
rmSync( dir, { recursive: true, force: true } );

const captured = readCaptured();
captured.demo = { commit: execFileSync( 'git', [ 'rev-parse', 'HEAD' ], { encoding: 'utf8' } ).trim(), date: new Date().toISOString().slice( 0, 10 ) };
writeFileSync( capturedFile, JSON.stringify( captured, null, '\t' ) + '\n' );
writeFiles( captured );
if ( uploadVideo( 'eizport/eizport', publicDir, versionTag() ) ) {
	useTag( versionTag() );
}
console.log( `Done (${ rec.warp( rec.t1 ).toFixed( 1 ) } s): public/video/demo.*, video/out/` );
