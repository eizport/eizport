/**
 * Export: the content types to export, each whole or by items (picked in
 * the side panel, ?type=…), the file settings in a panel above the list,
 * and Reset, Save as default and Export in the page header. The copies are
 * in the plugin's settings.
 */
import { Page } from '@wordpress/admin-ui';
import { Button, DropdownMenu } from '@wordpress/components';
import { useResizeObserver, useViewportMatch } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
import {
	DataViews,
	filterSortAndPaginate,
	type Action,
	type Field,
	type View,
} from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { useNavigate, useSearch } from '@wordpress/route';
import { Badge } from '@wordpress/ui';

import { Icon, moreVertical } from '@wordpress/icons';

import {
	errorMessage,
	exportContent,
	getSuggestions,
	getExportOptions,
	clearExportDefaults,
	getExportDefaults,
	getOptions,
	saveExportDefaults,
} from '../../src/abilities';
import FileBar from '../../src/file-bar';
import { displayName } from '../../src/display-name';
import HeaderRow from '../../src/header-row';
import { mark } from '../../src/mark';
import usePhoneView from '../../src/use-phone-view';
import {
	DEFAULT_STATE,
	LOOK_ROWS,
	MENUS_ROW,
	OPTIONS_ROW,
	REVIEWS_ROW,
	bringSuggested,
	chosenFor,
	explicitFor,
	exportAll,
	exportInput,
	groupName,
	hasChoices,
	hasMenus,
	includesSuggested,
	leaveOut,
	menusOf,
	resolvedTypes,
	sameState,
	setExportState,
	setOptionChoice,
	setSuggestions,
	stateFromDefaults,
	suggestedFor,
	useExportState,
	useSuggestions,
} from '../../src/state';
import type {
	ContentType,
	ExportOptions,
	ExportState,
	TypeChoice,
} from '../../src/types';
import { useResettableView } from '../../src/use-resettable-view';

type Row = ContentType & {
	/** Whether the row goes in the export (its checkbox). */
	checked: boolean;
	/** What goes, in words. */
	summary: string;
	/** Whether the row exports all of it already. */
	exportsAll: boolean;
};

/**
 * "%d item(s)".
 *
 * @param count Number.
 * @return Words.
 */
function items( count: number ): string {
	return sprintf(
		/* translators: %d: number of items. */
		_n( '%d item', '%d items', count, 'eizport' ),
		count
	);
}

/**
 * "%d suggested".
 *
 * @param count Number.
 * @return Words.
 */
function suggestedWords( count: number ): string {
	return sprintf(
		/* translators: %d: number of items or settings suggested by the items chosen (the homepage's settings, the products a block shows…). */
		_n( '%d suggested', '%d suggested', count, 'eizport' ),
		count
	);
}

/**
 * What a row exports, in words, and whether it is checked: the type whole
 * ("All", "Filtered") or "N suggested" (the suggestions it brings), groups
 * of options and "N items" (chosen one by one), joined by " + ". An
 * unchecked row with suggestions says "N suggested" too.
 *
 * @param state   State.
 * @param row     Row.
 * @param options Export options (for the groups' names).
 * @return Checked and summary.
 */
