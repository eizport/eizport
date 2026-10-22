/**
 * What the user is putting together on the Export screen (types, items,
 * file settings), in memory while the screen is open: the list and the side
 * panels are one bundle. Nothing is kept by itself — reopening or reloading
 * the screen starts again from the saved defaults ("Save as default"), or
 * from nothing chosen.
 */
import { useSyncExternalStore } from '@wordpress/element';

import type { ExportState, Suggestions, TypeChoice } from './types';

export const DEFAULT_STATE: ExportState = {
	types: {},
	settings: {
		format: 'wxr',
		filename: '',
		media: true,
		option_names: [],
		option_all: false,
		option_groups: [],
		include_suggested: [],
		as_plugin: false,
		update_existing: false,
		review_ids: [],
		review_all: false,
		menu_ids: [],
		menu_all: false,
		appearance: [],
		appearance_items: {},
		css_mode: 'end',
		landing: 'home',
		landing_page: 0,
		landing_path: '',
		language: 'site',
		themes: [],
		plugins: [],
		mu_plugins: [],
	},
};

const listeners = new Set< () => void >();
let cache: ExportState | null = null;

function read(): ExportState {
	return cache ?? DEFAULT_STATE;
}

/**
 * Whether anything was chosen since the screen opened (else the saved
 * defaults are loaded).
 *
 * @return Whether there are choices.
 */
export function hasChoices(): boolean {
	return cache !== null;
}

/**
 * The state for saved defaults (eizport/get-export-defaults).
 *
 * @param defaults Saved defaults.
 * @return State.
 */
export function stateFromDefaults( defaults: Partial< ExportState > ): ExportState {
	return {
		...DEFAULT_STATE,
		types: { ...( defaults.types || {} ) },
		settings: { ...DEFAULT_STATE.settings, ...( defaults.settings || {} ) },
	};
}

/**
 * Replaces the state.
 *
 * @param next New state, or a function of the current one.
 */
export function setExportState(
	next: ExportState | ( ( current: ExportState ) => ExportState )
) {
	cache = typeof next === 'function' ? next( read() ) : next;
	listeners.forEach( ( listener ) => listener() );
}

/**
 * The state, re-rendering on changes.
 *
 * @return State.
 */
export function useExportState(): ExportState {
	return useSyncExternalStore(
		( listener ) => {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},
		read,
		read
	);
}

/** The Options row of the Export list (not a post type). */
export const OPTIONS_ROW = '__options';

/** The Product reviews row (WooCommerce's product reviews). */
export const REVIEWS_ROW = '__reviews';

/** The Menus row: classic menus ("c:<ID>") and block navigations ("n:<ID>"). */
export const MENUS_ROW = '__menus';

/**
 * The look's rows (no side panel: each is one thing, in or out), by the key
 * eizport/export takes.
 */
export const LOOK_ROWS: Record< string, string > = {
	__theme_mods: 'theme_mods',
	__custom_css: 'custom_css',
	__global_styles: 'global_styles',
	__global_css: 'global_css',
	__widgets: 'widgets',
	'__advanced-custom-fields': 'advanced-custom-fields',
	__elementor: 'elementor',
};

/** Rows that aren't a post type. */
const OWN_ROWS = [ OPTIONS_ROW, REVIEWS_ROW, MENUS_ROW, ...Object.keys( LOOK_ROWS ) ];

/**
 * A menu's ID in the Menus row.
 *
 * @param kind "classic" or "navigation".
 * @param id   Term or post ID.
 * @return Row ID, like "c:18".
 */
export function menuKey( kind: 'classic' | 'navigation', id: number ): string {
	return `${ kind === 'classic' ? 'c' : 'n' }:${ id }`;
}

/**
 * The menus of some Menus row IDs, as eizport/export takes them.
 *
 * @param keys Row IDs ("c:18", "n:4").
 * @return Classic menu and navigation IDs.
 */
export function menusOf( keys: Id[] ): {
	classic: number[];
	navigation: number[];
} {
	const of = ( prefix: string ) =>
		keys
			.map( String )
			.filter( ( key ) => key.startsWith( prefix ) )
			.map( ( key ) => Number( key.slice( 2 ) ) );
	return { classic: of( 'c:' ), navigation: of( 'n:' ) };
}

/** What the items chosen suggest (eizport/get-suggestions). */
let suggestions: Suggestions = { items: {}, reviews: [], options: [] };
const suggestionListeners = new Set< () => void >();

/**
 * Sets the suggestions for the current choices.
 *
 * @param next Suggestions.
 */
export function setSuggestions( next: Suggestions ) {
	suggestions = next;
	suggestionListeners.forEach( ( listener ) => listener() );
}

/**
 * The suggestions, re-rendering when they change.
 *
 * @return Suggestions.
 */
