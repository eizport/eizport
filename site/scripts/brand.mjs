/**
 * Builds the brand files from the mark (src/assets/brand/mark.svg) and the
 * Inter font, with the wordmark as outlines so every file renders the same
 * anywhere: logos for the brand manual (brand/, see BRAND.md), the
 * favicon and touch icon, the social image, and the WordPress.org icon and
 * banners (../.wordpress-org/). Run with `npm run brand`; the outputs are
 * committed. `npm run brand -- --locale br=<its repository>` writes only the
 * banners in that language (banner-772x250-pt_BR.png…), which WordPress.org
 * shows on its localized directory.
 */
import { readFileSync } from 'node:fs';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import opentype from 'opentype.js';
import sharp from 'sharp';

const root = new URL( '../', import.meta.url );
const path = ( relative ) => fileURLToPath( new URL( relative, root ) );

// --locale <code>=<its repository>: only the WordPress.org banners in that language (banner-…-pt_BR.png), their line from its strings.json (brand.banner).
const args = process.argv.slice( 2 );
const localeArg = args.includes( '--locale' ) ? args[ args.indexOf( '--locale' ) + 1 ] : '';
const [ localeCode, localeRepo ] = localeArg ? localeArg.split( '=' ) : [];
const locale = localeCode
	? JSON.parse( readFileSync( path( 'translations.json' ), 'utf8' ) ).locales.find( ( item ) => item.code === localeCode )
	: null;
if ( localeCode && ( ! locale || ! localeRepo ) ) {
	console.error( '--locale needs a language of translations.json and its repository: --locale br=../../eizport-ptb' );
	process.exit( 1 );
}
const english = JSON.parse( readFileSync( path( 'src/data/brand.json' ), 'utf8' ) );
const banner = locale ? JSON.parse( readFileSync( resolve( localeRepo, 'strings.json' ), 'utf8' ) ).brand?.banner : english.banner;
if ( ! banner ) {
	console.error( `${ localeRepo }/strings.json has no brand.banner.` );
	process.exit( 1 );
}

const COLORS = {
	primary: '#3858e9',
	primaryDark: '#1d35b4',
	accent: '#f2994a',
	ink: '#1e1e1e',
	muted: '#50575e',
	paper: '#fff',
	night: '#0f1115',
	onNight: '#f0f0f1',
};

const font = async ( weight ) => {
	const parsed = opentype.parse(
		( await readFile( path( `node_modules/@fontsource/inter/files/inter-latin-${ weight }-normal.woff` ) ) ).buffer
	);
	// opentype.js can't run some of Inter's GSUB lookups; plain words need none (kerning is GPOS).
	delete parsed.tables.gsub;
	return parsed;
};
const semibold = await font( 600 );
const regular = await font( 400 );

/** The mark's drawing (no background), at 64 units, for embedding. */
const markStrokes = ( top = COLORS.paper, bottom = COLORS.accent ) =>
	`<g fill="none" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"><path stroke="${ top }" d="M20 14 14 20l6 6M14 20h32L18 44"/><path stroke="${ bottom }" d="M18 44h32m-6-6 6 6-6 6"/></g>`;

/** The mark at a position and size, on its rounded square. */
const mark = ( x, y, size, background = COLORS.primary ) => {
	const body = `<rect width="64" height="64" rx="14" fill="${ background }"/>${ markStrokes() }`;
	// At the origin and native size the transform is a no-op: leave it out.
	return x || y || 64 !== size ? `<g transform="translate(${ x } ${ y }) scale(${ size / 64 })">${ body }</g>` : body;
};

/** Text as an outline path, with its advance width. */
const outline = ( text, f, size, x, y, fill ) => {
	const glyphs = f.getPath( text, x, y, size );
	glyphs.fill = fill;
	return { svg: glyphs.toSVG( 2 ), width: f.getAdvanceWidth( text, size ) };
};

/** "Eizport", its "z" in the primary color (the mark's Z). */
const wordmark = ( x, y, size, ink ) => {
	const ei = outline( 'Ei', semibold, size, x, y, ink );
	const z = outline( 'z', semibold, size, x + ei.width, y, COLORS.primary );
	const port = outline( 'port', semibold, size, x + ei.width + z.width, y, ink );
	return { svg: ei.svg + z.svg + port.svg, width: ei.width + z.width + port.width };
};

const svg = ( width, height, body, title = 'Eizport' ) =>
	`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ width } ${ height }" width="${ width }" height="${ height }" role="img" aria-label="${ title }">${ body }</svg>\n`;

const logos = {};

