/**
 * Downloads each language's translation (translations.json) and lays it out
 * for the build, in git-ignored folders — translated text never lives in
 * this repository:
 *
 * - .translations/<code>/          the language's repository (shallow clone)
 * - src/content/i18n/<code>/       strings.json, docs/ (image paths fixed) and
 *                                  status.json (each page translated,
 *                                  outdated or missing)
 * - src/assets/i18n/<code>/        translated screenshots and the video's poster,
 *                                  when it has them
 * - public/i18n/<code>/video/      the demo video in that language (from its
 *                                  repository's media-X.Y.Z release, media.json)
 *                                  and its captions
 *
 * A page is outdated when its English page changed after the commit in its
 * `translatedFrom`. A language that can't be downloaded is left out: the
 * site builds in the languages it has.
 *
 *   npm run translations                               download and lay out
 *   npm run translations -- --local br=../../eizport-ptb  use a local checkout
 *   npm run translations -- --status                   only report (npm run check)
 *   npm run translations -- --ref site                 each language's branch or tag instead of
 *                                                      its translations.json ref (the published
 *                                                      site: the translation of the released version)
 */
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { downloadVideo } from './media.mjs';

const site = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const config = JSON.parse( readFileSync( join( site, 'translations.json' ), 'utf8' ) );
const args = process.argv.slice( 2 );
const statusOnly = args.includes( '--status' );
const refIndex = args.indexOf( '--ref' );
const ref = refIndex >= 0 ? args[ refIndex + 1 ] : '';
const local = Object.fromEntries(
	args.flatMap( ( arg, index ) => ( arg === '--local' ? [ args[ index + 1 ].split( '=' ) ] : [] ) ).map( ( [ code, path ] ) => [ code, resolve( path ) ] )
);

