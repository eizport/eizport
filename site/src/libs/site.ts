/**
 * The site's facts, read once: name, links, and what readme.txt says (the
 * version, requirements, FAQ and changelog), so nothing is written twice.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// The plugin's readme, next to site/ (builds run from site/).
const readmePath = resolve( process.cwd(), '../readme.txt' );
const readme = readFileSync( readmePath, 'utf8' );

/** A header line of readme.txt, like "Stable tag". */
const header = ( name: string ): string =>
	readme.match( new RegExp( `^${ name }:\\s*(.+)$`, 'm' ) )?.[ 1 ].trim() ?? '';

const repo = 'https://github.com/eizport/eizport';

export const site = {
	name: 'Eizport',
	tagline: 'Move WordPress content with its media, settings and files.',
	description:
		'Eizport gives Tools → Export a new screen and .wxrz bundles: content with its media, settings and post content files in one file. Choose items and what they use comes along as suggestions; import with the WordPress Importer, or send the file as a plugin that imports itself. Free and open source.',
	version: header( 'Stable tag' ),
	requires: header( 'Requires at least' ),
	requiresPhp: header( 'Requires PHP' ),
	tested: header( 'Tested up to' ),
	license: header( 'License' ),
	repo,
	releases: `${ repo }/releases`,
	/** Where to get it: its page in the WordPress.org plugin directory. */
	directory: 'https://wordpress.org/plugins/eizport/',
	/** The latest stable version's .zip, from the directory (where a file is meant). */
	download: 'https://downloads.wordpress.org/plugin/eizport.zip',
	issues: `${ repo }/issues`,
	editBase: `${ repo }/edit/trunk/site/src/content/docs/`,
	demo: 'https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/eizport/eizport/trunk/playground/blueprint.json',
};

/** A path inside the site, with the base (empty on eizport.github.io; kept so the site can move under a path). */
export const url = ( path = '' ): string =>
	`${ import.meta.env.BASE_URL.replace( /\/$/, '' ) }/${ path.replace( /^\//, '' ) }`;

/** A title as a URL slug: "File and formats" → "file-and-formats". */
export const slugify = ( text: string ): string =>
	text
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /(^-|-$)/g, '' );

const escape = ( text: string ): string =>
	text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

/** readme.txt's inline markup as HTML: `code`, **bold**, [links](url). */
export const inline = ( text: string ): string =>
	escape( text )
		.replace( /`([^`]+)`/g, '<code>$1</code>' )
		.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
		.replace( /\[([^\]]+)\]\((https?:[^)]+)\)/g, '<a href="$2">$1</a>' );

/** A "== Section ==" of readme.txt, as text. */
const section = ( name: string ): string => {
	const start = readme.indexOf( `== ${ name } ==` );
	if ( start < 0 ) {
		return '';
	}
	const rest = readme.slice( start + name.length + 6 );
	const end = rest.search( /^== /m );
	return ( end < 0 ? rest : rest.slice( 0, end ) ).trim();
};

/** Its "= Title =" entries: title and lines. */
const entries = ( text: string ): { title: string; lines: string[] }[] =>
	text
		.split( /^= (.+) =$/m )
		.slice( 1 )
		.reduce< { title: string; lines: string[] }[] >( ( list, part, index, parts ) => {
			if ( index % 2 === 0 ) {
				list.push( {
					title: part.trim(),
					lines: ( parts[ index + 1 ] ?? '' ).split( '\n' ).map( ( line ) => line.trim() ).filter( Boolean ),
				} );
			}
			return list;
		}, [] );

/** The changelog: version and its changes (HTML). */
export const changelog = (): { version: string; changes: string[] }[] =>
	entries( section( 'Changelog' ) ).map( ( entry ) => ( {
		version: entry.title,
		changes: entry.lines.map( ( line ) => inline( line.replace( /^\*\s*/, '' ) ) ),
	} ) );

/** What the current version brought: its changelog entries, as plain text (llms.txt, index.md). */
export const whatsNew = (): string[] =>
	( changelog().find( ( item ) => item.version === site.version )?.changes ?? [] ).map( ( html ) =>
		html.replace( /<[^>]+>/g, '' ).replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&quot;/g, '"' ).replace( /&#0?39;/g, "'" ).replace( /&amp;/g, '&' )
	);

/** The FAQ: question and answer paragraphs (HTML). */
export const faq = (): { question: string; answer: string[] }[] =>
	entries( section( 'Frequently Asked Questions' ) ).map( ( entry ) => ( {
		question: entry.title,
		answer: entry.lines.map( inline ),
	} ) );

/** The upgrade notice of the current version. */
export const upgradeNotice = (): string =>
	inline(
		entries( section( 'Upgrade Notice' ) ).find( ( entry ) => entry.title === site.version )?.lines.join( ' ' ) ?? ''
	);