// Horizontal: mark 64, wordmark 30px, 16px gap.
{
	const text = wordmark( 80, 43, 30, COLORS.ink );
	const width = Math.ceil( 80 + text.width );
	logos[ 'logo-horizontal.svg' ] = svg( width, 64, mark( 0, 0, 64 ) + text.svg );
	const dark = wordmark( 80, 43, 30, COLORS.onNight );
	logos[ 'logo-horizontal-dark.svg' ] = svg( width, 64, mark( 0, 0, 64 ) + dark.svg );
}

// Stacked: mark 96 centered over the wordmark.
{
	const probe = wordmark( 0, 0, 30, COLORS.ink );
	const width = Math.ceil( probe.width );
	const text = wordmark( 0, 148, 30, COLORS.ink );
	logos[ 'logo-stacked.svg' ] = svg( width, 160, mark( ( width - 96 ) / 2, 0, 96 ) + text.svg );
}

logos[ 'mark.svg' ] = svg( 64, 64, mark( 0, 0, 64 ) );
logos[ 'mark-mono.svg' ] = svg(
	64,
	64,
	markStrokes( 'currentColor', 'currentColor' )
);
logos[ 'mark-white.svg' ] = svg( 64, 64, markStrokes( COLORS.paper, COLORS.paper ) );

if ( ! locale ) {
	await mkdir( path( 'brand' ), { recursive: true } );
	for ( const [ name, content ] of Object.entries( logos ) ) {
		await writeFile( path( `brand/${ name }` ), content );
	}

	// Favicon: the mark, as is.
	await writeFile( path( 'public/favicon.svg' ), logos[ 'mark.svg' ] );
}

const png = ( content, width, height, file ) =>
	sharp( Buffer.from( content ), { density: 288 } )
		.resize( width, height )
		.png( { compressionLevel: 9 } )
		.toFile( path( file ) );

if ( ! locale ) {
	await png( svg( 180, 180, `<rect width="180" height="180" fill="${ COLORS.primary }"/>${ mark( 18, 18, 144 ) }` ), 180, 180, 'public/apple-touch-icon.png' );
}

/** A wide card: gradient, mark, wordmark and a line of text. */
const card = ( width, height, tagline ) => {
	const scale = height / 630;
	const markSize = 150 * scale;
	const left = 96 * scale;
	const title = wordmark( left, height / 2 + 26 * scale, 64 * scale, COLORS.paper );
	// A longer line (another language) gets a smaller size, to stay inside the card's margins.
	const size = Math.min( 30 * scale, ( 30 * scale * ( width - 2 * left ) ) / regular.getAdvanceWidth( tagline, 30 * scale ) );
	const line = outline( tagline, regular, size, left, height / 2 + 96 * scale, '#c9d2ff' );
	return svg(
		width,
		height,
		`<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${ COLORS.primaryDark }"/><stop offset="1" stop-color="${ COLORS.night }"/></linearGradient></defs>` +
			`<rect width="${ width }" height="${ height }" fill="url(#g)"/>` +
			`<g opacity=".08" transform="translate(${ width - 560 * scale } ${ -40 * scale }) scale(${ 11 * scale })">${ markStrokes( COLORS.paper, COLORS.paper ) }</g>` +
			mark( left, height / 2 - markSize - 40 * scale, markSize ) +
			title.svg.replaceAll( `fill="${ COLORS.primary }"`, `fill="${ COLORS.accent }"` ) +
			line.svg
	);
};

if ( locale ) {
	for ( const [ width, height ] of [ [ 772, 250 ], [ 1544, 500 ] ] ) {
		await png( card( width, height, banner ), width, height, `../.wordpress-org/banner-${ width }x${ height }-${ locale.wp }.png` );
	}
	console.log( `WordPress.org banners in ${ locale.wp } written.` );
	process.exit( 0 );
}

await png( card( 1200, 630, 'Export content with its media, settings and files. Import it back.' ), 1200, 630, 'public/og-image.png' );

// WordPress.org: icon and banners.
await mkdir( path( '../.wordpress-org' ), { recursive: true } );
await writeFile( path( '../.wordpress-org/icon.svg' ), logos[ 'mark.svg' ] );
for ( const size of [ 128, 256 ] ) {
	await png( logos[ 'mark.svg' ], size, size, `../.wordpress-org/icon-${ size }x${ size }.png` );
}
for ( const [ width, height ] of [ [ 772, 250 ], [ 1544, 500 ] ] ) {
	await png( card( width, height, banner ), width, height, `../.wordpress-org/banner-${ width }x${ height }.png` );
}

console.log( 'Brand files written.' );