function describe(
	state: ExportState,
	row: string,
	options?: ExportOptions
): { checked: boolean; summary: string; exportsAll: boolean } {
	const parts: string[] = [];
	const suggestions = suggestedFor( row );
	const choice: TypeChoice | undefined = state.types[ row ];
	// A look row whole: "All", like a whole type.
	const lookWhole =
		!! LOOK_ROWS[ row ] &&
		state.settings.appearance.includes( LOOK_ROWS[ row ] );
	if ( row === OPTIONS_ROW && state.settings.option_all ) {
		parts.push( __( 'All options', 'eizport' ) );
	} else if ( row === REVIEWS_ROW && state.settings.review_all ) {
		parts.push( __( 'All', 'eizport' ) );
	} else if ( row === MENUS_ROW && state.settings.menu_all ) {
		parts.push( __( 'All', 'eizport' ) );
	} else if ( lookWhole ) {
		parts.push( __( 'All', 'eizport' ) );
	} else if ( choice && ! choice.ids ) {
		parts.push(
			Object.keys( choice.filters || {} ).length
				? __( 'Filtered', 'eizport' )
				: __( 'All', 'eizport' )
		);
	}
	if ( includesSuggested( state, row ) && suggestions.length ) {
		parts.push( suggestedWords( suggestions.length ) );
	}
	if ( row === OPTIONS_ROW && ! state.settings.option_all ) {
		( options?.option_groups || [] )
			.filter( ( group ) =>
				state.settings.option_groups.includes( group.group )
			)
			.forEach( ( group ) => parts.push( groupName( group.label ) ) );
	}
	const brought = includesSuggested( state, row ) ? suggestions : [];
	const own = explicitFor( state, row ).filter(
		( id ) => ! brought.includes( id )
	);
	if ( own.length ) {
		parts.push( items( own.length ) );
	}
	let exportsAll = !! choice && ! choice.ids && ! choice.filters;
	if ( row === OPTIONS_ROW ) {
		exportsAll = state.settings.option_all;
	} else if ( row === REVIEWS_ROW ) {
		exportsAll = state.settings.review_all;
	} else if ( row === MENUS_ROW ) {
		exportsAll = state.settings.menu_all;
	} else if ( LOOK_ROWS[ row ] ) {
		exportsAll = lookWhole;
	}
	if ( parts.length ) {
		return { checked: true, summary: parts.join( ' + ' ), exportsAll };
	}
	return {
		exportsAll,
		checked: false,
		summary: suggestions.length
			? suggestedWords( suggestions.length )
			: __( 'Not included', 'eizport' ),
	};
}

const DEFAULT_VIEW: View = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	titleField: 'label',
	fields: [ 'count', 'choice' ],
	layout: {},
};

// Narrower than this, the header's actions don't fit next to the title.
const STAGE_ACTIONS_MIN_WIDTH = 700;

