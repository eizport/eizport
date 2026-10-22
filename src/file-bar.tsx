/**
 * The Export screen's "Details" panel, above the list: a line with the
 * file's settings in a few words that opens (always closed to start) the quick
 * settings in a DataForm row (format, name, media; content as .html is a
 * setting) and "Themes and plugins" — a modal with the themes and plugins a
 * Playground .zip installs, enabled with that format.
 */
import { Button, Modal, Popover, ToggleControl } from '@wordpress/components';
import { DataForm, type Field, type Form } from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { chevronDown, chevronRight, Icon, info } from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

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

const MORE_FORM: Form = {
	layout: { type: 'card' },
	fields: [
		{
			id: 'playground',
			label: __( 'Playground', 'eizport' ),
			description: __(
				'What the WordPress Playground .zip installs — any installed theme or plugin. The ones from the WordPress.org directory are installed from there, in the version this site has; premium and custom ones go inside the file: share it only where their licenses allow.',
				'eizport'
			),
			layout: { type: 'card', isCollapsible: true, isOpened: true },
			children: [ 'themes', 'plugins' ],
		},
	],
};

/**
 * A toggle with an ⓘ whose help shows in the same popover on hover and focus,
 * and stays open or closes on click.
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
		<Stack align="center" gap="xs">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				checked={ checked }
				disabled={ disabled }
				onChange={ onChange }
			/>
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
		</Stack>
	);
}

type EditProps = {
	data: FileSettings;
	field: { label: string };
	onChange: ( edits: Partial< FileSettings > ) => void;
};

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
	bundle: boolean
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
						label: __( 'Replace the site’s CSS', 'eizport' ),
					},
				],
				// Only with CSS rules chosen (Customizer custom CSS, Editor global CSS).
				isVisible: ( item: FileSettings ) => hasCss( item ),
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
				description: __(
					'A child theme brings its parent. The active one is activated, if chosen.',
					'eizport'
				),
			},
			{
				id: 'plugins',
				label: __(
					'Plugins to install and activate',
					'eizport'
				),
				type: 'array',
				elements: ( options?.plugins || [] ).map( ( plugin ) => ( {
					value: plugin.plugin,
					label: plugin.name,
				} ) ),
			},
		],
		[ options, bundle ]
	);
}

/**
 * What the modal holds, in a few words.
 *
 * @param settings File settings.
 * @return Summary.
 */
function summary( settings: FileSettings ): string {
	return sprintf(
		/* translators: 1: number of themes, 2: number of plugins. */
		__( 'Playground: %1$s, %2$s', 'eizport' ),
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
		// Themes and plugins only when some are chosen.
		...( settings.format === 'playground' &&
		settings.themes.length + settings.plugins.length > 0
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
			? [ __( 'CSS replaces', 'eizport' ) ]
			: [] ),
	]
		.filter( Boolean )
		.join( ' · ' );
}

export default function FileBar( { options }: { options?: ExportOptions } ) {
	const state = useExportState();
	const { settings } = state;
	const bundle = needsBundle( state );
	const fields = useFields( options, bundle );
	const [ isOpen, setIsOpen ] = useState( false );
	// Always closed to start with.
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ draft, setDraft ] = useState< FileSettings >( settings );
	const isPlayground = settings.format === 'playground';
	// Themes and plugins the Playground .zip installs, on its button.
	const chosenCount = isPlayground
		? settings.themes.length + settings.plugins.length
		: 0;

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
			{ /* Always there, so the bar doesn't shift: off unless Playground. A count, like the filters', once themes or plugins are chosen. */ }
			<Stack align="center" gap="md" className="eizport-file-bar__more">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					disabled={ ! isPlayground }
					accessibleWhenDisabled
					className="eizport-counted"
					aria-label={
						chosenCount > 0
							? `${ __( 'Themes and plugins', 'eizport' ) } (${ summary( settings ) })`
							: undefined
					}
					onClick={ () => {
						setDraft( settings );
						setIsOpen( true );
					} }
				>
					{ __( 'Themes and plugins', 'eizport' ) }
					{ chosenCount > 0 && (
						<span className="eizport-count" aria-hidden="true">
							{ chosenCount }
						</span>
					) }
				</Button>
			</Stack>
			{ isOpen && (
				<Modal
					title={ __( 'Playground themes and plugins', 'eizport' ) }
					size="medium"
					className="eizport-options-modal"
					onRequestClose={ () => setIsOpen( false ) }
				>
					<DataForm< FileSettings >
						data={ draft }
						fields={ fields }
						form={ MORE_FORM }
						onChange={ ( edits ) =>
							setDraft( ( current ) => ( { ...current, ...edits } ) )
						}
					/>
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
