/**
 * The Export screen's "Details" panel, above the list: a line with the
 * file's settings in a few words that opens (always closed to start) the quick
 * settings in a DataForm row (format, name, media; content as .html is a
 * setting) and "Playground options" — a modal with where a Playground .zip
 * opens and the themes and plugins it installs, enabled with that format: two
 * groups that open one at a time, each with an ⓘ on its title.
 */
import {
	Button,
	Modal,
	Popover,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { DataForm, type Field, type Form } from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	chevronDown,
	chevronRight,
	chevronUp,
	Icon,
	info,
} from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

import { getItems } from './abilities';
import { needsBundle, setExportState, useExportState } from './state';
import type { ExportOptions, FileSettings } from './types';

/**
 * Whether CSS rules are chosen (the Customizer's or the Site Editor's).
 *
 * @param settings File settings.
 * @return Whether there are.
 */
function hasCss( settings: FileSettings ): boolean {
	return [ 'custom_css', 'global_css' ].some(
		( key ) =>
			settings.appearance.includes( key ) ||
			// A checked row brings what it suggests: all its rules.
			settings.include_suggested.includes( `__${ key }` ) ||
			( settings.appearance_items?.[ key ] || [] ).length > 0
	);
}

const BAR_FORM: Form = {
	layout: { type: 'row', alignment: 'end' },
	fields: [
		'format',
		'filename',
		'media',
		'as_plugin',
		'update_existing',
		'css_mode',
	],
};

/**
 * The Playground options' groups: a title with an ⓘ, and the fields.
 */
const PLAYGROUND_GROUPS: {
	id: string;
	title: string;
	help: string;
	form: Form;
}[] = [
	{
		id: 'landing',
		title: __( 'Landing page', 'eizport' ),
		help: __(
			'Where Playground opens once the site is ready: the home page, the dashboard, a page of this site or a path.',
			'eizport'
		),
		form: { fields: [ 'landing', 'landing_page', 'landing_path' ] },
	},
	{
		id: 'themes',
		title: __( 'Themes and plugins', 'eizport' ),
		help: __(
			'What the WordPress Playground .zip installs — any installed theme or plugin, active or inactive as on this site; a child theme brings its parent, and the active theme is activated if chosen. The ones from the WordPress.org directory are installed from there, in the version this site has; premium and custom ones go inside the file: share it only where their licenses allow.',
			'eizport'
		),
		form: { fields: [ 'themes', 'plugins' ] },
	},
];

/**
 * An ⓘ whose help shows in the same popover on hover and focus, and stays
 * open or closes on click.
 *
 * @param props      Props.
 * @param props.help Help text.
 * @return Button and popover.
 */
function InfoButton( { help }: { help: string } ) {
	const [ anchor, setAnchor ] = useState< HTMLButtonElement | null >( null );
	const [ isHelpOpen, setIsHelpOpen ] = useState( false );
	const [ isHovered, setIsHovered ] = useState( false );
	useEffect( () => {
		if ( ! isHelpOpen ) {
			return;
		}
		const close = ( event: PointerEvent ) => {
			const target = event.target as Node;
			if (
				! anchor?.contains( target ) &&
				! ( target as Element ).closest?.( '.eizport-help-popover' )
			) {
				setIsHelpOpen( false );
			}
		};
		const escape = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				setIsHelpOpen( false );
			}
		};
		document.addEventListener( 'pointerdown', close );
		document.addEventListener( 'keydown', escape );
		return () => {
			document.removeEventListener( 'pointerdown', close );
			document.removeEventListener( 'keydown', escape );
		};
	}, [ isHelpOpen, anchor ] );

	return (
		<>
			<Button
				ref={ setAnchor }
				size="small"
				variant="tertiary"
				icon={ info }
				label={ help }
				// Hover, focus and click show the same popover.
				showTooltip={ false }
				aria-expanded={ isHelpOpen }
				onClick={ () => {
					// A click keeps the hover's popover open; another one closes it.
					setIsHovered( false );
					setIsHelpOpen( ! isHelpOpen );
				} }
				onMouseEnter={ () => setIsHovered( true ) }
				onMouseLeave={ () => setIsHovered( false ) }
				onFocus={ () => setIsHovered( true ) }
				onBlur={ () => setIsHovered( false ) }
			/>
			{ ( isHelpOpen || isHovered ) && (
				<Popover
					anchor={ anchor }
					placement="top"
					shift
					className="eizport-help-popover"
					// Focus stays on the ⓘ, which toggles it; Escape and a click elsewhere close it.
					focusOnMount={ false }
					onClose={ () => setIsHelpOpen( false ) }
				>
					{ help }
				</Popover>
			) }
		</>
	);
}

