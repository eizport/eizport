/**
 * The demo video lives in GitHub release assets, not in git (it would add
 * ~9 MB to every clone at each recording): each recording is a pre-release
 * tagged media-X.Y.Z (never "latest") with demo.mp4 and demo.webm, in this
 * repository for English and in each translation's repository for its
 * language. media.json names the recording the site shows; scripts/video.mjs
 * uploads a new one. Captions and the poster stay in git.
 *
 *   npm run media                                  download English's into public/video/
 *   npm run media -- --upload [--tag media-X.Y.Z]   upload public/video/'s as a recording
 *   npm run media -- --upload --locale br=../../eizport-ptb
 *       a language's, from its repository's assets/video/, to that repository
 *       (scripts/video.mjs does this after recording; the tag defaults to the
 *       plugin's version, media-X.Y.Z)
 *
 * scripts/translations.mjs downloads each language's into its checkout. A
 * video that can't be downloaded is left out: the site shows the poster.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const site = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

export const FILES = [ 'demo.mp4', 'demo.webm' ];

/** The recording the site shows: media.json's tag (media-X.Y.Z). */
export const mediaTag = () => JSON.parse( readFileSync( join( site, 'media.json' ), 'utf8' ) ).tag;

/**
 * Downloads a repository's demo video (owner/name) into a folder, skipping
 * files already there with the same size.
 *
 * @return {Promise<boolean>} Whether every file is there.
 */
export const downloadVideo = async ( repo, dir, tag = mediaTag() ) => {
	mkdirSync( dir, { recursive: true } );
	for ( const file of FILES ) {
		const url = `https://github.com/${ repo }/releases/download/${ tag }/${ file }`;
		const target = join( dir, file );
		try {
			const head = await fetch( url, { method: 'HEAD' } );
			if ( ! head.ok ) {
				throw new Error( `HTTP ${ head.status }` );
			}
			if ( existsSync( target ) && statSync( target ).size === Number( head.headers.get( 'content-length' ) ) ) {
				continue;
			}
			const response = await fetch( url );
			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}
			writeFileSync( target, Buffer.from( await response.arrayBuffer() ) );
		} catch ( error ) {
			console.warn( `! ${ repo }@${ tag }: couldn't download ${ file } (${ error.message }); the site shows the poster.` );
			return false;
		}
	}
	return true;
};

/** The recording's tag for this version of the plugin: media-X.Y.Z (eizport.php's Version, without -dev or -rc.N). */
export const versionTag = () => {
	const version = /^ \* Version:\s*(\S+)$/m.exec( readFileSync( join( site, '../eizport.php' ), 'utf8' ) )[ 1 ];
	return `media-${ version.replace( /-.*$/, '' ) }`;
};

/**
 * Uploads a demo video (dir's demo.mp4 and demo.webm) to a repository's
 * media-X.Y.Z release, created as a pre-release that is never "latest"
 * (releases/latest is the plugin's download), replacing files already there.
 *
 * @return {boolean} Whether it was uploaded; otherwise the command is printed.
 */
export const uploadVideo = ( repo, dir, tag ) => {
	const files = FILES.map( ( file ) => join( dir, file ) );
	const gh = ( ...args ) => execFileSync( 'gh', [ ...args, '--repo', repo ], { stdio: [ 'ignore', 'pipe', 'pipe' ], encoding: 'utf8' } );
	try {
		try {
			gh( 'release', 'view', tag );
		} catch {
			gh( 'release', 'create', tag, '--prerelease', '--latest=false', '--target', 'trunk', '--title', `Demo video (${ tag })`, '--notes', 'The demo video the site shows (media.json in eizport/eizport). Not a plugin release.' );
		}
		gh( 'release', 'upload', tag, ...files, '--clobber' );
		console.log( `Video: ${ files.join( ', ' ) } → ${ repo }@${ tag }.` );
		return true;
	} catch ( error ) {
		console.warn( `! Couldn't upload the video to ${ repo }@${ tag } (${ String( error.stderr ?? error.message ).trim() }). By hand:\n  gh release create ${ tag } --repo ${ repo } --prerelease --latest=false --target trunk && gh release upload ${ tag } ${ files.join( ' ' ) } --repo ${ repo } --clobber` );
		return false;
	}
};

/** Points the site at a recording (media.json). */
export const useTag = ( tag ) => {
	const file = join( site, 'media.json' );
	writeFileSync( file, JSON.stringify( { ...JSON.parse( readFileSync( file, 'utf8' ) ), tag }, null, '\t' ) + '\n' );
};

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	const args = process.argv.slice( 2 );
	const value = ( name ) => ( args.includes( name ) ? args[ args.indexOf( name ) + 1 ] : '' );
	if ( args.includes( '--upload' ) ) {
		const tag = value( '--tag' ) || versionTag();
		const [ code, path ] = value( '--locale' ).split( '=' );
		if ( code ) {
			const locale = JSON.parse( readFileSync( join( site, 'translations.json' ), 'utf8' ) ).locales.find( ( item ) => item.code === code );
			process.exit( locale && path && uploadVideo( locale.repo, resolve( path, 'assets/video' ), tag ) ? 0 : 1 );
		}
		if ( ! uploadVideo( 'eizport/eizport', join( site, 'public/video' ), tag ) ) {
			process.exit( 1 );
		}
		useTag( tag );
		console.log( `media.json: ${ tag }.` );
	} else if ( await downloadVideo( 'eizport/eizport', join( site, 'public/video' ) ) ) {
		console.log( `Video: ${ mediaTag() } in public/video/.` );
	}
}
