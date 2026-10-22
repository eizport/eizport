/**
 * The side panel of the Export screen: the items of one type
 * (eizport/get-items), opened by ?type=<post type>. Checking or clicking an
 * item takes it to the export at once (the main list shows it); the
 * "Selection" filter shows all items or only the ones chosen, and the
 * other filters help find them. Close goes back to the list.
 */
import { Page } from '@wordpress/admin-ui';
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	DataViews,
	type Field,
	type Filter,
	type View,
} from '@wordpress/dataviews';
import { dateI18n, getSettings } from '@wordpress/date';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { useNavigate, useSearch } from '@wordpress/route';
import { Badge } from '@wordpress/ui';

import { errorMessage, getExportOptions, getItems } from '../../src/abilities';
import HeaderRow from '../../src/header-row';
import mergeSelection from '../../src/merge-selection';
import usePhoneView from '../../src/use-phone-view';
import LookPicker from '../../src/look-picker';
import MenusPicker from '../../src/menus-picker';
import OptionsPicker from '../../src/options-picker';
import {
	OPTIONS_ROW,
	LOOK_ROWS,
	MENUS_ROW,
	REVIEWS_ROW,
	chosenFor,
	setRowItems,
	suggestedFor,
	useExportState,
	useSuggestions,
} from '../../src/state';
import ReviewsPicker from '../../src/reviews-picker';
import {
	selectionField,
	selectionMode,
	startFilters,
} from '../../src/selection-filter';
import type {
	ContentType,
	Filters,
	Item,
} from '../../src/types';
import { useResettableView } from '../../src/use-resettable-view';

/**
 * The ability filters of the view's filters.
 *
 * @param filters View filters.
 * @return Filters for eizport/get-items and eizport/export.
 */
function toFilters( filters: Filter[] = [] ): Filters {
	const out: Filters = {};
	const terms: number[] = [];
	filters.forEach( ( filter ) => {
		if ( filter.value === undefined || filter.value === '' ) {
			return;
		}
		if ( filter.field === 'status' ) {
			out.status = String( filter.value );
		} else if ( filter.field.startsWith( 'tax:' ) ) {
			terms.push(
				...( Array.isArray( filter.value )
					? filter.value
					: [ filter.value ]
				).map( Number )
			);
		}
	} );
	if ( terms.length ) {
		out.terms = terms;
	}
	return out;
}

/**
 * The view's filters for saved ability filters.
 *
 * @param filters Saved filters.
 * @param type    Content type.
 * @return View filters.
 */
function toViewFilters( filters: Filters, type?: ContentType ): Filter[] {
	const out: Filter[] = [];
	if ( filters.status ) {
		out.push( { field: 'status', operator: 'is', value: filters.status } );
	}
	type?.taxonomies.forEach( ( taxonomy ) => {
		const ids = taxonomy.terms
			.map( ( term ) => term.id )
			.filter( ( id ) => filters.terms?.includes( id ) );
		if ( ids.length ) {
			out.push( {
				field: `tax:${ taxonomy.taxonomy }`,
				operator: 'isAny',
				value: ids,
			} );
		}
	} );
	return out;
}