/**
 * A toggle with an ⓘ (InfoButton).
 *
 * @param props          Props.
 * @param props.label    Toggle label.
 * @param props.help     Help text.
 * @param props.checked  Whether it is on.
 * @param props.disabled Whether it can't change.
 * @param props.onChange Sets it.
 * @return Control.
 */
function ToggleWithHelp( {
	label,
	help,
	checked,
	disabled = false,
	onChange,
}: {
	label: string;
	help: string;
	checked: boolean;
	disabled?: boolean;
	onChange: ( checked: boolean ) => void;
} ) {
	return (
		<Stack align="center" gap="xs">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				checked={ checked }
				disabled={ disabled }
				onChange={ onChange }
			/>
			<InfoButton help={ help } />
		</Stack>
	);
}

/**
 * A Playground options group: a header that opens and closes it (one open at
 * a time), its title with an ⓘ, and, while closed, what it holds.
 *
 * @param props          Props.
 * @param props.title    Title.
 * @param props.help     Help on the ⓘ.
 * @param props.summary  What it holds, while closed.
 * @param props.isOpen   Whether it is open.
 * @param props.onToggle Opens or closes it.
 * @param props.children Its fields.
 * @return Group.
 */
function PlaygroundGroup( {
	title,
	help,
	summary,
	isOpen,
	onToggle,
	children,
}: {
	title: string;
	help: string;
	summary: string;
	isOpen: boolean;
	onToggle: () => void;
	children: ReactNode;
} ) {
	return (
		<div className="eizport-playground-group">
			<Stack
				align="center"
				justify="space-between"
				className="eizport-playground-group__header"
			>
				<Stack align="center" gap="xs">
					<h2 className="eizport-playground-group__title">{ title }</h2>
					<InfoButton help={ help } />
				</Stack>
				<Button
					__next40pxDefaultSize
					size="compact"
					variant="tertiary"
					icon={ isOpen ? chevronUp : chevronDown }
					iconPosition="right"
					aria-expanded={ isOpen }
					label={ title }
					showTooltip={ false }
					onClick={ onToggle }
				>
					{ isOpen ? null : summary }
				</Button>
			</Stack>
			{ isOpen && (
				<div className="eizport-playground-group__body">{ children }</div>
			) }
		</div>
	);
}

type EditProps = {
	data: FileSettings;
	field: {
		label: string;
		elements?: { value: string | number; label: string }[];
	};
	onChange: ( edits: Partial< FileSettings > ) => void;
};

/**
 * "Page" (the landing page): the site's pages, with an ⓘ saying the page
 * goes in the blueprint as a relative path.
 *
 * @param props          Field edit props.
 * @param props.data     File settings.
 * @param props.field    The field.
 * @param props.onChange Sets settings.
 * @return Control.
 */
function LandingPageEdit( { data, field, onChange }: EditProps ) {
	return (
		<Stack direction="column" gap="xs">
			<Stack align="center" gap="xs">
				<span className="eizport-field-label">{ field.label }</span>
				<InfoButton
					help={ __(
						'The page is saved as a relative path, /?pagename=<its slug>, so Playground opens the same page whatever ID it gets there.',
						'eizport'
					) }
				/>
			</Stack>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				hideLabelFromVision
				label={ field.label }
				value={ String( data.landing_page || 0 ) }
				options={ [
					{ value: '0', label: __( 'Choose a page', 'eizport' ) },
					...( field.elements || [] ).map( ( element ) => ( {
						value: String( element.value ),
						label: element.label,
					} ) ),
				] }
				onChange={ ( value ) =>
					onChange( { landing_page: parseInt( value, 10 ) || 0 } )
				}
			/>
		</Stack>
	);
}

