/**
 * A tab of Settings → Eizport: a DataForm in cards with Save in
 * the page header, as Gutenberg's Content Types form
 * (eizport/get-settings, eizport/update-settings). Each tab shows some of
 * the settings; Save sends them all.
 */
import { Page } from '@wordpress/admin-ui';
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { DataForm, type Field, type Form } from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';
import { Stack } from '@wordpress/ui';
import type { MouseEvent, ReactNode } from 'react';

import { mark } from './mark';
import {
	errorMessage,
	getSettings,
	updateSettings,
	type SettingValues,
} from './abilities';
import PageTabs from './page-tabs';
import './style.scss';
import { SETTINGS_TABS } from './tabs';
import type { Settings } from './types';

type Values = SettingValues;

const KEYS: ( keyof Values )[] = [
	'copy_lifetime',
	'replace_export',
	'classic_ui',
	'style_import',
	'import_direct',
	'content_files',
	'attached_only',
	'bundle_zip',
	'plugin_content',
	'plugin_import_again',
	'defaults_scope',
	'linked_options',
	'css_markers',
];

export default function SettingsForm( {
	active,
	subTitle,
	form,
	footer,
}: {
	/** The tab's route, like "/general". */
	active: string;
	subTitle: string;
	form: Form;
	footer?: ReactNode;
} ) {
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const [ saved, setSaved ] = useState< Settings >();
	const [ values, setValues ] = useState< Values >();
	const [ isSaving, setIsSaving ] = useState( false );
	// The cards open one at a time, like an accordion.
	const [ openCard, setOpenCard ] = useState< string | null >( null );
	const accordion = useMemo< Form >(
		() => ( {
			...form,
			fields: ( form.fields ?? [] ).map( ( field ) =>
				typeof field === 'object' && field.layout?.type === 'card'
					? ( {
							...field,
							layout: {
								...field.layout,
								isOpened: field.id === openCard,
							},
					  } as typeof field )
					: field
			),
		} ),
		[ form, openCard ]
	);
	// DataForm's cards keep their own state: a click on a card's header says which one opens.
	const onCardToggle = ( event: MouseEvent< HTMLElement > ) => {
		const header = ( event.target as HTMLElement ).closest(
			'[aria-expanded]'
		);
		const card = header?.closest( '.dataforms-layouts-card__field' );
		if ( ! header || ! card ) {
			return;
		}
		const cards = Array.from(
			event.currentTarget.querySelectorAll(
				'.dataforms-layouts-card__field'
			)
		);
		const field = ( form.fields ?? [] )[ cards.indexOf( card ) ];
		const id = typeof field === 'object' ? field.id : field;
		const opening = header.getAttribute( 'aria-expanded' ) !== 'true';
		setOpenCard( opening ? id ?? null : null );
	};

	useEffect( () => {
		getSettings()
			.then( ( settings ) => {
				setSaved( settings );
				setValues( {
					copy_lifetime: settings.copy_lifetime,
					replace_export: settings.replace_export,
					classic_ui: settings.classic_ui,
					style_import: settings.style_import,
					import_direct: settings.import_direct,
					content_files: settings.content_files,
					attached_only: settings.attached_only,
					bundle_zip: settings.bundle_zip,
					plugin_content: settings.plugin_content,
					plugin_import_again: settings.plugin_import_again,
					defaults_scope: settings.defaults_scope,
					linked_options: settings.linked_options,
					css_markers: settings.css_markers,
				} );
			} )
			.catch( ( error ) =>
				createErrorNotice( errorMessage( error ), { type: 'snackbar' } )
			);
	}, [ createErrorNotice ] );

	const fields: Field< Values >[] = useMemo(
		() => [
			{
				id: 'copy_lifetime',
				label: __( 'Keep copies for', 'eizport' ),
				type: 'integer',
				Edit: 'select',
				elements: ( saved?.lifetime_choices || [] ).map( ( choice ) => ( {
					value: choice.value,
					label: choice.label,
				} ) ),
				description: __(
					'After that, a copy stops being served and is deleted. Copies already kept follow the new choice too.',
					'eizport'
				),
			},
			{
				id: 'replace_export',
				label: __( 'Replace Tools → Export', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'Tools → Export opens the plugin’s Export screen. Off, WordPress’s Export screen stays as it is, and the plugin’s is at Tools → Eizport.',
					'eizport'
				),
			},
			{
				id: 'classic_ui',
				label: __( 'Use the classic Export screen', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				// The classic screen is WordPress's, changed: only while it's replaced.
				isVisible: ( item ) => item.replace_export,
				description: __(
					'Tools → Export opens WordPress’s classic screen, with the plugin’s choices added, instead of the new one.',
					'eizport'
				),
			},
			{
				id: 'style_import',
				label: __( 'Style Tools → Import → WordPress', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'The WordPress Importer looks like the plugin’s Export screen: a header with the main button, its steps in cards, and a file button in the admin’s language. Off, it looks as WordPress made it (.wxrz bundles are accepted either way).',
					'eizport'
				),
			},
			{
				id: 'import_direct',
				label: __(
					'Open the WordPress Importer from Tools → Import',
					'eizport'
				),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'Tools → Import goes straight to the WordPress Importer (when it is active); its screen links to all importers. Off, Tools → Import lists them, as WordPress does.',
					'eizport'
				),
			},
			{
				id: 'attached_only',
				label: __( 'Only attached media', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'Items exported bring their attached media and featured images, and also the media they use without being attached: images, galleries, covers and files in their content (and product galleries). On, only the attached ones, like WordPress.',
					'eizport'
				),
			},
			{
				id: 'bundle_zip',
				label: __( 'Bundle as .zip', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'Bundles are plain .zip files, which any computer opens (the default), instead of .wxrz (a gzipped tar) — inside a plugin made as plugin too. Same contents; the importer takes both.',
					'eizport'
				),
			},
			{
				id: 'css_markers',
				label: __( 'Mark imported CSS', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'CSS rules exported from the look’s rows go between "head" and "tail" comments named after the file, to show where they came from. The default for exports and for the importer, which can change it.',
					'eizport'
				),
			},
			{
				id: 'plugin_content',
				label: __( 'As plugin holds the bundle as', 'eizport' ),
				type: 'text',
				Edit: 'select',
				elements: saved?.plugin_content_choices || [],
				description: __(
					'One .wxrz file, like any export; or its files (manifest.json, the WXR formatted line by line, uploads/…), next to the plugin’s or in a bundle/ folder, to look at or change before installing it.',
					'eizport'
				),
			},
			{
				id: 'plugin_import_again',
				label: __( 'As plugin: allow importing again', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'Once it imported, a plugin exported "as plugin" still offers Import again. Off, it only offers to deactivate and delete itself.',
					'eizport'
				),
			},
			{
				id: 'content_files',
				label: __( 'Content as .html files', 'eizport' ),
				type: 'boolean',
				Edit: 'toggle',
				description: __(
					'Each post’s content goes to post_content/<slug>.html in the .wxrz or Playground .zip, instead of inside the WXR. Off, it all stays in the WXR.',
					'eizport'
				),
			},
			{
				id: 'defaults_scope',
				label: __( 'Defaults are saved for', 'eizport' ),
				type: 'text',
				Edit: 'radio',
				elements: saved?.scope_choices || [],
			},
			{
				id: 'linked_options',
				label: __( 'Linked settings', 'eizport' ),
				type: 'text',
				Edit: 'select',
				elements: saved?.linked_choices || [],
				description: __(
					'Settings that point to the items exported (homepage, posts page, privacy policy page, site icon, pages plugins register like WooCommerce’s shop and cart). When items are checked, they show in the Options row at once; on export, they also come with items exported by filters or with all content.',
					'eizport'
				),
			},
		],
		[ saved ]
	);

	const changed =
		!! saved &&
		!! values &&
		KEYS.some( ( key ) => values[ key ] !== saved[ key ] );

	const onSave = async () => {
		if ( ! values ) {
			return;
		}
		setIsSaving( true );
		try {
			const settings = await updateSettings( values );
			// The admin menu changes with these: load the page again.
			if (
				saved &&
				( settings.replace_export !== saved.replace_export ||
					settings.classic_ui !== saved.classic_ui )
			) {
				window.location.reload();
				return;
			}
			setSaved( settings );
			createSuccessNotice( __( 'Settings saved.', 'eizport' ), {
				type: 'snackbar',
			} );
		} catch ( error ) {
			createErrorNotice( errorMessage( error ), { type: 'snackbar' } );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Page
			className="eizport-page"
			visual={ <Icon icon={ mark } /> }
			title={ __( 'Eizport', 'eizport' ) }
			subTitle={ subTitle }
			actions={
				<Button
					__next40pxDefaultSize
					variant="primary"
					size="compact"
					type="submit"
					form="eizport-settings-form"
					isBusy={ isSaving }
					disabled={ isSaving || ! changed }
					accessibleWhenDisabled
				>
					{ __( 'Save', 'eizport' ) }
				</Button>
			}
			hasPadding={ false }
		>
			<div className="eizport-tabs-row">
				<PageTabs tabs={ SETTINGS_TABS() } active={ active } />
			</div>
			<Stack
				direction="column"
				gap="md"
				className="eizport-form"
				render={
					<form
						id="eizport-settings-form"
						onClickCapture={ onCardToggle }
						onSubmit={ ( event ) => {
							event.preventDefault();
							onSave();
						} }
					/>
				}
			>
				{ values && (
					<DataForm< Values >
						data={ values }
						fields={ fields }
						form={ accordion }
						onChange={ ( edits ) =>
							setValues( ( current ) =>
								current
									? {
											...current,
											...edits,
											// A select gives its value as a string.
											copy_lifetime: Number(
												edits.copy_lifetime ??
													current.copy_lifetime
											),
									  }
									: current
							)
						}
					/>
				) }
				{ footer }
			</Stack>
		</Page>
	);
}

