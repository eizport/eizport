/**
 * Settings → Eizport → Export: what goes with the items
 * exported (media, linked settings) and how bundles keep their content.
 */
import type { Form } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';

import SettingsForm from '../../src/settings-form';

const FORM: Form = {
	layout: { type: 'card' },
	fields: [
		{
			id: 'items',
			label: __( 'With the items exported', 'eizport' ),
			layout: {
				type: 'card',
				isOpened: false,
				// What is chosen, while closed.
				summary: [ { id: 'linked_options', visibility: 'when-collapsed' } ],
			},
			children: [
				'attached_only',
				'linked_options',
				'bundle_zip',
				'plugin_content',
				'plugin_import_again',
				'content_files',
				'css_markers',
			],
		},
	],
};

function Stage() {
	return (
		<SettingsForm
			active="/export"
			subTitle={ __(
				'What comes with the items exported, and how bundles keep their content.',
				'eizport'
			) }
			form={ FORM }
		/>
	);
}

export const stage = Stage;
