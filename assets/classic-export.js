/**
 * The classic Tools → Export screen, when Eizport replaces it
 * (includes/export.php, selection.php, options.php): types as checkboxes
 * with filters or a selection each, the homepage choice, the bundle and
 * Playground settings, the notice after a Playground .zip is downloaded,
 * and the saved defaults. Data from PHP in window.eizportClassicExport.
 * Loaded in the footer, after the form.
 */
( function () {
	var config = window.eizportClassicExport || {};

	// Types: filters or a selection each (selection.php).
	( function () {
		var form = document.getElementById( 'export-filters' );
		var blocks = Array.prototype.slice.call( document.querySelectorAll( '.eizport-type' ) );
		if ( ! form ) {
			return;
		}
		var all = form.querySelector( 'input[name="content"][value="all"]' );
		var panels = {};

		// Core's type radios become checkboxes: one or more types, or "All content".
		form.querySelectorAll( 'input[name="content"]' ).forEach( function ( radio ) {
			if ( radio.value === 'all' ) {
				return;
			}
			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.name = config.typesField;
			box.value = radio.value;
			box.className = 'eizport-type-box';
			radio.replaceWith( box );
		} );
		[ [ 'posts', 'post-filters' ], [ 'pages', 'page-filters' ], [ 'attachment', 'attachment-filters' ] ].forEach( function ( pair ) {
			panels[ pair[ 0 ] ] = document.getElementById( pair[ 1 ] );
		} );

		blocks.forEach( function ( block ) {
			var mode = block.querySelector( '.eizport-mode' );
			var picker = block.querySelector( '.eizport-picker' );
			var target = block.dataset.target ? document.getElementById( block.dataset.target ) : null;
			var filters;

			if ( target ) {
				// Core's filters (posts, pages, media): the choice goes on top, the list at the end.
				filters = Array.prototype.slice.call( target.children );
				target.insertBefore( mode, target.firstChild );
				target.appendChild( picker );
				block.remove();
			} else {
				// Custom post types: our filters, under the type's checkbox.
				filters = Array.prototype.slice.call( block.querySelectorAll( '.eizport-own-filter' ) );
				var box = form.querySelector( '.eizport-type-box[value="' + block.dataset.content + '"]' );
				if ( box && box.closest( 'p' ) ) {
					box.closest( 'p' ).after( block );
				}
				panels[ block.dataset.content ] = block;
			}

			function show() {
				var select = mode.querySelector( 'input:checked' ).value === 'select';
				filters.forEach( function ( item ) {
					item.hidden = select;
				} );
				picker.hidden = ! select;
			}
			mode.addEventListener( 'change', show );
			show();

			var search = picker.querySelector( '.eizport-list-search' );
			if ( search ) {
				search.addEventListener( 'input', function () {
					var text = search.value.trim().toLowerCase();
					picker.querySelectorAll( '.eizport-checklist-items li' ).forEach( function ( item ) {
						item.hidden = text !== '' && item.textContent.toLowerCase().indexOf( text ) === -1;
					} );
				} );
			}
		} );

		if ( window.jQuery ) {
			window.jQuery( function ( $ ) {
				// Core's script takes every radio in the form for the content choice and folds the filters.
				$( '.eizport-mode input' ).off( 'change' );
				$( form ).on( 'change', '.eizport-type-box', function () {
					var panel = panels[ this.value ];
					if ( this.checked && all ) {
						all.checked = false;
					}
					if ( panel ) {
						$( panel )[ this.checked ? 'slideDown' : 'slideUp' ]( 'fast' );
					}
				} );
				if ( all ) {
					$( all ).on( 'change', function () {
						form.querySelectorAll( '.eizport-type-box' ).forEach( function ( box ) {
							box.checked = false;
						} );
					} );
				}
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			var boxes = Array.prototype.slice.call( form.querySelectorAll( '.eizport-type-box:checked' ) );
			var empty = boxes.filter( function ( box ) {
				var picker = form.querySelector( '.eizport-picker[data-content="' + box.value + '"]' );
				return picker && ! picker.hidden && ! picker.querySelector( 'input:checked' );
			} );
			if ( ! boxes.length && all && ! all.checked ) {
				event.preventDefault();
				window.alert( config.texts.chooseWhat );
			} else if ( empty.length ) {
				event.preventDefault();
				window.alert( config.texts.checkItems );
			}
		} );
	} )();

	// The homepage choice (options.php).
	( function () {
		// The homepage choice shows only when the homepage or the posts page can be in the export.
		var front = document.getElementById( 'eizport-front-pages' );
		var form = front && front.closest( 'form' );
		if ( ! form ) {
			return;
		}
		var pages = JSON.parse( front.dataset.pages );
		function update() {
			var content = form.querySelector( 'input[name="content"]:checked' );
			var mode = form.querySelector( 'input[name="eizport_mode[page]"]:checked' );
			var visible = ! content || content.value === 'all' || ( content.value === 'pages' && ( ! mode || mode.value === 'filters' || pages.some( function ( id ) {
				var box = form.querySelector( 'input[name="eizport_ids[page][]"][value="' + id + '"]' );
				return box && box.checked;
			} ) ) );
			front.hidden = ! visible;
			front.querySelector( 'input' ).disabled = ! visible;
		}
		form.addEventListener( 'change', update );
		update();
	} )();

	// Bundle and Playground settings, and the download notice (export.php).
	( function () {
		var bundle = document.getElementById( 'eizport-bundle' );
		var blueprint = document.getElementById( 'eizport-blueprint' );
		var extension = document.getElementById( 'eizport-extension' );
		var options = document.getElementById( 'eizport-bundle-options' );
		bundle.addEventListener( 'change', function () {
			options.disabled = ! bundle.checked;
		} );
		blueprint.addEventListener( 'change', function () {
			document.getElementById( 'eizport-blueprint-options' ).disabled = ! blueprint.checked;
			extension.textContent = blueprint.checked ? '.zip' : '.' + config.bundleExtension;
		} );

		// After a Playground .zip is downloaded, the response's cookie says its name and copy link.
		var notice = document.getElementById( 'eizport-download-notice' );
		var cookie = config.cookie;
		var path = config.cookiePath;
		var text = config.texts.download;
		var timer;

		function readCookie() {
			var match = document.cookie.match( new RegExp( '(?:^|; )' + cookie + '=([^;]*)' ) );
			if ( ! match ) {
				return null;
			}
			document.cookie = cookie + '=; Max-Age=0; path=' + path;
			try {
				return JSON.parse( decodeURIComponent( match[ 1 ] ) );
			} catch ( e ) {
				return null;
			}
		}

		function element( tag, content, attributes ) {
			var node = document.createElement( tag );
			node.textContent = content || '';
			Object.keys( attributes || {} ).forEach( function ( name ) {
				node.setAttribute( name, attributes[ name ] );
			} );
			return node;
		}

		function copyButton( value, label ) {
			var button = element( 'button', label, { type: 'button', 'class': 'button button-small' } );
			button.addEventListener( 'click', function () {
				navigator.clipboard.writeText( value ).then( function () {
					button.textContent = text.copied;
					setTimeout( function () {
						button.textContent = label;
					}, 2000 );
				} );
			} );
			return button;
		}

		function show( data ) {
			var line;
			notice.textContent = '';
			line = element( 'p', text.downloaded + ' ' );
			line.appendChild( element( 'code', data.file ) );
			notice.appendChild( line );

			line = element( 'p', text.run + ' ' );
			line.appendChild( element( 'code', data.command ) );
			line.appendChild( document.createTextNode( ' ' ) );
			line.appendChild( copyButton( data.command, text.copy ) );
			notice.appendChild( line );

			if ( data.playground ) {
				line = element( 'p', text.kept + ' ' );
				line.appendChild( element( 'a', text.open, { href: data.playground, target: '_blank', rel: 'noopener' } ) );
				line.appendChild( document.createTextNode( ' ' ) );
				line.appendChild( copyButton( data.playground, text.copyLink ) );
				notice.appendChild( line );
			}
			notice.hidden = false;
		}

		bundle.closest( 'form' ).addEventListener( 'submit', function ( event ) {
			var tries = 0;
			if ( event.defaultPrevented || ! bundle.checked || ! blueprint.checked ) {
				return;
			}
			readCookie();
			notice.hidden = true;
			clearInterval( timer );
			timer = setInterval( function () {
				var data = readCookie();
				if ( data || ++tries > 1200 ) {
					clearInterval( timer );
				}
				if ( data ) {
					show( data );
				}
			}, 500 );
		} );
	} )();

	// The saved defaults, if any (export.php).
	if ( config.defaults ) {
		// After the selection script's own jQuery ready handlers, which react to the changes.
		( window.jQuery || function ( run ) {
			document.addEventListener( 'DOMContentLoaded', run );
		} )( function () {
			var defaults = config.defaults;
			var contents = config.contents;
			var form = document.getElementById( 'export-filters' );
			if ( ! form ) {
				return;
			}
			var set = function ( input, value ) {
				if ( input && input.checked !== value ) {
					input.checked = value;
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			};
			var named = function ( name, value ) {
				return form.querySelector( '[name="' + name + '"]' + ( undefined === value ? '' : '[value="' + value + '"]' ) );
			};
			var settings = defaults.settings || {};

			Object.keys( defaults.types || {} ).forEach( function ( type ) {
				var spec = defaults.types[ type ];
				var box = form.querySelector( '.eizport-type-box[value="' + ( contents[ type ] || type ) + '"]' );
				set( box, true );
				if ( spec.ids ) {
					set( named( 'eizport_mode[' + type + ']', 'select' ), true );
					spec.ids.forEach( function ( id ) {
						set( named( 'eizport_ids[' + type + '][]', id ), true );
					} );
				} else if ( spec.filters && spec.filters.status ) {
					var status = named( 'eizport_filters[' + type + '][status]' ) || named( type + '_status' );
					if ( status ) {
						status.value = spec.filters.status;
					}
				}
			} );

			var bundle = 'playground' === settings.format || settings.media || ( settings.option_groups || [] ).length || ( settings.option_names || [] ).length;
			set( document.getElementById( 'eizport-bundle' ), !! bundle );
			if ( settings.filename ) {
				document.getElementById( 'eizport-filename' ).value = settings.filename;
			}
			set( named( config.fields.media ), !! settings.media );
			( settings.option_groups || [] ).forEach( function ( group ) {
				set( named( config.fields.groups, group ), true );
			} );
			if ( ( settings.option_names || [] ).length ) {
				document.getElementById( 'eizport-options-extra' ).value = settings.option_names.join( ', ' );
			}
			set( document.getElementById( 'eizport-blueprint' ), 'playground' === settings.format );
			form.querySelectorAll( 'input[name="' + config.fields.themes + '"]' ).forEach( function ( input ) {
				if ( 'playground' === settings.format ) {
					set( input, ( settings.themes || [] ).indexOf( input.value ) !== -1 );
				}
			} );
			( settings.plugins || [] ).forEach( function ( plugin ) {
				set( named( config.fields.plugins, plugin ), true );
			} );
			( settings.mu_plugins || [] ).forEach( function ( file ) {
				set( named( config.fields.mu, file ), true );
			} );
		} );
	}
} )();