const run = ( cwd, gitArgs ) => execFileSync( 'git', gitArgs, { cwd, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim();
const root = run( site, [ 'rev-parse', '--show-toplevel' ] );
// Paths below are relative to the repository's root.
const git = ( ...gitArgs ) => run( root, gitArgs );
const inRepo = ( path ) => relative( root, join( site, path ) );

/** Every .md under a folder, as slugs ("group/page"). */
const markdown = ( dir ) =>
	existsSync( dir )
		? readdirSync( dir, { recursive: true } )
				.filter( ( file ) => file.endsWith( '.md' ) )
				.map( ( file ) => file.replace( /\.md$/, '' ).split( '\\' ).join( '/' ) )
		: [];

/** The frontmatter's simple `key: value` lines. */
const frontmatter = ( text ) =>
	Object.fromEntries(
		[ ...( text.match( /^---\n([\s\S]*?)\n---/ )?.[ 1 ] ?? '' ).matchAll( /^(\w+):\s*(.*)$/gm ) ].map( ( [ , key, value ] ) => [
			key,
			value.replace( /^(["'])(.*)\1$/, '$2' ).replace( /\\"/g, '"' ),
		] )
	);

/** translated, or outdated when an English file changed after `from` (a commit). */
const freshness = ( from, files ) => {
	if ( ! from ) {
		return { status: 'outdated', note: 'no translatedFrom' };
	}
	try {
		git( 'cat-file', '-e', `${ from }^{commit}` );
	} catch {
		return { status: 'translated', note: `commit ${ from } not in this clone's history` };
	}
	try {
		git( 'diff', '--quiet', from, 'HEAD', '--', ...files.map( inRepo ) );
		return { status: 'translated' };
	} catch {
		return { status: 'outdated' };
	}
};

/** The language's repository: a local checkout, or a fresh shallow clone. */
const fetchRepo = ( locale ) => {
	if ( local[ locale.code ] ) {
		return local[ locale.code ];
	}
	const dir = join( site, '.translations', locale.code );
	const branch = ref || locale.ref;
	rmSync( dir, { recursive: true, force: true } );
	try {
		execFileSync( 'git', [ 'clone', '--quiet', '--depth', '1', '--branch', branch, `https://github.com/${ locale.repo }.git`, dir ], { stdio: 'pipe', env: { ...process.env, GIT_TERMINAL_PROMPT: '0' } } );
		return dir;
	} catch ( error ) {
		console.warn( `! ${ locale.code }: couldn't download ${ locale.repo }@${ branch } (${ String( error.stderr ?? error.message ).trim() }); building without it.` );
		return '';
	}
};

const englishDocs = markdown( join( site, 'src/content/docs' ) );
let warnings = 0;

for ( const locale of config.locales ) {
	// --status reports what the last download laid out (npm run check).
	if ( statusOnly ) {
		const file = join( site, 'src/content/i18n', locale.code, 'status.json' );
		if ( ! existsSync( file ) ) {
			console.log( `${ locale.code }: no translation downloaded (npm run translations).` );
			continue;
		}
		const { strings, pages } = JSON.parse( readFileSync( file, 'utf8' ) );
		const late = Object.entries( pages ).filter( ( [ , page ] ) => page.status !== 'translated' );
		warnings += late.length + ( strings === 'translated' ? 0 : 1 );
		console.log( `${ locale.code }: ${ Object.keys( pages ).length - late.length } of ${ Object.keys( pages ).length } pages translated; strings ${ strings }.` );
		for ( const [ slug, page ] of late ) console.log( `  ${ page.status }: docs/${ slug }.md` );
		continue;
	}
	const source = fetchRepo( locale );
	const out = join( site, 'src/content/i18n', locale.code );
	const assets = join( site, 'src/assets/i18n', locale.code );
	const media = join( site, 'public/i18n', locale.code );
	if ( ! source || ! existsSync( join( source, 'strings.json' ) ) ) {
		rmSync( out, { recursive: true, force: true } );
		rmSync( assets, { recursive: true, force: true } );
		rmSync( media, { recursive: true, force: true } );
		console.log( `${ locale.code }: no translation${ source ? ' (no strings.json)' : '' }.` );
		continue;
	}

	// Screenshots in the language, if it has them (same names as English).
	const shots = existsSync( join( source, 'assets/screenshots' ) )
		? readdirSync( join( source, 'assets/screenshots' ) ).filter( ( file ) => file.endsWith( '.webp' ) )
		: [];

	rmSync( out, { recursive: true, force: true } );
	rmSync( assets, { recursive: true, force: true } );
	rmSync( media, { recursive: true, force: true } );
	mkdirSync( out, { recursive: true } );
	// The demo video in the language: files served as they are, the poster through Astro's images.
	const video = join( source, 'assets/video' );
	await downloadVideo( locale.repo, video );
	const hasVideo = existsSync( join( video, 'demo.mp4' ) );
	if ( hasVideo ) {
		mkdirSync( join( media, 'video' ), { recursive: true } );
		for ( const file of readdirSync( video ).filter( ( f ) => /^demo\.(mp4|webm|[a-z-]+\.vtt)$/.test( f ) ) ) cpSync( join( video, file ), join( media, 'video', file ) );
		if ( existsSync( join( video, 'demo-poster.webp' ) ) ) {
			mkdirSync( join( assets, 'video' ), { recursive: true } );
			cpSync( join( video, 'demo-poster.webp' ), join( assets, 'video', 'demo-poster.webp' ) );
		}
	}
	cpSync( join( source, 'strings.json' ), join( out, 'strings.json' ) );
	if ( shots.length ) {
		mkdirSync( join( assets, 'screenshots' ), { recursive: true } );
		for ( const shot of shots ) cpSync( join( source, 'assets/screenshots', shot ), join( assets, 'screenshots', shot ) );
	}
	// Pages are written as if they were in src/content/docs/<group>/: their
	// images point two folders further up here, to the translated
	// screenshot when there is one.
	for ( const slug of markdown( join( source, 'docs' ) ) ) {
		const text = readFileSync( join( source, 'docs', `${ slug }.md` ), 'utf8' ).replace(
			/\]\(\.\.\/\.\.\/\.\.\/assets\/(screenshots\/([a-z0-9-]+\.webp))\)/g,
			( _match, path, file ) => `](../../../../../assets/${ shots.includes( file ) ? `i18n/${ locale.code }/${ path }` : path })`
		);
		mkdirSync( dirname( join( out, 'docs', `${ slug }.md` ) ), { recursive: true } );
		writeFileSync( join( out, 'docs', `${ slug }.md` ), text );
	}

	// Each page's state, and the strings'.
	const pages = {};
	const counts = { translated: 0, outdated: 0, missing: 0 };
	for ( const slug of englishDocs ) {
		const file = join( source, 'docs', `${ slug }.md` );
		if ( ! existsSync( file ) ) {
			pages[ slug ] = { status: 'missing' };
		} else {
			const data = frontmatter( readFileSync( file, 'utf8' ) );
			const { status, note } = freshness( data.translatedFrom, [ `src/content/docs/${ slug }.md` ] );
			pages[ slug ] = { status, title: data.title, description: data.description };
			if ( note ) console.warn( `! ${ locale.code }/docs/${ slug }: ${ note }` );
		}
		counts[ pages[ slug ].status ]++;
	}
	for ( const slug of markdown( join( source, 'docs' ) ) ) {
		if ( ! englishDocs.includes( slug ) ) {
			console.warn( `! ${ locale.code }/docs/${ slug }.md has no English page: it isn't shown.` );
			warnings++;
		}
	}
	const strings = JSON.parse( readFileSync( join( source, 'strings.json' ), 'utf8' ) );
	const stringsState = freshness( strings.translatedFrom, [ 'src/i18n/en.ts', 'src/data/landing.ts', 'src/data/sidebar.ts' ] );
	writeFileSync( join( out, 'status.json' ), JSON.stringify( { strings: stringsState.status, pages }, null, '\t' ) + '\n' );

	const late = Object.entries( pages ).filter( ( [ , page ] ) => page.status !== 'translated' );
	warnings += late.length + ( stringsState.status === 'translated' ? 0 : 1 );
	console.log(
		`${ locale.code } (${ locale.repo }): ${ counts.translated } pages translated, ${ counts.outdated } outdated, ${ counts.missing } missing; strings ${ stringsState.status }${ shots.length ? `; ${ shots.length } screenshots` : '' }${ hasVideo ? '; video' : '' }.`
	);
	for ( const [ slug, page ] of late ) console.log( `  ${ page.status }: docs/${ slug }.md` );
}

if ( statusOnly && warnings ) {
	console.log( 'Translations behind English are shown with a notice; update them in their repositories.' );
}