/**
 * "Media files": the attachments' files go inside the bundle.
 *
 * @param props          Field edit props.
 * @param props.data     File settings.
 * @param props.field    The field.
 * @param props.onChange Sets settings.
 * @return Control.
 */
function MediaEdit( { data, field, onChange }: EditProps ) {
	return (
		<ToggleWithHelp
			label={ field.label }
			help={ __(
				'The exported attachments’ files (images, PDFs, videos) go inside the file, so the importer doesn’t download them from this site. Off, the importer can still fetch them while this site is online.',
				'eizport'
			) }
			checked={ data.media }
			onChange={ ( checked ) => onChange( { media: checked } ) }
		/>
	);
}

/**
 * "As plugin": off and disabled with the Playground .zip.
 *
 * @param props          Field edit props.
 * @param props.data     File settings.
 * @param props.field    The field.
 * @param props.onChange Sets settings.
 * @return Control.
 */
function AsPluginEdit( { data, field, onChange }: EditProps ) {
	const isPlayground = data.format === 'playground';
	return (
		<ToggleWithHelp
			label={ field.label }
			help={ __(
				'A plugin .zip holding the bundle: installed on the other site, it imports itself from its screen under Tools.',
				'eizport'
			) }
			checked={ ! isPlayground && data.as_plugin }
			disabled={ isPlayground }
			onChange={ ( checked ) => onChange( { as_plugin: checked } ) }
		/>
	);
}

/**
 * "Update existing": the bundle asks its importer to update what's there; off
 * and disabled with the Playground .zip.
 *
 * @param props          Field edit props.
 * @param props.data     File settings.
 * @param props.field    The field.
 * @param props.onChange Sets settings.
 * @return Control.
 */
function UpdateExistingEdit( { data, field, onChange }: EditProps ) {
	// Playground starts from a new site: nothing to update.
	const isPlayground = data.format === 'playground';
	return (
		<ToggleWithHelp
			label={ field.label }
			help={ __(
				'On import, pages, posts and products that already exist on the other site (same slug) are updated with this content instead of getting copies — the homepage stays the homepage, a second delivery updates the first. The importer can still turn it off.',
				'eizport'
			) }
			checked={ ! isPlayground && data.update_existing }
			disabled={ isPlayground }
			onChange={ ( checked ) => onChange( { update_existing: checked } ) }
		/>
	);
}

/**
 * The bundle's name in the format toggle.
 *
 * @param options What the site offers (eizport/get-export-options).
 * @return "WXRZ", or "WXR ZIP" with Settings → Export → "Bundle as .zip".
 */
function bundleLabel( options?: ExportOptions ): string {
	return options?.bundle_zip
		? __( 'WXR ZIP', 'eizport' )
		: __( 'WXRZ', 'eizport' );
}

/**
 * The file settings' fields.
 *
 * @param options What the site offers (eizport/get-export-options).
 * @return Fields.
 */
