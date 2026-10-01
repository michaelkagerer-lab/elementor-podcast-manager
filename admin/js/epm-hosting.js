/**
 * Hosting & import screen.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-epm-hosting]' );
	var app = window.epmApp;
	// The import result (admin/js/epm-import-result.js).
	var importResult = window.epmImportResult;

	if ( ! root || ! app || ! importResult ) {
		return;
	}

	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var _n = i18n ? i18n._n : function ( single, plural, count ) {
		return count === 1 ? single : plural;
	};

	var announcer = root.querySelector( '[data-epm-announce]' );
	// check: bumped when the address changes, so a feed that is still
	// being read page by page stops updating the screen.
	var state = { preview: null, stepping: false, check: 0 };

	function $( selector, scope ) {
		return ( scope || root ).querySelector( selector );
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d+)\$s/g, function ( match, index ) {
			var value = args[ Number( index ) - 1 ];
			return value === undefined ? match : String( value );
		} );
	}

	function announce( message ) {
		if ( ! announcer ) {
			return;
		}
		announcer.textContent = '';
		window.setTimeout( function () {
			announcer.textContent = message;
		}, 50 );
	}

	function request( action, fields, nonce ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce || app.importNonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			var value = fields[ key ];
			body.append( key, value === true ? '1' : value === false ? '' : value );
		} );

		return window
			.fetch( app.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( app.strings.failed );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					var error = new Error( ( json && json.data && json.data.message ) || app.strings.failed );
					error.data = json && json.data;
					throw error;
				}
				return json.data;
			} );
	}

	function busy( button, on ) {
		if ( ! button ) {
			return;
		}
		button.disabled = !! on;
		if ( on ) {
			button.setAttribute( 'aria-busy', 'true' );
		} else {
			button.removeAttribute( 'aria-busy' );
			// Disabling the focused button dropped focus to the page: put it
			// back, unless it is hidden now or focus moved on meanwhile.
			if ( ( ! document.activeElement || document.activeElement === document.body ) && button.offsetParent !== null ) {
				button.focus();
			}
		}
	}

	/* ---------- hosting form ---------- */

	var form = $( '[data-hosting-form]' );
	if ( form ) {
		form.addEventListener( 'change', function ( event ) {
			if ( event.target.name === 'epm_hosting[mode]' ) {
				$( '[data-external-only]', form ).hidden = event.target.value !== 'external';
				$( '[data-self-only]', form ).hidden = event.target.value === 'external';
				// Another host needs its feed address.
				$( '#epm-hosting-feed', form ).required = event.target.value === 'external';
			}
			if ( event.target.name === 'epm_hosting[provider]' ) {
				var provider = app.providers[ event.target.value ] || app.providers.other;
				$( '[data-provider-help]', form ).textContent = provider.feedHelp;
				$( '#epm-hosting-feed', form ).placeholder = provider.example || 'https://';
			}
		} );
	}

	/* ---------- redirect instructions ---------- */

	var redirectSelect = $( '[data-redirect-select]' );
	if ( redirectSelect ) {
		redirectSelect.addEventListener( 'change', function () {
			var provider = app.providers[ redirectSelect.value ] || app.providers.other;
			$( '[data-redirect-help]' ).textContent = provider.redirect;
		} );
	}

	/* ---------- sync now ---------- */

	function syncNow( button ) {
		busy( button, true );
		announce( app.strings.syncing );
		request( 'epm_sync_now', {} )
			.then( function ( result ) {
				$( '[data-sync-message]' ).textContent = result.message;
				$( '[data-sync-last]' ).textContent = result.last_run;
				var badge = $( '[data-sync-status] .epm-badge' );
				if ( badge ) {
					badge.className = 'epm-badge epm-badge--ok';
					badge.textContent = __( 'Working', 'elementor-podcast-manager' );
				}
				announce( result.message );
			} )
			.catch( function ( error ) {
				var message = error.message;
				$( '[data-sync-message]' ).textContent = message;
				var badge = $( '[data-sync-status] .epm-badge' );
				if ( badge ) {
					badge.className = 'epm-badge epm-badge--error';
					badge.textContent = __( 'Problem', 'elementor-podcast-manager' );
				}
				announce( message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	/* ---------- import ---------- */

	var importForm = $( '[data-import-form]' );
	var jobBox = $( '[data-job]' );

	function showImportError( message ) {
		var el = $( '[data-error]', importForm );
		el.textContent = message || '';
		el.hidden = ! message;
		var input = importForm.querySelector( '[name="url"]' );
		if ( message ) {
			input.setAttribute( 'aria-invalid', 'true' );
		} else {
			input.removeAttribute( 'aria-invalid' );
		}
	}

	function showProgress( message ) {
		var el = $( '[data-preview-progress]', importForm );
		el.textContent = message || '';
		el.hidden = ! message;
	}

	/**
	 * A paged feed is read over several requests: keep asking for the
	 * next pages while the server says it is still reading.
	 */
	function readAll( result, run ) {
		if ( run !== state.check ) {
			return Promise.reject( null );
		}
		if ( ! result.catalog || ! result.catalog.loading ) {
			showProgress( '' );
			return Promise.resolve( result );
		}
		showProgress( result.catalog.message );
		announce( result.catalog.message );
		return request( 'epm_import_more', { token: result.token } ).then( function ( next ) {
			return readAll( next, run );
		} );
	}

	function check( button ) {
		var input = importForm.querySelector( '[name="url"]' );
		if ( ! input.value.trim() ) {
			showImportError( __( 'Paste your RSS feed address, Apple Podcasts link or show page.', 'elementor-podcast-manager' ) );
			input.focus();
			return;
		}

		showImportError( '' );
		$( '[data-preview]', importForm ).hidden = true;
		busy( button, true );
		announce( app.strings.checking );
		var run = ++state.check;

		request( 'epm_import_preview', { url: input.value.trim() } )
			.then( function ( result ) {
				return readAll( result, run );
			} )
			.then( function ( result ) {
				state.preview = result;
				input.value = result.feed_url;
				renderPreview( result );
			} )
			.catch( function ( error ) {
				if ( ! error || run !== state.check ) {
					return;
				}
				showProgress( '' );
				showImportError( error.message );
				announce( error.message );
				input.focus();
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	/**
	 * "Try reading the rest again": continue from the page that failed.
	 */
	function retry( button ) {
		if ( ! state.preview ) {
			return;
		}
		busy( button, true );
		announce( app.strings.checking );
		var run = ++state.check;
		var error = $( '[data-error-for="accept_partial"]', importForm );

		request( 'epm_import_more', { token: state.preview.token } )
			.then( function ( result ) {
				return readAll( result, run );
			} )
			.then( function ( result ) {
				state.preview = result;
				error.hidden = true;
				renderPreview( result );
				// The callout (and this button) may be gone: focus the result.
				var target = result.catalog && ! result.catalog.complete ? $( '[data-preview-incomplete]', importForm ) : $( '[data-preview-title]', importForm );
				target.setAttribute( 'tabindex', '-1' );
				target.focus();
			} )
			.catch( function ( failure ) {
				if ( ! failure || run !== state.check ) {
					return;
				}
				showProgress( '' );
				var text = $( '[data-preview-incomplete-text]', importForm );
				text.textContent = failure.message;
				announce( failure.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	/**
	 * The incomplete-feed callout and, when the audio is copied (a move),
	 * the confirmation that names what is missing.
	 */
	function renderCompleteness( result ) {
		var catalog = result.catalog || { complete: true };
		var incomplete = ! catalog.complete;
		var box = $( '[data-preview-incomplete]', importForm );

		box.hidden = ! incomplete;
		$( '[data-preview-incomplete-text]', importForm ).textContent = incomplete ? catalog.message : '';
		$( '[data-retry-wrap]', importForm ).hidden = ! ( incomplete && catalog.retry );

		var accept = importForm.querySelector( '[name="accept_partial"]' );
		accept.checked = false;
		$( '[data-accept-partial-label]', importForm ).textContent = incomplete
			? format(
					/* translators: %1$s: number of episodes found in the feed */
					_n( 'Move only the %1$s episode that was found. The missing episodes stay at the old host and will not be on this website.', 'Move only the %1$s episodes that were found. The missing episodes stay at the old host and will not be on this website.', result.episodes, 'elementor-podcast-manager' ),
					result.episodes
			  )
			: '';
		updateAccept();
	}

	// The confirmation matters for a move only (copying the audio).
	function updateAccept() {
		var incomplete = state.preview && state.preview.catalog && ! state.preview.catalog.complete;
		var move = importForm.querySelector( '[name="download_media"]' ).checked;
		$( '[data-accept-partial]', importForm ).hidden = ! ( incomplete && move );
		$( '[data-preview-incomplete-mirror]', importForm ).hidden = move;
		if ( ! move ) {
			fieldError( 'accept_partial', false );
		}
	}

	function fieldError( name, show ) {
		var field = importForm.querySelector( '[name="' + name + '"]' );
		var error = $( '[data-error-for="' + name + '"]', importForm );
		if ( error ) {
			error.hidden = ! show;
		}
		if ( show ) {
			field.setAttribute( 'aria-invalid', 'true' );
		} else {
			field.removeAttribute( 'aria-invalid' );
		}
		return field;
	}

	function renderPreview( result ) {
		var channel = result.channel || {};
		var art = $( '[data-preview-art]', importForm );

		if ( channel.image ) {
			art.onerror = function () {
				art.hidden = true;
			};
			art.src = channel.image;
			art.hidden = false;
		} else {
			art.hidden = true;
		}

		$( '[data-preview-title]', importForm ).textContent = channel.title || result.feed_url;
		$( '[data-preview-meta]', importForm ).textContent = [
			format(
				/* translators: %1$s: number of episodes */
				_n( '%1$s episode', '%1$s episodes', result.episodes, 'elementor-podcast-manager' ),
				result.episodes
			),
			result.provider_name,
			result.newest && result.oldest ? result.oldest + ' – ' + result.newest : '',
		]
			.filter( Boolean )
			.join( ' · ' );

		var notes = [];
		if ( result.existing > 0 ) {
			notes.push(
				format(
					/* translators: %1$s: number of episodes */
					_n( '%1$s of these episodes is already on this website and is updated, not duplicated.', '%1$s of these episodes are already on this website and are updated, not duplicated.', result.existing, 'elementor-podcast-manager' ),
					result.existing
				)
			);
		}
		if ( result.moved_to ) {
			notes.push(
				format(
					/* translators: %1$s: feed address */
					__( 'This feed says the show moved to %1$s.', 'elementor-podcast-manager' ),
					result.moved_to
				)
			);
		}
		if ( result.duplicates && result.duplicates.length ) {
			notes.push(
				format(
					/* translators: %1$s: number of episodes */
					_n( '%1$s episode shares its ID with another one and is skipped.', '%1$s episodes share their ID with another one and are skipped.', result.duplicates.length, 'elementor-podcast-manager' ),
					result.duplicates.length
				)
			);
		}
		var box = $( '[data-preview-notes]', importForm );
		box.hidden = ! notes.length;
		$( 'p', box ).textContent = notes.join( ' ' );

		$( '[data-confirm-owner]', importForm ).hidden = ! result.locked;
		renderCompleteness( result );
		$( '[data-preview]', importForm ).hidden = false;
		announce(
			format(
				/* translators: 1: podcast title, 2: number of episodes */
				_n( '%1$s: %2$s episode found.', '%1$s: %2$s episodes found.', result.episodes, 'elementor-podcast-manager' ),
				channel.title || result.feed_url,
				result.episodes
			)
		);
	}

	function start( button ) {
		if ( ! state.preview ) {
			return;
		}
		var values = {
			download_media: importForm.querySelector( '[name="download_media"]' ).checked,
			draft: importForm.querySelector( '[name="draft"]' ).checked,
			apply_channel: importForm.querySelector( '[name="apply_channel"]' ).checked,
			confirm_owner: importForm.querySelector( '[name="confirm_owner"]' ).checked,
			accept_partial: importForm.querySelector( '[name="accept_partial"]' ).checked,
		};
		var incomplete = state.preview.catalog && ! state.preview.catalog.complete;

		// A move of part of a show only after the informed confirmation;
		// the message sits under the checkbox, which gets focus.
		if ( values.download_media && incomplete && ! values.accept_partial ) {
			fieldError( 'accept_partial', true ).focus();
			return;
		}
		fieldError( 'accept_partial', false );

		// Copying the audio moves the show here: a site that mirrors its
		// host stops syncing and redirecting when the import finishes.
		if (
			values.download_media &&
			app.hosting &&
			app.hosting.mode === 'external' &&
			! window.confirm( __( 'Copying the audio moves your podcast to this website. When the import finishes, hosting switches to “This website”: episodes stop syncing from your host and this site publishes the feed. Continue?', 'elementor-podcast-manager' ) )
		) {
			return;
		}

		busy( button, true );
		request( 'epm_import_start', {
			token: state.preview.token,
			// Copying media means taking the show over; otherwise the
			// episodes are mirrored.
			purpose: values.download_media ? 'move' : 'mirror',
			status: values.draft ? 'draft' : 'publish',
			download_media: values.download_media,
			confirm_owner: values.confirm_owner || ! state.preview.locked,
			accept_partial: values.download_media && incomplete && values.accept_partial,
			apply_channel: values.apply_channel,
			overwrite_channel: false,
		} )
			.then( function ( job ) {
				$( '[data-preview]', importForm ).hidden = true;
				renderJob( job );
				// The Import button is gone with the preview: focus the progress.
				jobBox.setAttribute( 'tabindex', '-1' );
				jobBox.focus();
				loop();
			} )
			.catch( function ( error ) {
				if ( error.data && error.data.code === 'epm_import_incomplete' ) {
					fieldError( 'accept_partial', true ).focus();
					return;
				}
				showImportError( error.message );
				announce( error.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	function renderJob( job ) {
		if ( ! job || ! jobBox ) {
			return;
		}
		jobBox.hidden = false;

		var total = Math.max( 0, job.total || 0 );
		var done = Math.min( total, job.done || 0 );
		var ratio = total > 0 ? done / total : job.status === 'done' ? 1 : 0;

		$( '.epm-progress__bar', jobBox ).style.setProperty( '--epm-progress', String( ratio ) );
		$( '.epm-progress__track', jobBox ).setAttribute( 'aria-valuenow', String( Math.round( ratio * 100 ) ) );
		$( '[data-job-count]', jobBox ).textContent = format( app.strings.progress, done, total );

		var labels = {
			/* translators: import result label, e.g. "12 new" */
			created: __( 'new', 'elementor-podcast-manager' ),
			/* translators: import result label, e.g. "3 updated" */
			updated: __( 'updated', 'elementor-podcast-manager' ),
			/* translators: import result label, e.g. "40 unchanged" */
			unchanged: __( 'unchanged', 'elementor-podcast-manager' ),
			/* translators: import result label, e.g. "1 skipped" */
			skipped: __( 'skipped', 'elementor-podcast-manager' ),
			/* translators: import result label, e.g. "1 failed" */
			failed: __( 'failed', 'elementor-podcast-manager' ),
			/* translators: import result label, e.g. "2 audio not copied" */
			media_failed: __( 'audio not copied', 'elementor-podcast-manager' ),
		};
		var counts = job.counts || {};
		$( '[data-job-summary]', jobBox ).textContent = Object.keys( labels )
			.filter( function ( key ) {
				return counts[ key ] > 0;
			} )
			.map( function ( key ) {
				return counts[ key ] + ' ' + labels[ key ];
			} )
			.join( ' · ' );

		var log = $( '[data-job-log]', jobBox );
		log.textContent = '';
		( job.log || [] ).forEach( function ( entry ) {
			var li = document.createElement( 'li' );
			var badge = document.createElement( 'span' );
			badge.className = 'epm-badge' + ( entry.action === 'failed' ? ' epm-badge--error' : entry.action === 'created' ? ' epm-badge--ok' : '' );
			badge.textContent = labels[ entry.action ] || entry.action;
			var title = document.createElement( entry.edit ? 'a' : 'span' );
			title.className = 'epm-log__title';
			title.textContent = entry.title;
			if ( entry.edit ) {
				title.href = entry.edit;
			}
			li.appendChild( badge );
			li.appendChild( title );
			if ( entry.message ) {
				var note = document.createElement( 'span' );
				note.className = 'epm-log__note';
				note.textContent = entry.message;
				li.appendChild( note );
			}
			log.appendChild( li );
		} );

		var error = $( '[data-job-error]', jobBox );
		error.hidden = ! job.error;
		if ( job.error ) {
			$( 'p', error ).textContent = job.error;
		}

		// Imported from part of the feed: say so, also after the import.
		var partial = $( '[data-job-incomplete]', jobBox );
		var incomplete = job.catalog && ! job.catalog.complete && job.catalog.message;
		partial.hidden = ! incomplete;
		if ( incomplete ) {
			$( 'p', partial ).textContent = format(
				/* translators: %1$s: why the feed could not be read completely */
				__( 'This import covers only part of the feed. %1$s', 'elementor-podcast-manager' ),
				job.catalog.message
			);
		}

		var active = importResult.active( job );
		$( '[data-action="cancel"]', jobBox ).hidden = ! active;
		$( '[data-job-episodes]', jobBox ).hidden = active;

		// Waiting, the file in progress, what is still at the old host.
		var media = importResult.render( jobBox, job );

		if ( job.status === 'done' || job.status === 'done_with_problems' || job.status === 'waiting' ) {
			announce( format( app.strings.progress, done, total ) + ( media ? ' ' + media : '' ) + ( incomplete ? ' ' + $( 'p', partial ).textContent : '' ) );
		}
	}

	/* ---------- an unfinished move ---------- */

	function retryCopies( button ) {
		busy( button, true );
		request( 'epm_import_retry', {} )
			.then( function ( job ) {
				renderJob( job );
				jobBox.setAttribute( 'tabindex', '-1' );
				jobBox.focus();
				loop();
			} )
			.catch( function ( error ) {
				announce( error.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	function confirmMove( button ) {
		var box = $( '[data-confirm-move]', jobBox );
		var field = $( '[name="confirm_remaining"]', box );
		var error = $( '[data-error-for="confirm_remaining"]', box );
		if ( ! field.checked ) {
			error.hidden = false;
			field.setAttribute( 'aria-invalid', 'true' );
			field.focus();
			return;
		}
		error.hidden = true;
		field.removeAttribute( 'aria-invalid' );
		busy( button, true );
		request( 'epm_import_confirm', { confirm_remaining: 1 } )
			.then( function ( job ) {
				renderJob( job );
				jobBox.setAttribute( 'tabindex', '-1' );
				jobBox.focus();
			} )
			.catch( function ( failure ) {
				announce( failure.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	function loop() {
		if ( state.stepping ) {
			return;
		}
		state.stepping = true;

		( function next() {
			request( 'epm_import_step', {} )
				.then( function ( job ) {
					renderJob( job );
					if ( importResult.active( job ) ) {
						window.setTimeout( next, importResult.delay( job ) );
						return;
					}
					state.stepping = false;
				} )
				.catch( function ( error ) {
					state.stepping = false;
					var box = $( '[data-job-error]', jobBox );
					box.hidden = false;
					$( 'p', box ).textContent = error.message + ' ' + app.strings.leaveImport;
				} );
		} )();
	}

	/* ---------- copy ---------- */

	function copy( button ) {
		var value = button.getAttribute( 'data-copy' );
		var label = button.textContent;
		var done = function () {
			button.textContent = app.strings.copied;
			announce( app.strings.copied );
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		};
		if ( window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( value ).then( done, function () {} );
			return;
		}
		var area = document.createElement( 'textarea' );
		area.value = value;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {
			// The address stays selectable on screen.
		}
		document.body.removeChild( area );
	}

	/* ---------- wiring ---------- */

	if ( importForm ) {
		importForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			check( $( '[data-action="check"]', importForm ) );
		} );
		importForm.querySelector( '[name="url"]' ).addEventListener( 'input', function () {
			state.preview = null;
			state.check++;
			showProgress( '' );
			$( '[data-preview]', importForm ).hidden = true;
		} );
		importForm.querySelector( '[name="download_media"]' ).addEventListener( 'change', updateAccept );
		importForm.querySelector( '[name="accept_partial"]' ).addEventListener( 'change', function ( event ) {
			if ( event.target.checked ) {
				fieldError( 'accept_partial', false );
			}
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( '[data-action], [data-copy]' );
		if ( ! target ) {
			return;
		}
		if ( target.hasAttribute( 'data-copy' ) ) {
			copy( target );
			return;
		}
		switch ( target.getAttribute( 'data-action' ) ) {
			case 'sync-now':
				syncNow( target );
				break;
			case 'start':
				start( target );
				break;
			case 'retry-feed':
				retry( target );
				break;
			case 'retry-copies':
				retryCopies( target );
				break;
			case 'confirm-move':
				confirmMove( target );
				break;
			case 'cancel':
				busy( target, true );
				request( 'epm_import_cancel', {} )
					.then( function ( job ) {
						renderJob( job );
						// The stop button is gone: keep focus in the import.
						jobBox.setAttribute( 'tabindex', '-1' );
						jobBox.focus();
					} )
					.catch( function ( error ) {
						announce( error.message );
					} )
					.then( function () {
						busy( target, false );
					} );
				break;
		}
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.stepping ) {
			event.preventDefault();
			event.returnValue = app.strings.leaveImport;
		}
	} );

	// A running (or waiting) import, started here, in the setup assistant
	// or by cron, is picked up again; an unfinished move is shown.
	if ( app.job && importResult.active( app.job ) ) {
		renderJob( app.job );
		loop();
	} else if ( app.job && app.job.status === 'done_with_problems' ) {
		renderJob( app.job );
	}
} )();