export function useSuggestions(): Suggestions {
	return useSyncExternalStore(
		( listener ) => {
			suggestionListeners.add( listener );
			return () => suggestionListeners.delete( listener );
		},
		() => suggestions,
		() => suggestions
	);
}

type Id = number | string;

/**
 * What a row's items suggest: products for Products, settings for Options,
 * reviews for Product reviews, the theme's and the items' menus for Menus.
 *
 * @param row Row: a post type, OPTIONS_ROW, REVIEWS_ROW or MENUS_ROW.
 * @return IDs (option names for Options).
 */
export function suggestedFor( row: string ): Id[] {
	if ( row === OPTIONS_ROW ) {
		return suggestions.options;
	}
	if ( row === REVIEWS_ROW ) {
		return suggestions.reviews;
	}
	if ( LOOK_ROWS[ row ] ) {
		return suggestions.appearance?.[ LOOK_ROWS[ row ] ] || [];
	}
	if ( row === MENUS_ROW ) {
		return [
			...( suggestions.menus?.classic || [] ).map( ( id ) =>
				menuKey( 'classic', id )
			),
			...( suggestions.menus?.navigation || [] ).map( ( id ) =>
				menuKey( 'navigation', id )
			),
		];
	}
	return suggestions.items[ row ] || [];
}

/**
 * Whether a row brings its suggestions (it was checked while it had some).
 *
 * @param state State.
 * @param row   Row.
 * @return Whether it does.
 */
export function includesSuggested( state: ExportState, row: string ): boolean {
	return state.settings.include_suggested.includes( row );
}

/**
 * A row's items chosen one by one.
 *
 * @param state State.
 * @param row   Row.
 * @return IDs (option names for Options).
 */
export function explicitFor( state: ExportState, row: string ): Id[] {
	if ( row === OPTIONS_ROW ) {
		return state.settings.option_names;
	}
	if ( row === REVIEWS_ROW ) {
		return state.settings.review_ids;
	}
	if ( row === MENUS_ROW ) {
		return state.settings.menu_ids;
	}
	if ( LOOK_ROWS[ row ] ) {
		return state.settings.appearance_items?.[ LOOK_ROWS[ row ] ] || [];
	}
	return state.types[ row ]?.ids || [];
}

/**
 * A row's items chosen: one by one, and the suggested ones it brings.
 *
 * @param state State.
 * @param row   Row.
 * @return IDs (option names for Options).
 */
export function chosenFor( state: ExportState, row: string ): Id[] {
	return [
		...new Set( [
			...explicitFor( state, row ),
			...( includesSuggested( state, row ) ? suggestedFor( row ) : [] ),
		] ),
	];
}

/**
 * Writes a row's items chosen one by one and whether it brings its
 * suggestions.
 *
 * @param current  State.
 * @param row      Row.
 * @param explicit Items chosen one by one.
 * @param include  Whether it brings its suggestions.
 * @return New state.
 */
function withRow(
	current: ExportState,
	row: string,
	explicit: Id[],
	include: boolean
): ExportState {
	const settings = {
		...current.settings,
		include_suggested: [
			...current.settings.include_suggested.filter(
				( item ) => item !== row
			),
			...( include ? [ row ] : [] ),
		],
	};
	if ( row === OPTIONS_ROW ) {
		return {
			...current,
			settings: { ...settings, option_names: explicit.map( String ) },
		};
	}
	if ( row === REVIEWS_ROW ) {
		return {
			...current,
			settings: {
				...settings,
				review_ids: explicit.map( Number ),
				review_all: false,
			},
		};
	}
	if ( row === MENUS_ROW ) {
		return {
			...current,
			settings: {
				...settings,
				menu_ids: explicit.map( String ),
				menu_all: false,
			},
		};
	}
	if ( LOOK_ROWS[ row ] ) {
		const key = LOOK_ROWS[ row ];
		const chosen = { ...( settings.appearance_items || {} ) };
		if ( explicit.length ) {
			chosen[ key ] = explicit.map( String );
		} else {
			delete chosen[ key ];
		}
		return {
			...current,
			settings: {
				...settings,
				// Items chosen one by one: the row isn't whole anymore.
				appearance: settings.appearance.filter( ( whole ) => whole !== key ),
				appearance_items: chosen,
			},
		};
	}
	const types = { ...current.types };
	if ( explicit.length ) {
		types[ row ] = { ids: explicit.map( Number ) };
	} else {
		delete types[ row ];
	}
	return { ...current, types, settings };
}

/**
 * Sets a row's items chosen, at once (from its side panel): unchecking one
 * of the suggested ones turns the suggestions into plain choices, the rest
 * staying checked; none leaves the row out.
 *
 * @param row Row.
 * @param ids Items checked (the suggested ones among them).
 */
