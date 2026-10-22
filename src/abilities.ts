/**
 * The plugin's abilities, run through core's abilities client: the same
 * schemas, permissions and errors agents get over REST or MCP.
 */
import { executeAbility } from '@wordpress/abilities';
import * as coreAbilities from '@wordpress/core-abilities';

import type {
	Copy,
	ExportOptions,
	ExportState,
	ItemsPage,
	OptionsPage,
	ReviewsPage,
	Settings,
	Suggestions,
} from './types';

/**
 * Waits until the server's abilities are registered in the client. Core 7.1
 * ships `initialize()` (memoized); newer versions — the Gutenberg plugin,
 * when active, replaces core's module — export a `ready` promise instead. A
 * named import of either breaks on the other, so the module is read whole.
 *
 * @return Promise resolved once registered.
 */
function serverAbilities(): Promise< unknown > {
	const module = coreAbilities as unknown as {
		initialize?: () => Promise< void >;
		ready?: Promise< void >;
	};
	if ( typeof module.initialize === 'function' ) {
		return module.initialize();
	}
	return module.ready ?? Promise.resolve();
}

/**
 * Runs an ability once the server's abilities are registered in the client.
 *
 * @param name  Ability name.
 * @param input Input, when the ability takes one.
 * @return The ability's output.
 */
export async function runAbility< T >(
	name: string,
	input?: Record< string, unknown >
): Promise< T > {
	await serverAbilities();
	return ( await executeAbility( name, input as never ) ) as T;
}

export const getExportOptions = () =>
	runAbility< ExportOptions >( 'eizport/get-export-options' );

export const getItems = ( input: Record< string, unknown > ) =>
	runAbility< ItemsPage >( 'eizport/get-items', input );

export const exportContent = ( input: Record< string, unknown > ) =>
	runAbility< Copy >( 'eizport/export', input );

export const getCopies = () => runAbility< Copy[] >( 'eizport/get-copies' );

export const deleteCopies = ( names: string[] ) =>
	runAbility< { deleted: number } >( 'eizport/delete-copies', { names } );

export const deleteAllCopies = () =>
	runAbility< { deleted: number } >( 'eizport/delete-copies', { all: true } );

export const importCopy = ( copy: string ) =>
	runAbility< { posts: number; options: string[]; log: string } >(
		'eizport/import',
		{ copy }
	);

export const getOptions = ( input: Record< string, unknown > ) =>
	runAbility< OptionsPage >( 'eizport/get-options', input );

export const getSuggestions = ( input: Record< string, unknown > ) =>
	runAbility< Suggestions >( 'eizport/get-suggestions', input );

export const getReviews = ( input: Record< string, unknown > ) =>
	runAbility< ReviewsPage >( 'eizport/get-reviews', input );

/** The export choices saved as default; {} when none. */
export const getExportDefaults = () =>
	runAbility< Partial< ExportState > >( 'eizport/get-export-defaults' );

export const saveExportDefaults = ( defaults: ExportState ) =>
	runAbility< ExportState >( 'eizport/save-export-defaults', {
		types: defaults.types,
		settings: defaults.settings,
	} );

export const clearExportDefaults = () =>
	runAbility< object >( 'eizport/clear-export-defaults' );

export const getSettings = () =>
	runAbility< Settings >( 'eizport/get-settings' );

/** The settings that can be saved. */
export type SettingValues = Pick<
	Settings,
	| 'copy_lifetime'
	| 'replace_export'
	| 'classic_ui'
	| 'style_import'
	| 'import_direct'
	| 'content_files'
	| 'attached_only'
	| 'bundle_zip'
	| 'plugin_content'
	| 'plugin_import_again'
	| 'defaults_scope'
	| 'linked_options'
	| 'css_markers'
>;

export const updateSettings = (
	changes: Partial< SettingValues >
) => runAbility< Settings >( 'eizport/update-settings', changes );

/**
 * A readable message from an ability error (WP_Error over REST).
 *
 * @param error What was thrown.
 * @return Message.
 */
export function errorMessage( error: unknown ): string {
	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: unknown } ).message );
	}
	return String( error );
}
