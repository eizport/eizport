/** The newest bundle manifest JSON Schema (/eizport-schema.json), served from the plugin's schemas/ — for writing a manifest by hand. */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { APIRoute } from 'astro';

export const GET: APIRoute = () =>
	new Response( readFileSync( resolve( process.cwd(), '../schemas/eizport-schema.json' ), 'utf8' ), {
		headers: { 'Content-Type': 'application/json; charset=utf-8' },
	} );