export function setRowItems( row: string, ids: Id[] ) {
	setExportState( ( current ) => {
		const brought = includesSuggested( current, row )
			? suggestedFor( row )
			: [];
		const keep =
			brought.length > 0 && brought.every( ( id ) => ids.includes( id ) );
		return withRow(
			current,
			row,
			keep ? ids.filter( ( id ) => ! brought.includes( id ) ) : ids,
			keep
		);
	} );
}

/**
 * Checks a row with suggestions: it brings them (and stays "N suggested").
 *
 * @param row Row.
 */
export function bringSuggested( row: string ) {
	setExportState( ( current ) =>
		withRow( current, row, explicitFor( current, row ), true )
	);
}

/**
 * Leaves a row out: no items, no suggestions brought.
 *
 * @param row Row.
 */
export function leaveOut( row: string ) {
	if ( row === OPTIONS_ROW ) {
		setOptionChoice( {} );
		return;
	}
	setExportState( ( current ) => withRow( current, row, [], false ) );
}

/**
 * Exports all of a row: the whole type, or every product review.
 *
 * @param row Row (not Options).
 */
export function exportAll( row: string ) {
	setExportState( ( current ) => {
		const next = withRow( current, row, [], false );
		if ( row === REVIEWS_ROW ) {
			return {
				...next,
				settings: { ...next.settings, review_all: true },
			};
		}
		if ( row === MENUS_ROW ) {
			return {
				...next,
				settings: { ...next.settings, menu_all: true },
			};
		}
		if ( LOOK_ROWS[ row ] ) {
			return {
				...next,
				settings: {
					...next.settings,
					appearance: [ ...next.settings.appearance, LOOK_ROWS[ row ] ],
				},
			};
		}
		return { ...next, types: { ...next.types, [ row ]: {} } };
	} );
}

/**
 * The content types to export, with the suggested items the rows bring.
 *
 * @param state State.
 * @return Post type => choice, as eizport/export takes them.
 */
export function resolvedTypes( state: ExportState ): Record< string, TypeChoice > {
	const types: Record< string, TypeChoice > = { ...state.types };
	state.settings.include_suggested
		.filter( ( row ) => ! OWN_ROWS.includes( row ) )
		.forEach( ( row ) => {
			const choice = types[ row ];
			// A type exported whole (or filtered) already has its suggestions.
			if ( choice && ! choice.ids ) {
				return;
			}
			const ids = chosenFor( state, row ).map( Number );
			if ( ids.length ) {
				types[ row ] = { ids };
			}
		} );
	return types;
}

/** Items an update doesn't reach: the importer matches them its own way. */
export const NOT_UPDATED = [ 'attachment', 'nav_menu_item', 'wp_navigation' ];

/**
 * Whether the export holds items "Update existing" reaches: all content with
 * nothing checked, else a checked content type other than attachments and
 * menu items (not only menus, options or the look).
 *
 * @param state State.
 * @return Whether.
 */
export function updateReachesItems( state: ExportState ): boolean {
	if ( ! Object.keys( state.types ).length && ! hasMenus( state ) ) {
		return true;
	}
	return Object.keys( resolvedTypes( state ) ).some(
		( type ) => ! NOT_UPDATED.includes( type ) && ! OWN_ROWS.includes( type )
	);
}

/**
 * The ability input for the state.
 *
 * @param state State.
 * @return eizport/export input.
 */
export function exportInput( state: ExportState ): Record< string, unknown > {
	const { settings } = state;
	const reviews = chosenFor( state, REVIEWS_ROW ).map( Number );
	const input: Record< string, unknown > = {
		bundle: needsBundle( state ),
		media: settings.media,
		// The server adds nothing on its own: the suggested options go when chosen.
		options: settings.option_all
			? { all: true, linked: false }
			: {
					groups: settings.option_groups,
					names: chosenFor( state, OPTIONS_ROW ).map( String ),
					linked: false,
			  },
		// Product reviews only when their row is checked.
		reviews: settings.review_all ? { all: true } : { ids: reviews },
		// Menus too: classic ones (with their items and locations) and navigations.
		menus: settings.menu_all
			? { all: true }
			: menusOf( chosenFor( state, MENUS_ROW ) ),
		// The look, row by row (theme mods, custom CSS, global styles and CSS, widgets): whole, or its items.
		appearance: Object.fromEntries(
			Object.entries( LOOK_ROWS )
				.map( ( [ row, key ] ): [ string, string | string[] ] => [
					key,
					settings.appearance.includes( key )
						? 'all'
						: chosenFor( state, row ).map( String ),
				] )
				.filter( ( [ , value ] ) => value.length > 0 )
		),
		css_mode: settings.css_mode || 'end',
	};
	const types = resolvedTypes( state );
	if ( Object.keys( types ).length ) {
		input.types = types;
	}
	if ( settings.filename.trim() !== '' ) {
		input.filename = settings.filename.trim();
	}
	if ( settings.format !== 'playground' && settings.as_plugin ) {
		input.as_plugin = true;
	}
	if (
		settings.format !== 'playground' &&
		settings.update_existing &&
		updateReachesItems( state )
	) {
		input.update_existing = true;
	}
	if ( settings.format === 'playground' ) {
		const landing: Record< string, unknown > = {
			admin: 'admin',
			page: settings.landing_page ? { page: settings.landing_page } : 'home',
			path: settings.landing_path.startsWith( '/' ) ? settings.landing_path : 'home',
		};
		input.blueprint = {
			themes: settings.themes,
			plugins: settings.plugins,
			mu_plugins: settings.mu_plugins,
			landing_page: landing[ settings.landing ] ?? 'home',
			language: settings.language,
		};
	}
	return input;
}

