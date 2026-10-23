/**
 * Tools → Import → WordPress looking like the plugin's Export screen:
 * builds a header (icon, title, subtitle, the step's main button) and puts
 * each step in cards (importer.css), and gives the upload step a file
 * button like Settings → General's Site Icon. Without this script the
 * importer works as it is.
 *
 * Texts come from window.eizportImporter (includes/import.php).
 */
( function () {
	var texts = window.eizportImporter || {};
	var wrap = document.querySelector( '#wpbody-content > .wrap' );
	var title = wrap && wrap.querySelector( ':scope > h2' );
	if ( ! wrap || ! title ) {
		// Nothing to build: show the screen as it is (importer.css hides it until then).
		if ( wrap ) {
			wrap.classList.add( 'eizport-import--ready' );
		}
		return;
	}

	function element( tag, className ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		return node;
	}

	// The header.
	var header = element( 'div', 'eizport-import__header' );
	var heading = element( 'div', 'eizport-import__heading' );
	var line = element( 'div', 'eizport-import__title' );
	var actions = element( 'div', 'eizport-import__actions' );
	// The brand's mark (site/brand/mark.svg).
	var icon = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
	icon.setAttribute( 'viewBox', '0 0 64 64' );
	icon.setAttribute( 'width', '24' );
	icon.setAttribute( 'height', '24' );
	icon.setAttribute( 'aria-hidden', 'true' );
	icon.innerHTML =
		'<rect width="64" height="64" rx="14" fill="#3858e9"/>' +
		'<g fill="none" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">' +
		'<path stroke="#ffffff" d="M20 14 14 20l6 6M14 20h32L18 44"/>' +
		'<path stroke="#f2994a" d="M18 44h32m-6-6 6 6-6 6"/></g>';
	wrap.insertBefore( header, title );
	line.append( icon, title );
	heading.append( line );
	header.append( heading, actions );

	// The upload step's first paragraph ("Howdy! Upload your…") is the subtitle.
	var narrow = wrap.querySelector( ':scope > .narrow' );
	var intro = narrow && narrow.querySelector( ':scope > p' );
	var subtitle = element( 'p', 'eizport-import__subtitle' );
	if ( intro && narrow.querySelector( '#import-upload-form' ) ) {
		subtitle.textContent = intro.textContent;
		intro.remove();
	} else if ( texts.subtitle ) {
		subtitle.textContent = texts.subtitle;
	}
	if ( subtitle.textContent ) {
		heading.append( subtitle );
	}

	// The importer prints its messages as loose text between <br>s (step 2):
	// each one becomes an item of a list, shown in a card of their own.
	var messages = [];
	var current = null;
	var block = /^(DIV|P|FORM|H1|H2|H3|OL|UL|TABLE|SCRIPT|STYLE|NOSCRIPT)$/;
	Array.prototype.slice.call( wrap.childNodes ).forEach( function ( node ) {
		if ( node === header || ( node.nodeType === 1 && ( block.test( node.tagName ) || node.matches( '.notice, .error, .updated' ) ) ) ) {
			current = null;
			return;
		}
		if ( node.nodeType === 1 && node.tagName === 'BR' ) {
			current = null;
			node.remove();
			return;
		}
		if ( node.nodeType === 3 && ! node.textContent.trim() ) {
			node.remove();
			return;
		}
		if ( ! current ) {
			current = element( 'li' );
			messages.push( current );
		}
		current.append( node );
	} );

	// The body: everything after the header, the notices aside.
	var body = element( 'div', 'eizport-import__body' );
	Array.prototype.slice.call( wrap.children ).forEach( function ( child ) {
		if ( child !== header && ! child.matches( '.notice, .error, .updated' ) ) {
			body.append( child );
		}
	} );
	wrap.append( body );

	/**
	 * Puts a container's children in cards, a new one at each h3.
	 *
	 * @param {Element} container Where the children are.
	 */
	function cards( container ) {
		var card = null;
		Array.prototype.slice.call( container.children ).forEach( function ( child ) {
			if ( child.matches( 'input[type="hidden"], .eizport-import__moved, script, style' ) ) {
				return;
			}
			if ( ! card || child.tagName === 'H3' ) {
				card = element( 'div', 'eizport-import__card' );
				container.insertBefore( card, child );
			}
			card.append( child );
		} );
	}

	// Each form's submit button goes to the header (the original stays for no-script, hidden).
	wrap.querySelectorAll( 'form' ).forEach( function ( form, index ) {
		var submit = form.querySelector( 'p.submit input[type="submit"], p.submit button[type="submit"], input[type="submit"]' );
		if ( ! submit ) {
			return;
		}
		if ( ! form.id ) {
			form.id = 'eizport-import-form-' + index;
		}
		var button = element( 'button', 'button button-primary' );
		button.type = 'submit';
		button.setAttribute( 'form', form.id );
		button.textContent = submit.value || submit.textContent;
		// The upload step's button waits for a file.
		var file = form.querySelector( 'input[type="file"]' );
		if ( file ) {
			button.disabled = ! file.files || ! file.files.length;
			file.addEventListener( 'change', function () {
				button.disabled = ! file.files || ! file.files.length;
			} );
		}
		actions.append( button );
		( submit.closest( 'p' ) || submit ).classList.add( 'eizport-import__moved' );
	} );

	// Step 1 without its form: the file was refused. Say so, and offer another try.
	if ( texts.failed && ! wrap.querySelector( 'form' ) ) {
		subtitle.textContent = texts.failed.subtitle;
		if ( ! subtitle.parentNode ) {
			heading.append( subtitle );
		}
		var retry = element( 'a', 'button button-primary' );
		retry.href = texts.failed.url;
		retry.textContent = texts.failed.label;
		actions.append( retry );
	}

	// "All importers", when Tools → Import opens this one directly.
	if ( texts.all ) {
		var all = element( 'a', 'eizport-import__all' );
		all.href = texts.all.url;
		all.textContent = texts.all.label;
		actions.prepend( all );
	}

	Array.prototype.slice.call( body.children ).forEach( function ( child ) {
		if ( child.matches( '.narrow, form' ) ) {
			cards( child );
		}
	} );
	// Loose paragraphs (the last step) share one card.
	var loose = Array.prototype.slice.call( body.children ).filter( function ( child ) {
		return ! child.matches( '.narrow, form' );
	} );
	if ( loose.length ) {
		var card = element( 'div', 'eizport-import__card' );
		body.insertBefore( card, loose[ 0 ] );
		loose.forEach( function ( child ) {
			card.append( child );
		} );
	}

	// The importer's messages under the result, open (they can be folded).
	if ( messages.length ) {
		var log = element( 'details', 'eizport-import__card eizport-import__log' );
		log.open = true;
		var summary = element( 'summary' );
		var list = element( 'ul' );
		summary.textContent = ( texts.messages || 'Messages' ) + ' (' + messages.length + ')';
		messages.forEach( function ( item ) {
			list.append( item );
		} );
		log.append( summary, list );
		body.append( log );
	}

	// Built: importer.css shows the screen.
	wrap.classList.add( 'eizport-import--ready' );

	// The upload step's file field as a WordPress button (like the Site Icon's).
	var input = document.querySelector( '#import-upload-form input[type="file"]' );
	if ( ! input ) {
		return;
	}
	var fileWrap = element( 'span', 'eizport-file' );
	var fileButton = element( 'button', 'button button-hero' );
	var name = element( 'span', 'eizport-file__name' );
	fileButton.type = 'button';
	fileButton.textContent = texts.choose;
	name.setAttribute( 'aria-live', 'polite' );
	name.hidden = true;
	fileWrap.append( fileButton, name );
	input.hidden = true;
	input.after( fileWrap );
	fileButton.addEventListener( 'click', function () {
		input.click();
	} );

	function size( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var unit = 0;
		while ( bytes >= 1024 && unit < units.length - 1 ) {
			bytes /= 1024;
			unit++;
		}
		return new Intl.NumberFormat( document.documentElement.lang || undefined, { maximumFractionDigits: unit ? 1 : 0 } ).format( bytes ) + ' ' + units[ unit ];
	}

	input.addEventListener( 'change', function () {
		var file = input.files && input.files[ 0 ];
		// Like the Site Icon: a big button to choose, a regular one to change.
		fileButton.textContent = file ? texts.change : texts.choose;
		fileButton.className = file ? 'button' : 'button button-hero';
		name.hidden = ! file;
		name.textContent = '';
		if ( file ) {
			var details = element( 'span' );
			details.textContent = ' · ' + size( file.size );
			name.append( document.createTextNode( file.name ), details );
		}
	} );
} )();
