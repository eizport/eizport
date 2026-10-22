/** The bundle manifest's JSON Schema (/eizport-schema-2.json), served from the plugin's schemas/ — the URL manifests carry in "$schema". */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { APIRoute } from 'astro';

export const GET: APIRoute = () =>
	new Response( readFileSync( resolve( process.cwd(), '../schemas/eizport-schema-2.json' ), 'utf8' ), {
		headers: { 'Content-Type': 'application/json; charset=utf-8' },
	} );