function useFields(
	options: ExportOptions | undefined,
	bundle: boolean,
	pages: { value: number; label: string }[] = []
): Field< FileSettings >[] {
	return useMemo(
		() => [
			{
				id: 'format',
				label: __( 'Format', 'eizport' ),
				type: 'text',
				Edit: 'toggleGroup',
				elements: [
					// Core's WXR; a .wxrz bundle once media or settings go with it.
					{
						value: 'wxr',
						label: bundle
							? bundleLabel( options )
							: __( 'WXR XML', 'eizport' ),
					},
					{
						value: 'playground',
						label: __( 'Playground', 'eizport' ),
					},
				],
			},
			{
				id: 'filename',
				label: __( 'File name', 'eizport' ),
				type: 'text',
				// Core's name for today: what an empty field gives.
				placeholder: options?.filename || '',
			},
			{
				id: 'media',
				label: __( 'Media files', 'eizport' ),
				type: 'boolean',
				// The toggle, and its help on an ⓘ.
				Edit: MediaEdit,
			},
			{
				id: 'as_plugin',
				label: __( 'As plugin', 'eizport' ),
				type: 'boolean',
				// The toggle, and its help on an ⓘ instead of a line under it.
				Edit: AsPluginEdit,
			},
			{
				id: 'update_existing',
				label: __( 'Update existing', 'eizport' ),
				type: 'boolean',
				// The toggle, and its help on an ⓘ.
				Edit: UpdateExistingEdit,
			},
			{
				id: 'css_mode',
				label: __( 'CSS rules on import', 'eizport' ),
				type: 'text',
				Edit: 'select',
				elements: [
					{
						value: 'end',
						label: __( 'Add at the end of the site’s CSS', 'eizport' ),
					},
					{
						value: 'start',
						label: __( 'Add at the start of the site’s CSS', 'eizport' ),
					},
					{
						value: 'replace',
						label: __(
							'Replace the block of the same name (at the end when the site has none)',
							'eizport'
						),
					},
					{
						value: 'replace_all',
						label: __( 'Replace all the site’s CSS', 'eizport' ),
					},
				],
				// Only with CSS rules chosen (Customizer custom CSS, Editor global CSS).
				isVisible: ( item: FileSettings ) => hasCss( item ),
			},
			{
				id: 'landing',
				label: __( 'Opens on', 'eizport' ),
				type: 'text',
				// Buttons, like Format's.
				Edit: 'toggleGroup',
				elements: [
					{ value: 'home', label: __( 'Home page', 'eizport' ) },
					{ value: 'admin', label: __( 'Dashboard', 'eizport' ) },
					{ value: 'page', label: __( 'A page', 'eizport' ) },
					{ value: 'path', label: __( 'A path', 'eizport' ) },
				],
			},
			{
				id: 'landing_page',
				label: __( 'Page', 'eizport' ),
				type: 'integer',
				Edit: LandingPageEdit,
				elements: pages,
				isVisible: ( item: FileSettings ) => item.landing === 'page',
			},
			{
				id: 'landing_path',
				label: __( 'Path', 'eizport' ),
				type: 'text',
				placeholder: '/shop/',
				isVisible: ( item: FileSettings ) => item.landing === 'path',
			},
			{
				id: 'themes',
				label: __( 'Themes to install', 'eizport' ),
				type: 'array',
				elements: ( options?.themes || [] ).map( ( theme ) => ( {
					value: theme.stylesheet,
					label: theme.active
						? `${ theme.name } (${ __(
								'active',
								'eizport'
						  ) })`
						: theme.name,
				} ) ),
			},
			{
				id: 'plugins',
				label: __( 'Plugins to install', 'eizport' ),
				type: 'array',
				// Active or inactive in Playground, as here.
				elements: ( options?.plugins || [] ).map( ( plugin ) => ( {
					value: plugin.plugin,
					label: `${ plugin.name } (${
						plugin.active
							? __( 'active', 'eizport' )
							: __( 'inactive', 'eizport' )
					})`,
				} ) ),
			},
		],
		[ options, bundle, pages ]
	);
}

/**
 * What the modal holds, in a few words.
 *
 * @param settings File settings.
 * @return Summary.
 */
function summary( settings: FileSettings ): string {
	const opens = landingWords( settings );
	return sprintf(
		/* translators: 1: number of themes and plugins ("1 theme, 2 plugins"), 2: where Playground opens (", opens on the dashboard"), or nothing for the home page. */
		__( 'Playground: %1$s%2$s', 'eizport' ),
		themesAndPlugins( settings ),
		opens ? `, ${ opens }` : ''
	);
}

/**
 * The themes and plugins chosen, counted: "1 theme, 2 plugins".
 *
 * @param settings File settings.
 * @return Words.
 */
function themesAndPlugins( settings: FileSettings ): string {
	return sprintf(
		/* translators: 1: number of themes, 2: number of plugins. */
		__( '%1$s, %2$s', 'eizport' ),
		sprintf(
			/* translators: %d: number of themes. */
			_n( '%d theme', '%d themes', settings.themes.length, 'eizport' ),
			settings.themes.length
		),
		sprintf(
			/* translators: %d: number of plugins. */
			_n( '%d plugin', '%d plugins', settings.plugins.length, 'eizport' ),
			settings.plugins.length
		)
	);
}