function Picker( { typeName }: { typeName: string } ) {
	const navigate = useNavigate();
	const close = () => navigate( { href: '/export' } );
	const state = useExportState();
	const saved = state.types[ typeName ];
	const { createErrorNotice } = useDispatch( noticesStore );
	const [ type, setType ] = useState< ContentType >();
	const [ items, setItems ] = useState< Item[] >( [] );
	const [ total, setTotal ] = useState( { items: 0, pages: 0 } );
	const [ isLoading, setIsLoading ] = useState( true );
	useSuggestions();
	const selection = chosenFor( state, typeName ).map( String );
	const suggested = suggestedFor( typeName ).map( String );
	// The list asks for the items chosen (or suggested) when it loads, not on each check: an item unchecked stays in sight.
	const chosenNow = useRef( { selected: selection, suggested } );
	chosenNow.current = { selected: selection, suggested };
	const [ view, setView, onReset ] = useResettableView( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 20,
		sort: { field: 'date', direction: 'desc' },
		titleField: 'title',
		descriptionField: 'file',
		// Status and date stay in the view settings, hidden to start with.
		fields: [],
		layout: { density: 'compact' },
		// Items chosen already: the panel opens on them (or on the suggested ones).
		filters: startFilters( selection, suggested ),
	} );
	const mode = selectionMode( view );

	useEffect( () => {
		getExportOptions()
			.then( ( options ) => {
				const found = options.types.find(
					( candidate ) => candidate.type === typeName
				);
				setType( found );
				if ( found && saved?.filters ) {
					setView( ( current ) => ( {
						...current,
						filters: [
							...( current.filters || [] ),
							...toViewFilters( saved.filters || {}, found ),
						],
					} ) );
				}
			} )
			.catch( ( error ) =>
				createErrorNotice( errorMessage( error ), { type: 'snackbar' } )
			);
		// The saved choice only seeds the view once.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ typeName ] );

	useEffect( () => {
		setIsLoading( true );
		const sortField = view.sort?.field;
		getItems( {
			type: typeName,
			search: view.search || '',
			page: view.page || 1,
			per_page: view.perPage || 20,
			orderby:
				sortField === 'title' || sortField === 'menu_order'
					? sortField
					: 'date',
			order: view.sort?.direction || 'desc',
			filters: toFilters( view.filters ),
			...( mode !== 'all'
				? { include: chosenNow.current[ mode ].map( Number ) }
				: {} ),
		} )
			.then( ( page ) => {
				setItems( page.items );
				setTotal( { items: page.total, pages: page.total_pages } );
			} )
			.catch( ( error ) =>
				createErrorNotice( errorMessage( error ), { type: 'snackbar' } )
			)
			.finally( () => setIsLoading( false ) );
	}, [ typeName, view, mode, createErrorNotice ] );

	const fields: Field< Item >[] = useMemo( () => {
		const statusLabels: Record< string, string > = {
			publish: __( 'Published', 'eizport' ),
			draft: __( 'Draft', 'eizport' ),
			pending: __( 'Pending', 'eizport' ),
			private: __( 'Private', 'eizport' ),
			future: __( 'Scheduled', 'eizport' ),
			inherit: __( 'Attached', 'eizport' ),
			trash: __( 'Trash', 'eizport' ),
		};
		const list: Field< Item >[] = [
			selectionField< Item >(),
			{
				id: 'title',
				label: __( 'Title', 'eizport' ),
				enableHiding: false,
				render: ( { item } ) => (
					<span>
						{ item.title }
						{ ( item.roles || [] ).map( ( role ) => (
							<span key={ role.option }>
								{ ' ' }
								<Badge intent="informational">{ role.label }</Badge>
							</span>
						) ) }
					</span>
				),
			},
			{
				id: 'file',
				label: __( 'File', 'eizport' ),
				enableSorting: false,
				enableHiding: false,
			},
			{
				id: 'status',
				label: __( 'Status', 'eizport' ),
				enableSorting: false,
				elements: ( type?.statuses || [] ).map( ( status ) => ( {
					value: status,
					label: statusLabels[ status ] || status,
				} ) ),
				filterBy: { operators: [ 'is' ] },
				render: ( { item } ) => (
					<Badge intent="draft">
						{ statusLabels[ item.status ] || item.status }
					</Badge>
				),
			},
			{
				id: 'date',
				label: __( 'Date', 'eizport' ),
				type: 'datetime',
				filterBy: false,
				render: ( { item } ) =>
					dateI18n(
						getSettings().formats.datetimeAbbreviated,
						item.date,
						undefined
					),
			},
		];
		type?.taxonomies.forEach( ( taxonomy ) =>
			list.push( {
				id: `tax:${ taxonomy.taxonomy }`,
				label: taxonomy.label,
				enableSorting: false,
				enableHiding: false,
				elements: taxonomy.terms.map( ( term ) => ( {
					value: term.id,
					label: term.name,
				} ) ),
				filterBy: { operators: [ 'isAny' ] },
				render: () => null,
			} )
		);
		return list;
	}, [ type ] );

	const shownView = usePhoneView( view );
	const label = type?.label || typeName;

	// Every change goes to the export at once.
	const choose = ( ids: string[] ) =>
		setRowItems( typeName, ids.map( Number ) );

	return (
		<Page
			className="eizport-page eizport-picker"
			title={ label }
			subTitle={ __(
				'Check items to export only those: they join the export at once. Filter by Selection to see the ones chosen.',
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
			<DataViews< Item >
				data={ items }
				fields={ fields }
				view={ shownView }
				onChangeView={ setView }
				onReset={ onReset }
				paginationInfo={ {
					totalItems: total.items,
					totalPages: total.pages,
				} }
				isLoading={ isLoading }
				getItemId={ ( item ) => String( item.id ) }
				defaultLayouts={ { table: {} } }
				selection={ selection }
				onChangeSelection={ ( next ) =>
					choose(
						mergeSelection(
							selection,
							items.map( ( item ) => String( item.id ) ),
							next
						)
					)
				}
				onClickItem={ ( item ) => {
					const id = String( item.id );
					choose(
						selection.includes( id )
							? selection.filter( ( chosen ) => chosen !== id )
							: [ ...selection, id ]
					);
				} }
				actions={ [
					{
						id: 'select',
						label: __( 'Select', 'eizport' ),
						supportsBulk: true,
						callback: ( chosen ) =>
							choose( [
								...new Set( [
									...selection,
									...chosen.map( ( item ) => String( item.id ) ),
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

function Inspector() {
	const { type } = useSearch( { strict: false } ) as { type?: string };
	// A new picker (fresh view and selection) for each type.
	if ( type === OPTIONS_ROW ) {
		return <OptionsPicker />;
	}
	if ( type === REVIEWS_ROW ) {
		return <ReviewsPicker />;
	}
	if ( type === MENUS_ROW ) {
		return <MenusPicker />;
	}
	if ( type && LOOK_ROWS[ type ] ) {
		return <LookPicker key={ type } row={ type } />;
	}
	return type ? <Picker key={ type } typeName={ type } /> : null;
}

export { Inspector as inspector };
