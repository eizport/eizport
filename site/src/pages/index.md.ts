/** The landing page as Markdown, for AI agents (/index.md). */
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import type { APIRoute } from 'astro';
import { abilityExample, agent, audiences, comparison, compatibility, features, finale, formats, hero, hookExample, objections, pains, steps } from '../data/landing';
import { faq, site, url, whatsNew } from '@libs/site';

const text = ( html: string ) => html.replace( /<[^>]+>/g, '' ).replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&amp;/g, '&' );

export const GET: APIRoute = ( { site: origin } ) => {
	const abs = ( path: string ) => new URL( url( path ), origin ).href;
	const md = [
		`# ${ site.name }: ${ hero.title }`,
		`> ${ hero.lead }`,
		hero.note,
		`Version ${ site.version} · WordPress ${ site.requires }+ · PHP ${ site.requiresPhp }+ · ${ site.license }`,
		`${ existsSync( resolve( process.cwd(), 'public/video/demo.mp4' ) ) ? `- Demo video (captions in ${ abs( 'video/demo.en.vtt' ) }): ${ abs( 'video/demo.mp4' ) }\n` : '' }- Try it in WordPress Playground: ${ site.demo }\n- Install: ${ site.directory } (or \`wp plugin install eizport\`)\n- Source: ${ site.repo }\n- Docs: ${ abs( 'docs/' ) } (all as Markdown: ${ abs( 'llms-full.txt' ) })`,
		`## What's new in ${ site.version }`,
		whatsNew().map( ( change ) => `- ${ change }` ).join( '\n' ),
		'## Sound familiar?',
		pains.map( ( pain ) => `- **${ pain.title }.** ${ pain.text }` ).join( '\n' ),
		'## How it works',
		steps.map( ( step, index ) => `${ index + 1 }. **${ step.title }** — ${ step.text }` ).join( '\n' ),
		`## ${ agent.title }`,
		`${ agent.text }\n\n${ agent.points.map( ( p ) => `- ${ p }` ).join( '\n' ) }\n\nExample conversation over MCP:\n\n${ agent.chat.map( ( m ) => `- **${ m.who }:** ${ 'tool' in m && m.tool ? `calls \`${ m.tool }\` with \`${ m.input }\` — ` : '' }${ m.text }` ).join( '\n' ) }\n\nMore: ${ abs( 'docs/developers/ai-agents/' ) }`,
		'## Not a full migration. Not a bare export.',
		'Copying the whole database drags along transients, logs, sessions and old plugins’ tables, and overwrites the target site. WordPress’s export leaves out media files, settings and the images content uses. Eizport takes only what you choose, and everything that depends on it.',
		`| | ${ comparison.columns.join( ' | ' ) } |\n| --- | --- | --- | --- |\n` +
			comparison.rows.map( ( row ) => `| ${ row.label } | ${ row.cells.join( ' | ' ) } |` ).join( '\n' ),
		...features.flatMap( ( feature ) => [
			`## ${ feature.title }`,
			`${ feature.text }\n\n${ feature.points.map( ( point ) => `- ${ point }` ).join( '\n' ) }\n\nMore: ${ abs( feature.href ) }`,
		] ),
		'## Made for',
		audiences.map( ( a ) => `- **${ a.title }** — ${ a.text }` ).join( '\n' ),
		'## Formats',
		'| Format | File | Media files | Settings | Content as .html | Best for |\n| --- | --- | --- | --- | --- | --- |\n' +
			formats
				.map( ( f ) => `| ${ f.name } | \`${ f.file }\` | ${ f.media ? 'Yes' : 'No' } | ${ f.settings ? 'Yes' : 'No' } | ${ f.html ? 'Yes' : 'No' } | ${ f.use } |` )
				.join( '\n' ),
		'## Works with',
		'Support for a plugin is a compatibility built on public hooks. WooCommerce has one; the others weren’t tested, so here is what to expect from how they store their data.',
		compatibility
			.map( ( p ) => `### ${ p.name } — ${ p.status === 'supported' ? 'Supported' : 'Not officially supported yet · not tested' }\n\n${ p.points.map( ( point ) => `- ${ point }` ).join( '\n' ) }` )
			.join( '\n\n' ),
		`What to expect from each: ${ abs( 'docs/developers/compatibilities/' ) }`,
		'## For developers',
		'Every step is an ability of the WordPress Abilities API (REST, PHP, MCP). Export a page as a .zip bundle over REST:',
		'```bash\n' + abilityExample + '\n```',
		'Give your plugin’s page a role:',
		'```php\n' + hookExample + '\n```',
		'## Questions people ask',
		objections.map( ( item ) => `### ${ item.question }\n\n${ item.answer }` ).join( '\n\n' ),
		`## ${ finale.title }`,
		`${ finale.text } Install: ${ site.directory }`,
		'## FAQ',
		faq()
			.map( ( item ) => `### ${ item.question }\n\n${ item.answer.map( text ).join( '\n\n' ) }` )
			.join( '\n\n' ),
	];
	return new Response( md.join( '\n\n' ) + '\n', { headers: { 'Content-Type': 'text/markdown; charset=utf-8' } } );
};