/**
 * Whether the export is a .wxrz bundle (or a Playground .zip) rather than
 * core's plain WXR .xml: media files and settings only travel in one.
 *
 * @param state State.
 * @return Whether it needs the bundle.
 */
export function needsBundle( state: ExportState ): boolean {
	return (
		state.settings.format === 'playground' ||
		state.settings.as_plugin ||
		state.settings.media ||
		hasOptions( state ) ||
		hasLook( state )
	);
}

/**
 * Whether the export includes some of the look (it travels in the bundle's
 * options file).
 *
 * @param state State.
 * @return Whether any look row is chosen.
 */
export function hasLook( state: ExportState ): boolean {
	return Object.entries( LOOK_ROWS ).some(
		( [ row, key ] ) =>
			state.settings.appearance.includes( key ) ||
			chosenFor( state, row ).length > 0
	);
}

/**
 * Whether the export includes options.
 *
 * @param state State.
 * @return Whether any option is chosen.
 */
export function hasOptions( state: ExportState ): boolean {
	return (
		state.settings.option_all ||
		state.settings.option_groups.length > 0 ||
		chosenFor( state, OPTIONS_ROW ).length > 0
	);
}

/**
 * Whether the export includes menus.
 *
 * @param state State.
 * @return Whether any menu is chosen.
 */
export function hasMenus( state: ExportState ): boolean {
	return (
		state.settings.menu_all || chosenFor( state, MENUS_ROW ).length > 0
	);
}

/**
 * Whether the export includes product reviews.
 *
 * @param state State.
 * @return Whether any review is chosen.
 */
export function hasReviews( state: ExportState ): boolean {
	return (
		state.settings.review_all ||
		chosenFor( state, REVIEWS_ROW ).length > 0
	);
}

/**
 * A value with its object keys sorted, to compare states.
 *
 * @param value Value.
 * @return The same value, keys sorted.
 */
function sorted( value: unknown ): unknown {
	if ( Array.isArray( value ) ) {
		return value.map( sorted );
	}
	if ( value && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.keys( value as object )
				.sort()
				.map( ( key ) => [
					key,
					sorted( ( value as Record< string, unknown > )[ key ] ),
				] )
		);
	}
	return value;
}

/**
 * Whether two states make the same export (for Reset).
 *
 * @param a A state.
 * @param b Another.
 * @return Whether they are the same.
 */
export function sameState( a: ExportState, b: ExportState ): boolean {
	return (
		JSON.stringify( sorted( stateFromDefaults( a ) ) ) ===
		JSON.stringify( sorted( stateFromDefaults( b ) ) )
	);
}

/**
 * Sets the options to export; nothing given leaves them out (the
 * suggested ones too, until the row is checked again).
 *
 * @param choice        What to export.
 * @param choice.names  Option names.
 * @param choice.groups Groups of options (eizport/get-export-options).
 * @param choice.all    Every option that can be exported.
 */
export function setOptionChoice( {
	names = [],
	groups = [],
	all = false,
}: {
	names?: string[];
	groups?: string[];
	all?: boolean;
} ) {
	setExportState( ( current ) => {
		const none = ! all && ! names.length && ! groups.length;
		return {
			...current,
			settings: {
				...current.settings,
				option_names: all ? [] : names,
				option_groups: all ? [] : groups,
				option_all: all,
				include_suggested: none
					? current.settings.include_suggested.filter(
							( row ) => row !== OPTIONS_ROW
					  )
					: current.settings.include_suggested,
			},
		};
	} );
}

/**
 * A group's short name: its label up to the colon.
 *
 * @param label Group label, like "Basics (suggested): title, tagline…".
 * @return Short name, like "Basics".
 */
export function groupName( label: string ): string {
	return label.split( ':' )[ 0 ].replace( / \(.*\)$/, '' );
}
