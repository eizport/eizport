/**
 * The site's languages (translations.json): English at the root, each other
 * language under /<code>/ once scripts/translations.mjs has put its
 * translation in src/content/i18n/<code>/ (git-ignored). Texts come from
 * src/i18n/en.ts with the language's strings.json laid over them.
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import config from '../../translations.json';
import { en, type Strings } from '../i18n/en';
import { site, url } from './site';

export interface Locale {
	code: string;
	lang: string;
	/** Open Graph's locale, like en_US. */
	og: string;
	/** Its name, in it: the translation's strings.json `language` (English's here). */
	label: string;
	/** WordPress's locale, like pt_BR: the Playground demo opens in it. */
	wp?: string;
	repo?: string;
	ref?: string;
}

/** A docs page's state in a language, from status.json. */
export interface PageStatus {
	status: 'translated' | 'outdated' | 'missing';
	title?: string;
	description?: string;
}

export const defaultLocale: Locale = config.default;

const folder = ( code: string ): string => resolve( process.cwd(), 'src/content/i18n', code );

/** English, then every language whose translation is present (named by it). */
export const locales = (): Locale[] => [
	defaultLocale,
	...config.locales
		.filter( ( locale ) => existsSync( resolve( folder( locale.code ), 'strings.json' ) ) )
		.map( ( locale ) => ( {
			...locale,
			label: String( JSON.parse( readFileSync( resolve( folder( locale.code ), 'strings.json' ), 'utf8' ) ).language ?? locale.code ),
		} ) ),
];

/** The other languages, the ones under /<code>/. */
export const translatedLocales = (): Locale[] => locales().slice( 1 );

/** getStaticPaths of the /[locale]/ pages: one per translated language. */
export const localeParams = () => translatedLocales().map( ( locale ) => ( { params: { locale: locale.code } } ) );

export const localeBy = ( code: string ): Locale => locales().find( ( locale ) => locale.code === code ) ?? defaultLocale;

/** The language of a page, from its path. */
export const localeOf = ( pathname: string ): Locale => {
	const first = pathname.replace( import.meta.env.BASE_URL, '/' ).split( '/' )[ 1 ];
	return translatedLocales().find( ( locale ) => locale.code === first ) ?? defaultLocale;
};

const isObject = ( value: unknown ): value is Record< string, unknown > =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

/** `over` laid on `base`: same shape, item by item in lists; what `over` lacks stays. */
const merge = < T >( base: T, over: unknown ): T => {
	if ( Array.isArray( base ) ) {
		return ( Array.isArray( over ) ? base.map( ( item, index ) => merge( item, over[ index ] ) ) : base ) as T;
	}
	if ( isObject( base ) ) {
		if ( ! isObject( over ) ) {
			return base;
		}
		const out: Record< string, unknown > = { ...base };
		for ( const key of Object.keys( over ) ) {
			out[ key ] = key in base ? merge( ( base as Record< string, unknown > )[ key ], over[ key ] ) : over[ key ];
		}
		return out as T;
	}
	return ( typeof over === typeof base ? over : base ) as T;
};

const cache = new Map< string, Strings >();

/** The texts of a language. */
export const t = ( locale: Locale ): Strings => {
	if ( locale.code === defaultLocale.code ) {
		return en;
	}
	if ( ! cache.has( locale.code ) ) {
		const file = resolve( folder( locale.code ), 'strings.json' );
		cache.set( locale.code, merge( en, existsSync( file ) ? JSON.parse( readFileSync( file, 'utf8' ) ) : {} ) );
	}
	return cache.get( locale.code )!;
};

/** A text with its `{name}` values filled in. */
export const fill = ( text: string, values: Record< string, string | number > ): string =>
	text.replace( /\{(\w+)\}/g, ( match, name: string ) => ( name in values ? String( values[ name ] ) : match ) );

/** A page's URL in a language (files such as llms.txt or the video stay where they are: use url()). */
export const href = ( locale: Locale, path = '' ): string =>
	locale.code === defaultLocale.code ? url( path ) : url( `${ locale.code }/${ path.replace( /^\//, '' ) }` );

/** The Playground demo in a language: Playground sets the site language and downloads the plugin's language pack. */
export const demo = ( locale: Locale ): string => ( locale.wp ? `${ site.demo }&language=${ locale.wp }` : site.demo );

/** The path of a page without its language prefix: "/br/docs/" → "docs/". */
export const pathWithoutLocale = ( pathname: string ): string => {
	const path = pathname.replace( import.meta.env.BASE_URL, '/' ).replace( /^\//, '' );
	const locale = localeOf( pathname );
	return locale.code === defaultLocale.code ? path : path.slice( locale.code.length + 1 );
};

/** The same page in every language (the 404 page is only in English: GitHub Pages serves one). */
export const alternates = ( pathname: string ): { locale: Locale; href: string }[] => {
	const path = pathWithoutLocale( pathname );
	return ( path.startsWith( '404' ) ? [ defaultLocale ] : locales() ).map( ( locale ) => ( { locale, href: href( locale, path ) } ) );
};

const statuses = new Map< string, Record< string, PageStatus > >();

/** A docs page's translation state; English pages are always translated. */
export const pageStatus = ( locale: Locale, slug: string ): PageStatus => {
	if ( locale.code === defaultLocale.code ) {
		return { status: 'translated' };
	}
	if ( ! statuses.has( locale.code ) ) {
		const file = resolve( folder( locale.code ), 'status.json' );
		statuses.set( locale.code, existsSync( file ) ? JSON.parse( readFileSync( file, 'utf8' ) ).pages ?? {} : {} );
	}
	return statuses.get( locale.code )![ slug ] ?? { status: 'missing' };
};

/** Where a docs page's translation is edited, or created when missing. */
export const translationEdit = ( locale: Locale, slug: string, missing: boolean ): string => {
	const base = `https://github.com/${ locale.repo }`;
	if ( missing ) {
		const [ group, page ] = slug.split( '/' );
		return `${ base }/new/${ locale.ref }/docs/${ group }?filename=${ page }.md`;
	}
	return `${ base }/edit/${ locale.ref }/docs/${ slug }.md`;
};