function Stage() {
	const navigate = useNavigate();
	// The type open in the side panel, whose row's button closes it.
	const { type: openType } = useSearch( { strict: false } ) as {
		type?: string;
	};
	const state = useExportState();
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const [ options, setOptions ] = useState< ExportOptions >();
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ view, setView, onReset ] = useResettableView( DEFAULT_VIEW );
	// On a phone what is exported goes under the title.
	const shownView = usePhoneView( view, 'choice' );

	// The choices saved as default (null: none), for Reset; the screen opens with them.
	const [ savedDefaults, setSavedDefaults ] = useState< ExportState | null >(
		null
	);
	useEffect( () => {
		getExportDefaults()
			.then( ( defaults ) => {
				const saved = Object.keys( defaults ).length
					? stateFromDefaults( defaults )
					: null;
				setSavedDefaults( saved );
				if ( saved && ! hasChoices() ) {
					setExportState( saved );
				}
			} )
			.catch( () => undefined );
	}, [] );
	// The header's actions go in a ⋮ menu when the screen is narrow — the
	// window on a phone, or this panel next to the side panel.
	const [ stageWidth, setStageWidth ] = useState< number >();
	const observeStage = useResizeObserver( ( [ entry ] ) =>
		setStageWidth( entry.contentRect.width )
	);
	const isSmall =
		useViewportMatch( 'medium', '<' ) ||
		( stageWidth !== undefined && stageWidth < STAGE_ACTIONS_MIN_WIDTH );
	const resetTo = savedDefaults || DEFAULT_STATE;
	const isReset = sameState( state, resetTo );

	const onClearDefaults = () =>
		clearExportDefaults()
			.then( () => {
				setSavedDefaults( null );
				setExportState( DEFAULT_STATE );
				createSuccessNotice(
					__( 'Saved defaults cleared.', 'eizport' ),
					{ type: 'snackbar' }
				);
			} )
			.catch( ( error ) =>
				createErrorNotice( errorMessage( error ), { type: 'snackbar' } )
			);

	const [ isSaving, setIsSaving ] = useState( false );
	const onSaveDefault = async () => {
		setIsSaving( true );
		try {
			await saveExportDefaults( state );
			setSavedDefaults( stateFromDefaults( state ) );
			createSuccessNotice(
				__( 'Saved as default.', 'eizport' ),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			createErrorNotice( errorMessage( error ), { type: 'snackbar' } );
		} finally {
			setIsSaving( false );
		}
	};

	useEffect( () => {
		getOptions( { per_page: 1 } )
			.then( ( page ) => setOptionsTotal( page.total ) )
			.catch( () => undefined );
		getExportOptions()
			.then( setOptions )
			.catch( ( error ) =>
				createErrorNotice( errorMessage( error ), { type: 'snackbar' } )
			)
			.finally( () => setIsLoading( false ) );
	}, [ createErrorNotice ] );

	const [ optionsTotal, setOptionsTotal ] = useState( 0 );
	const suggestions = useSuggestions();
	const types = useMemo( () => resolvedTypes( state ), [ state, suggestions ] );
	const typesKey = JSON.stringify( types );
	// The classic menus chosen: the pages their items point to are suggested.
	const menusKey = JSON.stringify(
		menusOf( chosenFor( state, MENUS_ROW ) ).classic
	);

	// What the items chosen suggest (settings, products a block shows, reviews).
	useEffect( () => {
		const timer = setTimeout( () => {
			const chosen: Record< string, TypeChoice > = JSON.parse( typesKey );
			// Types chosen whole go apart: an empty object doesn't survive the URL of a read-only ability.
			getSuggestions( {
				types: Object.fromEntries(
					Object.entries( chosen ).filter(
						( [ , choice ] ) => choice.ids || choice.filters
					)
				),
				whole: Object.keys( chosen ).filter(
					( type ) => ! chosen[ type ].ids && ! chosen[ type ].filters
				),
				menus: { classic: JSON.parse( menusKey ) },
			} )
				.then( setSuggestions )
				.catch( () => undefined );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ typesKey, menusKey ] );

	const rows: Row[] = useMemo( () => {
		const list: Row[] = [];
		( options?.types || [] ).forEach( ( type ) => {
			list.push( { ...type, ...describe( state, type.type, options ) } );
			// Product reviews, right after Products.
			if ( type.type === 'product' && options?.review_count ) {
				list.push( {
					type: REVIEWS_ROW,
					label: __( 'Product reviews', 'eizport' ),
					count: options.review_count,
					hierarchical: false,
					statuses: [],
					taxonomies: [],
					...describe( state, REVIEWS_ROW, options ),
				} );
			}
		} );
		const menuCount =
			( options?.menus?.classic.length || 0 ) +
			( options?.menus?.navigation.length || 0 );
		if ( menuCount ) {
			// Navigation menus: classic ones (with their locations) and block navigations.
			list.push( {
				type: MENUS_ROW,
				label: __( 'Menus', 'eizport' ),
				count: menuCount,
				hierarchical: false,
				statuses: [],
				taxonomies: [],
				...describe( state, MENUS_ROW, options ),
			} );
		}
		// The look, a row each: only what the site has.
		const lookLabels: Record< string, string > = {
			theme_mods: __( 'Customizer theme mods', 'eizport' ),
			custom_css: __( 'Customizer custom CSS', 'eizport' ),
			global_styles: __( 'Editor global styles', 'eizport' ),
			global_css: __( 'Editor global CSS', 'eizport' ),
			widgets: __( 'Widgets', 'eizport' ),
			// ACF's post types, taxonomies and field groups (with ACF active).
			'advanced-custom-fields': __( 'Advanced Custom Fields (ACF)', 'eizport' ),
			// Elementor's site settings (the Kit) and templates (with Elementor active).
			elementor: __( 'Elementor', 'eizport' ),
		};
		Object.entries( LOOK_ROWS ).forEach( ( [ row, key ] ) => {
			const count = options?.appearance?.[ key ]?.length || 0;
			if ( count ) {
				list.push( {
					type: row,
					label: lookLabels[ key ],
					count,
					hierarchical: false,
					statuses: [],
					taxonomies: [],
					...describe( state, row, options ),
				} );
			}
		} );
		if ( options ) {
			// Options (settings), one by one, are exported with the content, in the same list.
			list.push( {
				type: OPTIONS_ROW,
				label: __( 'Options', 'eizport' ),
				count: optionsTotal,
				hierarchical: false,
				statuses: [],
				taxonomies: [],
				...describe( state, OPTIONS_ROW, options ),
			} );
		}
		return list;
		// suggestions: describe() reads them.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ options, state, optionsTotal, suggestions ] );

	const fields: Field< Row >[] = useMemo(
		() => [
			{
				id: 'label',
				label: __( 'Content', 'eizport' ),
				enableHiding: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.label,
			},
			{
				id: 'count',
				label: __( 'Items', 'eizport' ),
				type: 'integer',
				getValue: ( { item } ) => item.count,
			},
			{
				id: 'choice',
				label: __( 'Export', 'eizport' ),
				enableSorting: false,
				getValue: ( { item } ) => item.summary,
				render: ( { item } ) => {
					let intent: 'informational' | 'draft' | 'stable' = 'draft';
					if ( item.checked ) {
						intent = 'informational';
					} else if ( suggestedFor( item.type ).length ) {
						// Suggested, not included yet.
						intent = 'stable';
					}
					return <Badge intent={ intent }>{ item.summary }</Badge>;
				},
			},
		],
		[]
	);

	const actions: Action< Row >[] = useMemo(
		() => [
			{
				id: 'choose',
				label: ( [ item ] ) =>
					item?.type === openType
						? __( 'Close items', 'eizport' )
						: __( 'Choose items', 'eizport' ),
				isPrimary: true,
				callback: ( [ item ] ) =>
					navigate( {
						href:
							item.type === openType
								? '/export'
								: `/export?type=${ item.type }`,
					} ),
			},
			{
				id: 'all',
				label: __( 'Export all of it', 'eizport' ),
				supportsBulk: true,
				// Where it doesn't export all yet. DataViews only enables a row's checkbox when a bulk
				// action is eligible: a row left out has this one, a row included has "Leave out".
				isEligible: ( item ) => ! item.exportsAll,
				callback: ( rowsChosen ) =>
					rowsChosen.forEach( ( item ) =>
						item.type === OPTIONS_ROW
							? setOptionChoice( { all: true } )
							: exportAll( item.type )
					),
			},
			{
				id: 'leave-out',
				label: __( 'Leave out', 'eizport' ),
				supportsBulk: true,
				isEligible: ( item ) => item.checked,
				callback: ( rowsChosen ) =>
					rowsChosen.forEach( ( item ) => leaveOut( item.type ) ),
			},
		],
		[ navigate, openType ]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
	);
	const chosen = rows.filter( ( row ) => row.checked ).map( ( row ) => row.type );

	const onExport = async () => {
		setIsExporting( true );
		try {
			const copy = await exportContent( exportInput( state ) );
			createSuccessNotice(
				sprintf(
					/* translators: %s: file name. */
					__( '%s is ready.', 'eizport' ),
					displayName( copy.name )
				),
				{
					type: 'snackbar',
					actions: [
						{
							label: __( 'Download', 'eizport' ),
							url: copy.download_url,
						},
					],
				}
			);
		} catch ( error ) {
			createErrorNotice( errorMessage( error ), { type: 'snackbar' } );
		} finally {
			setIsExporting( false );
		}
	};

	return (
		<Page
			className="eizport-page"
			visual={ <Icon icon={ mark } /> }
			title={ __( 'Export', 'eizport' ) }
			subTitle={
				Object.keys( types ).length
					? sprintf(
							/* translators: %d: number of content types. */
							_n(
								'%d content type checked. Choose items per type; set up the file above.',
								'%d content types checked. Choose items per type; set up the file above.',
								Object.keys( types ).length,
								'eizport'
							),
							Object.keys( types ).length
					  )
					: hasMenus( state )
					? __(
							'No content type checked: only the menus are exported. Check types to export content too.',
							'eizport'
					  )
					: __(
							'No content type checked: all content is exported. Check types to export only those.',
							'eizport'
					  )
			}
			actions={
				<>
					{ /* Clear defaults and Reset to defaults: one active at a time (Reset while the choices differ from the defaults). On a phone, they and Save as default go in a ⋮ menu after Export. */ }
					{ ! isSmall && (
						<>
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								size="compact"
								disabled={ ! isReset || ! savedDefaults }
								accessibleWhenDisabled
								onClick={ onClearDefaults }
							>
								{ __( 'Clear defaults', 'eizport' ) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								size="compact"
								disabled={ isReset }
								accessibleWhenDisabled
								onClick={ () => setExportState( resetTo ) }
							>
								{ __( 'Reset to defaults', 'eizport' ) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="secondary"
								size="compact"
								isBusy={ isSaving }
								disabled={ isSaving }
								accessibleWhenDisabled
								onClick={ onSaveDefault }
							>
								{ __( 'Save as default', 'eizport' ) }
							</Button>
						</>
					) }
					<Button
						__next40pxDefaultSize
						variant="primary"
						size="compact"
						isBusy={ isExporting }
						disabled={ isExporting }
						accessibleWhenDisabled
						onClick={ onExport }
					>
						{ __( 'Export', 'eizport' ) }
					</Button>
					{ isSmall && (
						<DropdownMenu
							icon={ moreVertical }
							label={ __( 'More actions', 'eizport' ) }
							toggleProps={ { size: 'compact' } }
							controls={ [
								{
									title: __(
										'Clear defaults',
										'eizport'
									),
									isDisabled: ! isReset || ! savedDefaults,
									onClick: onClearDefaults,
								},
								{
									title: __(
										'Reset to defaults',
										'eizport'
									),
									isDisabled: isReset,
									onClick: () => setExportState( resetTo ),
								},
								{
									title: __(
										'Save as default',
										'eizport'
									),
									isDisabled: isSaving,
									onClick: onSaveDefault,
								},
							] }
						/>
					) }
				</>
			}
			hasPadding={ false }
		>
			<div ref={ observeStage } className="eizport-stage-width" />
			<FileBar options={ options } />
			<DataViews< Row >
				data={ data }
				fields={ fields }
				view={ shownView }
				onChangeView={ setView }
				onReset={ onReset }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isLoading }
				getItemId={ ( item ) => item.type }
				defaultLayouts={ { table: {}, list: {} } }
				selection={ chosen }
				onChangeSelection={ ( ids ) => {
					ids.filter( ( id ) => ! chosen.includes( id ) ).forEach(
						( row ) => {
							if ( suggestedFor( row ).length ) {
								// A row with suggestions brings them (and stays "N suggested").
								bringSuggested( row );
							} else if ( row === OPTIONS_ROW ) {
								// Options are picked one by one: open the list.
								navigate( { href: `/export?type=${ OPTIONS_ROW }` } );
							} else {
								exportAll( row );
							}
						}
					);
					chosen
						.filter( ( id ) => ! ids.includes( id ) )
						.forEach( leaveOut );
				} }
				onClickItem={ ( item ) =>
					navigate( { href: `/export?type=${ item.type }` } )
				}
			>
				<HeaderRow filters={ false } />
				<DataViews.Layout />
				<DataViews.Footer />
			</DataViews>
		</Page>
	);
}

export const stage = Stage;