/**
 * Where Playground opens, in words; nothing for the home page.
 *
 * @param settings File settings.
 * @return Words.
 */
function landingWords( settings: FileSettings ): string {
	if ( settings.landing === 'admin' ) {
		return __( 'opens on the dashboard', 'eizport' );
	}
	if ( settings.landing === 'page' && settings.landing_page ) {
		return __( 'opens on a page', 'eizport' );
	}
	if ( settings.landing === 'path' && settings.landing_path.startsWith( '/' ) ) {
		/* translators: %s: a path, like "/shop/". */
		return sprintf( __( 'opens on %s', 'eizport' ), settings.landing_path );
	}
	return '';
}

/**
 * Where Playground opens, for the closed "Landing page" group: the page's
 * title, the path, or the choice.
 *
 * @param settings File settings.
 * @param pages    The site's pages.
 * @return Words.
 */
function landingSummary(
	settings: FileSettings,
	pages: { value: number; label: string }[]
): string {
	if ( settings.landing === 'admin' ) {
		return __( 'Dashboard', 'eizport' );
	}
	if ( settings.landing === 'page' && settings.landing_page ) {
		return (
			pages.find( ( page ) => page.value === settings.landing_page )
				?.label || __( 'A page', 'eizport' )
		);
	}
	if ( settings.landing === 'path' && settings.landing_path.startsWith( '/' ) ) {
		return settings.landing_path;
	}
	return __( 'Home page', 'eizport' );
}

/**
 * How many Playground options are set: themes, plugins, and one more when
 * it doesn't open on the home page.
 *
 * @param settings File settings.
 * @return Count.
 */
function playgroundCount( settings: FileSettings ): number {
	return (
		settings.themes.length +
		settings.plugins.length +
		( landingWords( settings ) ? 1 : 0 )
	);
}

/**
 * The file in a few words, for the closed panel.
 *
 * @param settings File settings.
 * @param format   The format's label.
 * @return Summary, like "WXRZ · default filename · media files".
 */
function fileSummary( settings: FileSettings, format: string ): string {
	return [
		format,
		// Whether a name was typed, not the name: it would make the line long.
		settings.filename.trim()
			? __( 'custom filename', 'eizport' )
			: __( 'default filename', 'eizport' ),
		settings.media
			? __( 'media files', 'eizport' )
			: __( 'no media files', 'eizport' ),
		// Playground options only when some are set.
		...( settings.format === 'playground' && playgroundCount( settings ) > 0
			? [ summary( settings ) ]
			: [] ),
		...( settings.format !== 'playground' && settings.as_plugin
			? [ __( 'as plugin', 'eizport' ) ]
			: [] ),
		...( settings.format !== 'playground' && settings.update_existing
			? [ __( 'updates existing', 'eizport' ) ]
			: [] ),
		...( hasCss( settings ) && settings.css_mode === 'start'
			? [ __( 'CSS at the start', 'eizport' ) ]
			: [] ),
		...( hasCss( settings ) && settings.css_mode === 'replace'
			? [ __( 'CSS replaces its block', 'eizport' ) ]
			: [] ),
		...( hasCss( settings ) && settings.css_mode === 'replace_all'
			? [ __( 'CSS replaces all', 'eizport' ) ]
			: [] ),
	]
		.filter( Boolean )
		.join( ' · ' );
}

