/**
 * /llms.txt (llmstxt.org): the site in a few lines and every docs page with
 * its description, for AI agents — as Bootstrap's docs do.
 */
import type { APIRoute } from 'astro';
import { sidebar } from '../data/sidebar';
import { docsEntries, docsPages } from '@libs/docs';
import { site, url, whatsNew } from '@libs/site';

export const GET: APIRoute = async ( { site: origin } ) => {
	const entries = await docsEntries();
	const lines = [
		`# ${ site.name }`,
		'',
		`> ${ site.description }`,
		'',
		`WordPress plugin, version ${ site.version }, for WordPress ${ site.requires }+ and PHP ${ site.requiresPhp }+, GPL. Source: ${ site.repo }. Install: ${ site.directory } (or \`wp plugin install eizport\`). Every page below is Markdown; the whole documentation is in one file at ${ new URL( url( 'llms-full.txt' ), origin ) }.`,
		'',
		'The plugin itself is agent-ready: every export, import and setting is an ability of the WordPress Abilities API (REST, PHP, MCP) — see Developers → Abilities.',
		'',
		`## What's new in ${ site.version }`,
		'',
		...whatsNew().map( ( change ) => `- ${ change }` ),
		'',
	];
	lines.push(
		'## Site',
		'',
		`- [Overview](${ new URL( url( 'index.md' ), origin ) }): what the plugin does, features (suggestions, export as a plugin too), formats, the plugins it works with (WooCommerce; ACF and Elementor not tested yet), developers, FAQ.`,
		''
	);
	for ( const group of sidebar ) {
		lines.push( `## ${ group.title }`, '' );
		for ( const page of docsPages.filter( ( p ) => p.group === group.title ) ) {
			const description = entries.find( ( entry ) => entry.id === page.slug )?.data.description ?? '';
			const href = page.generated ? new URL( page.href, origin ).href : new URL( `${ page.href.replace( /\/$/, '' ) }.md`, origin ).href;
			lines.push( `- [${ page.title }](${ href })${ description ? `: ${ description }` : '' }` );
		}
		lines.push( '' );
	}
	return new Response( lines.join( '\n' ), { headers: { 'Content-Type': 'text/plain; charset=utf-8' } } );
};
