/**
 * Step 1 of the WordPress Importer (includes/import.php): "Update items
 * that already exist" and, when the bundle has CSS rules, how they're added
 * ("Additional CSS"), added to the form, and, for a bundle, "Download
 * and import file attachments" checked, since the files are in the bundle
 * and nothing is downloaded. Data from PHP in window.eizportImportSteps;
 * runs before importer.js gathers the steps in cards.
 */
( function () {
	var config = window.eizportImportSteps || {};

	if ( config.updateExisting ) {
		( function ( field ) {
			var form = document.querySelector( 'form[action*="step=2"]' );
			var submit = form && form.querySelector( 'p.submit' );
			if ( ! submit ) {
				return;
			}
			var heading = document.createElement( 'h3' );
			heading.textContent = field.heading;
			var row = document.createElement( 'p' );
			var label = document.createElement( 'label' );
			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.name = field.name;
			box.value = '1';
			box.checked = !! field.checked;
			label.append( box, ' ' + field.label );
			row.append( label );
			form.insertBefore( heading, submit );
			form.insertBefore( row, submit );
		} )( config.updateExisting );
	}

	// How the bundle's CSS rules are added, and whether they're marked.
	if ( config.css ) {
		( function ( field ) {
			var form = document.querySelector( 'form[action*="step=2"]' );
			var submit = form && form.querySelector( 'p.submit' );
			if ( ! submit ) {
				return;
			}
			var heading = document.createElement( 'h3' );
			heading.textContent = field.heading;
			var modeRow = document.createElement( 'p' );
			var modeLabel = document.createElement( 'label' );
			var select = document.createElement( 'select' );
			select.name = field.modeName;
			field.modes.forEach( function ( mode ) {
				var option = document.createElement( 'option' );
				option.value = mode.value;
				option.textContent = mode.label;
				option.selected = mode.value === field.mode;
				select.append( option );
			} );
			modeLabel.append( field.modeLabel + ' ', select );
			modeRow.append( modeLabel );
			var markersRow = document.createElement( 'p' );
			var markersLabel = document.createElement( 'label' );
			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.name = field.markersName;
			box.value = '1';
			box.checked = !! field.markers;
			markersLabel.append( box, ' ' + field.markersLabel );
			markersRow.append( markersLabel );
			form.insertBefore( heading, submit );
			form.insertBefore( modeRow, submit );
			form.insertBefore( markersRow, submit );
		} )( config.css );
	}

	if ( config.importId ) {
		var importId = document.querySelector( 'input[name="import_id"]' );
		var fetch = document.getElementById( 'import-attachments' );
		if ( importId && fetch && config.importId === parseInt( importId.value, 10 ) ) {
			fetch.checked = true;
		}
	}
} )();
