/**
 * The side panel of a look row (Customizer theme mods and custom CSS, Editor
 * global styles and global CSS, Widgets, Advanced Custom Fields): its items from
 * eizport/get-export-options — a theme mod, a CSS rule, a group of styles, a
 * widget — each with a preview and its checkbox. Checking or clicking one
 * takes it to the export at once; the "Selection" filter shows all, the ones
 * chosen or the suggested ones (all the row has).
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
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { useNavigate } from '@wordpress/route';

import { errorMessage, getExportOptions } from './abilities';
import HeaderRow from './header-row';
import mergeSelection from './merge-selection';
import usePhoneView from './use-phone-view';
import { selectionField, selectionMode, startFilters } from './selection-filter';
import {
	LOOK_ROWS,
	chosenFor,
	setRowItems,
	suggestedFor,
	useExportState,
	useSuggestions,
} from './state';
import type { LookItem } from './types';
import { useResettableView } from './use-resettable-view';

/**
 * The row's title and what checking an item does.
 *
 * @param key Row key (theme_mods, custom_css…).
 * @return Title and subtitle.
 */
function texts( key: string ): { title: string; subTitle: string } {
	switch ( key ) {
		case 'theme_mods':
			return {
				title: __( 'Customizer theme mods', 'eizport' ),
				subTitle: __(
					'Check the settings to export: they join the other site’s theme mods, the rest staying as it is there. Images go with them.',
					'eizport'
				),
			};
		case 'custom_css':
			return {
				title: __( 'Customizer custom CSS', 'eizport' ),
				subTitle: __(
					'Check the rules to export — the last one only, say. On import they’re added at the end of the other site’s CSS, at its start, or replace its block of the same name or all of it (Details), between head/tail comments named after the file.',
					'eizport'
				),
			};
		case 'global_styles':
			return {
				title: __( 'Editor global styles', 'eizport' ),
				subTitle: __(
					'Check the groups to export: each replaces the same group on the other site, the rest of its styles staying.',
					'eizport'
				),
			};
		case 'global_css':
			return {
				title: __( 'Editor global CSS', 'eizport' ),
				subTitle: __(
					'Check the rules of the Site Editor’s Additional CSS to export; they’re added as the Customizer’s are (Details).',
					'eizport'
				),
			};
		case 'advanced-custom-fields':
			return {
				title: __( 'Advanced Custom Fields (ACF)', 'eizport' ),
				subTitle: __(
					'Check the post types, taxonomies and field groups to export, in ACF’s own export format. On import, ACF imports them before the content (one with the same key there is updated), and the fields that store IDs point to the items there.',
					'eizport'
				),
			};
		default:
			return {
				title: __( 'Widgets', 'eizport' ),
				subTitle: __(
					'Check the widgets to export: they go at the end of the same sidebar on the other site, next to its own widgets.',
					'eizport'
				),
			};
	}
}

/**
 * An item's preview: an image, color swatches, the CSS or some text.
 *
 * @param props      Props.
 * @param props.item Item.
 * @return Preview.
 */
function Preview( { item }: { item: LookItem } ) {
	const { type, value } = item.preview;
	if ( type === 'image' ) {
		return (
			<img
				className="eizport-look-preview__image"
				src={ String( value ) }
				alt=""
				loading="lazy"
			/>
		);
	}
	if ( type === 'color' || type === 'colors' ) {
		const colors = Array.isArray( value ) ? value : [ value ];
		return (
			<span className="eizport-look-preview__colors">
				{ colors.map( ( color ) => (
					<span
						key={ color }
						className="eizport-look-preview__swatch"
						style={ { background: color } }
						title={ color }
					/>
				) ) }
			</span>
		);
	}
	if ( type === 'code' ) {
		return <pre className="eizport-look-preview__code">{ String( value ) }</pre>;
	}
	return <span className="eizport-look-preview__text">{ String( value ) }</span>;
}

export default function LookPicker( { row }: { row: string } ) {
	const key = LOOK_ROWS[ row ];
	const navigate = useNavigate();
	const close = () => navigate( { href: '/export' } );
	const state = useExportState();
	useSuggestions();
	const { createErrorNotice } = useDispatch( noticesStore );
	const [ items, setItems ] = useState< LookItem[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const selection = chosenFor( state, row ).map( String );
	const suggested = suggestedFor( row ).map( String );
	const [ view, setView, onReset ] = useResettableView( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 20,
		titleField: 'label',
		// The preview under the item's name: a column of its own sits under the actions in a narrow panel.
		descriptionField: 'preview',
		fields: [ 'details' ],
		layout: { density: 'compact' },
		filters: startFilters( selection, suggested ),
	} as View );
	const mode = selectionMode( view );

	useEffect( () => {
		getExportOptions()
			.then( ( options ) => setItems( options.appearance?.[ key ] || [] ) )
			.catch( ( error ) =>
				createErrorNotice( errorMessage( error ), { type: 'snackbar' } )
			)
			.finally( () => setIsLoading( false ) );
	}, [ createErrorNotice, key ] );

	const fields: Field< LookItem >[] = useMemo(
		() => [
			selectionField< LookItem >(),
			{
				id: 'label',
				label: __( 'Item', 'eizport' ),
				enableHiding: false,
				enableGlobalSearch: true,
			},
			{
				id: 'details',
				label: __( 'Details', 'eizport' ),
				enableSorting: false,
				enableGlobalSearch: true,
				filterBy: false,
			},
			{
				id: 'preview',
				label: __( 'Preview', 'eizport' ),
				enableSorting: false,
				filterBy: false,
				getValue: ( { item } ) =>
					Array.isArray( item.preview.value )
						? item.preview.value.join( ' ' )
						: item.preview.value,
				render: ( { item } ) => <Preview item={ item } />,
			},
		],
		[]
	);

	// The Selection filter is ours: the rest of the view (search, sort, pages) is DataViews'.
	const shown = useMemo( () => {
		const keys =
			mode === 'selected' ? selection : mode === 'suggested' ? suggested : null;
		return keys ? items.filter( ( item ) => keys.includes( item.key ) ) : items;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ items, mode, selection.join(), suggested.join() ] );
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

	const shownView = usePhoneView( view, 'preview' );
	const choose = ( keys: string[] ) => setRowItems( row, keys );
	const { title, subTitle } = texts( key );

	return (
		<Page
			className="eizport-page eizport-picker"
			title={ title }
			subTitle={ subTitle }
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
			<DataViews< LookItem >
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
							data.map( ( item ) => item.key ),
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
