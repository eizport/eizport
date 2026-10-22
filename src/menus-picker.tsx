/**
 * The side panel for the Menus row: the site's classic menus (with the
 * theme locations they sit in) and block navigations, from
 * eizport/get-export-options, each with its checkbox. Checking or clicking
 * one takes it to the export at once; the "Selection" filter shows all, the
 * ones chosen or the suggested ones (the menus in theme locations, the
 * navigations the items chosen or the theme use).
 */
import { Page } from '@wordpress/admin-ui';
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	DataViews,
	filterSortAndPaginate,
	type Field,
	type View,
} from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { useNavigate } from '@wordpress/route';

import { errorMessage, getExportOptions } from './abilities';
import HeaderRow from './header-row';
import mergeSelection from './merge-selection';
import usePhoneView from './use-phone-view';
import { selectionField, selectionMode, startFilters } from './selection-filter';
import {
	MENUS_ROW,
	chosenFor,
	menuKey,
	setRowItems,
	suggestedFor,
	useExportState,
	useSuggestions,
} from './state';
import { useResettableView } from './use-resettable-view';

interface MenuRow {
	/** "c:<ID>" or "n:<ID>". */
	key: string;
	title: string;
	kind: string;
	/** Theme locations, or the number of links. */
	details: string;
}

export default function MenusPicker() {
	const navigate = useNavigate();
	const close = () => navigate( { href: '/export' } );
	const state = useExportState();
	useSuggestions();
	const { createErrorNotice } = useDispatch( noticesStore );
	const [ menus, setMenus ] = useState< MenuRow[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const selection = chosenFor( state, MENUS_ROW ).map( String );
	const suggested = suggestedFor( MENUS_ROW ).map( String );
	const [ view, setView, onReset ] = useResettableView( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 20,
		titleField: 'title',
		fields: [ 'kind', 'details' ],
		layout: { density: 'compact' },
		filters: startFilters( selection, suggested ),
	} as View );
	const mode = selectionMode( view );

	useEffect( () => {
		getExportOptions()
			.then( ( options ) =>
				setMenus( [
					...options.menus.classic.map( ( menu ) => ( {
						key: menuKey( 'classic', menu.id ),
						title: menu.name,
						kind: __( 'Classic menu', 'eizport' ),
						details: menu.locations.length
							? menu.locations.join( ', ' )
							: sprintf(
									/* translators: %d: number of menu items. */
									_n( '%d item', '%d items', menu.items, 'eizport' ),
									menu.items
							  ),
					} ) ),
					...options.menus.navigation.map( ( menu ) => ( {
						key: menuKey( 'navigation', menu.id ),
						title: menu.title,
						kind: __( 'Navigation', 'eizport' ),
						details: sprintf(
							/* translators: %d: number of links in a navigation menu. */
							_n( '%d link', '%d links', menu.links, 'eizport' ),
							menu.links
						),
					} ) ),
				] )
			)
			.catch( ( error ) =>
				createErrorNotice( errorMessage( error ), { type: 'snackbar' } )
			)
			.finally( () => setIsLoading( false ) );
	}, [ createErrorNotice ] );

	const fields: Field< MenuRow >[] = useMemo(
		() => [
			selectionField< MenuRow >(),
			{
				id: 'title',
				label: __( 'Menu', 'eizport' ),
				enableHiding: false,
				enableGlobalSearch: true,
			},
			{
				id: 'kind',
				label: __( 'Type', 'eizport' ),
				enableSorting: false,
				filterBy: false,
			},
			{
				id: 'details',
				label: __( 'Location', 'eizport' ),
				enableSorting: false,
				filterBy: false,
			},
		],
		[]
	);

	// The Selection filter is ours: the rest of the view (search, sort, pages) is DataViews'.
	const shown = useMemo( () => {
		const keys =
			mode === 'selected' ? selection : mode === 'suggested' ? suggested : null;
		return keys ? menus.filter( ( menu ) => keys.includes( menu.key ) ) : menus;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ menus, mode, selection.join(), suggested.join() ] );
	const { data, paginationInfo } = useMemo(
		() =>
			filterSortAndPaginate(
				shown,
				{
					...view,
					filters: ( view.filters || [] ).filter(
						( filter ) => filter.field !== 'selection'
					),
				},
				fields
			),
		[ shown, view, fields ]
	);

	const shownView = usePhoneView( view );
	const choose = ( keys: string[] ) => setRowItems( MENUS_ROW, keys );

	return (
		<Page
			className="eizport-page eizport-picker"
			title={ __( 'Menus', 'eizport' ) }
			subTitle={ __(
				'Check menus to export them: classic menus go with their items and theme locations, navigations with their links. The pages they link to are suggested in their rows.',
				'eizport'
			) }
			actions={
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					size="compact"
					onClick={ close }
				>
					{ __( 'Close', 'eizport' ) }
				</Button>
			}
			hasPadding={ false }
		>
			<DataViews< MenuRow >
				data={ data }
				fields={ fields }
				view={ shownView }
				onChangeView={ setView }
				onReset={ onReset }
				paginationInfo={ paginationInfo }
				isLoading={ isLoading }
				getItemId={ ( item ) => item.key }
				defaultLayouts={ { table: {} } }
				selection={ selection }
				onChangeSelection={ ( next ) =>
					choose(
						mergeSelection(
							selection,
							data.map( ( row ) => row.key ),
							next
						)
					)
				}
				onClickItem={ ( item ) =>
					choose(
						selection.includes( item.key )
							? selection.filter( ( chosen ) => chosen !== item.key )
							: [ ...selection, item.key ]
					)
				}
				actions={ [
					{
						id: 'select',
						label: __( 'Select', 'eizport' ),
						supportsBulk: true,
						callback: ( chosen ) =>
							choose( [
								...new Set( [
									...selection,
									...chosen.map( ( item ) => item.key ),
								] ),
							] ),
					},
				] }
			>
				<HeaderRow />
				<DataViews.Layout />
				<DataViews.Footer />
			</DataViews>
		</Page>
	);
}