export default function FileBar( { options }: { options?: ExportOptions } ) {
	const state = useExportState();
	const { settings } = state;
	const bundle = needsBundle( state );
	// The site's pages, for "Opens on: A page" (the first hundred, by title).
	const [ pages, setPages ] = useState< { value: number; label: string }[] >(
		[]
	);
	useEffect( () => {
		if ( settings.format !== 'playground' ) {
			return;
		}
		getItems( {
			type: 'page',
			per_page: 100,
			orderby: 'title',
			order: 'asc',
			filters: { status: 'publish' },
		} )
			.then( ( result ) =>
				setPages(
					result.items.map( ( item ) => ( {
						value: item.id,
						label: item.title || `#${ item.id }`,
					} ) )
				)
			)
			.catch( () => setPages( [] ) );
	}, [ settings.format ] );
	const fields = useFields( options, bundle, pages );
	// The Playground options' groups open one at a time, closed to start with.
	const [ openGroup, setOpenGroup ] = useState< string | null >( null );
	const [ isOpen, setIsOpen ] = useState( false );
	// Always closed to start with.
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ draft, setDraft ] = useState< FileSettings >( settings );
	const isPlayground = settings.format === 'playground';
	// What the Playground options set, on their button: themes, plugins, and where it opens.
	const chosenCount = isPlayground ? playgroundCount( settings ) : 0;

	const save = ( edits: Partial< FileSettings > ) =>
		setExportState( ( current ) => ( {
			...current,
			settings: { ...current.settings, ...edits },
		} ) );


	let format: string = __( 'Playground', 'eizport' );
	if ( settings.format !== 'playground' ) {
		format = bundle
			? bundleLabel( options )
			: __( 'WXR XML', 'eizport' );
	}

	return (
		<div className="eizport-file-panel">
			<button
				type="button"
				className="eizport-file-panel__toggle"
				aria-expanded={ isExpanded }
				aria-controls="eizport-file-bar"
				onClick={ () => setIsExpanded( ( current ) => ! current ) }
			>
				<Icon icon={ isExpanded ? chevronDown : chevronRight } size={ 20 } />
				<span className="eizport-file-panel__label">
					{ __( 'Details', 'eizport' ) }
				</span>
				<span className="eizport-file-panel__summary">
					{ fileSummary( settings, format ) }
				</span>
			</button>
		<Stack
			id="eizport-file-bar"
			className="eizport-file-bar"
			justify="space-between"
			align="end"
			gap="lg"
			hidden={ ! isExpanded }
		>
			<DataForm< FileSettings >
				data={ settings }
				fields={ fields }
				form={ BAR_FORM }
				onChange={ save }
			/>
			{ /* Always there, so the bar doesn't shift: off unless Playground. A count, like the filters', once themes, plugins or a landing page are set. */ }
			<Stack align="center" gap="md" className="eizport-file-bar__more">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					disabled={ ! isPlayground }
					accessibleWhenDisabled
					className="eizport-counted"
					aria-label={
						chosenCount > 0
							? `${ __( 'Playground options', 'eizport' ) } (${ summary( settings ) })`
							: undefined
					}
					onClick={ () => {
						setDraft( settings );
						setOpenGroup( null );
						setIsOpen( true );
					} }
				>
					{ __( 'Playground options', 'eizport' ) }
					{ chosenCount > 0 && (
						<span className="eizport-count" aria-hidden="true">
							{ chosenCount }
						</span>
					) }
				</Button>
			</Stack>
			{ isOpen && (
				<Modal
					title={ __( 'Playground options', 'eizport' ) }
					size="medium"
					className="eizport-options-modal"
					onRequestClose={ () => setIsOpen( false ) }
				>
					<Stack direction="column" gap="md">
						{ PLAYGROUND_GROUPS.map( ( group ) => (
							<PlaygroundGroup
								key={ group.id }
								title={ group.title }
								help={ group.help }
								summary={
									group.id === 'landing'
										? landingSummary( draft, pages )
										: themesAndPlugins( draft )
								}
								isOpen={ openGroup === group.id }
								onToggle={ () =>
									setOpenGroup(
										openGroup === group.id ? null : group.id
									)
								}
							>
								<DataForm< FileSettings >
									data={ draft }
									fields={ fields }
									form={ group.form }
									onChange={ ( edits ) =>
										setDraft( ( current ) => ( {
											...current,
											...edits,
										} ) )
									}
								/>
							</PlaygroundGroup>
						) ) }
					</Stack>
					<Stack justify="flex-end" gap="sm" className="eizport-modal-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setIsOpen( false ) }
						>
							{ __( 'Cancel', 'eizport' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ () => {
								save( draft );
								setIsOpen( false );
							} }
						>
							{ __( 'Done', 'eizport' ) }
						</Button>
					</Stack>
				</Modal>
			) }
		</Stack>
		</div>
	);
}
