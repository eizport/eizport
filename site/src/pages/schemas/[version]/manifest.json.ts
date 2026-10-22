/**
 * The bundle manifest's JSON Schemas, served from the plugin's schemas/: one
 * per version (/schemas/1.1/manifest.json, the URL manifests carry in
 * "$schema", frozen once released) and /schemas/trunk/manifest.json, the
 * newest — as schemas.wp.org does with theme.json.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import type { APIRoute, GetStaticPaths } from 'astro';

const folder = resolve( process.cwd(), '../schemas' );

const versions = (): string[] =>
	readdirSync( folder )
		.filter( ( version ) => /^\d+\.\d+$/.test( version ) && existsSync( resolve( folder, version, 'manifest.json' ) ) )
		.sort( ( a, b ) => a.localeCompare( b, 'en', { numeric: true } ) );

export const getStaticPaths = ( () => {
	const all = versions();
	return [
		...all.map( ( version ) => ( { params: { version }, props: { file: version } } ) ),
		{ params: { version: 'trunk' }, props: { file: all[ all.length - 1 ] } },
	];
} ) satisfies GetStaticPaths;

export const GET: APIRoute = ( { props } ) =>
	new Response( readFileSync( resolve( folder, String( props.file ), 'manifest.json' ), 'utf8' ), {
		headers: { 'Content-Type': 'application/json; charset=utf-8' },
	} );
