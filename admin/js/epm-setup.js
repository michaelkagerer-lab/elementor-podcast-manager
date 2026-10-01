/**
 * Setup assistant (Podcast → Setup assistant).
 *
 * One panel per step; every step saves through admin-ajax.php so a reload
 * or a closed tab never loses input. The import runs as a server-side job
 * that this screen steps through (and WP-Cron continues without it).
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-epm-setup]' );
	var app = window.epmApp;
	// The import result (admin/js/epm-import-result.js).
	var importResult = window.epmImportResult;

	if ( ! root || ! app || ! importResult ) {
		return;
	}

	var FLOWS = {
		new: [ 'path', 'show', 'look', 'done' ],
		move: [ 'path', 'connect', 'import', 'show', 'look', 'done' ],
		external: [ 'path', 'connect', 'import', 'show', 'look', 'done' ],
		'': [ 'path' ],
	};

	var state = {
		path: root.getAttribute( 'data-path' ) || '',
		step: 'path',
		preview: null,
		stepping: false,
		pageUrl: '',
		// Bumped when the address changes: a feed still being read page
		// by page stops updating the screen.
		check: 0,
	};

	var announcer = root.querySelector( '[data-epm-announce]' );

	// Translations: wp.i18n (strings are extracted by `wp i18n make-pot`).
	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var _n = i18n ? i18n._n : function ( single, plural, count ) {
		return count === 1 ? single : plural;
	};

	/* ---------- helpers ---------- */

	function $( selector, scope ) {
		return ( scope || root ).querySelector( selector );
	}

	function $$( selector, scope ) {
		return Array.prototype.slice.call( ( scope || root ).querySelectorAll( selector ) );
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

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d+)\$s/g, function ( match, index ) {
			var value = args[ Number( index ) - 1 ];
			return value === undefined ? match : String( value );
		} );
	}

	function request( action, fields, nonce ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce || app.nonce );

		Object.keys( fields || {} ).forEach( function ( key ) {
			var value = fields[ key ];
			if ( value && typeof value === 'object' && ! Array.isArray( value ) ) {
				Object.keys( value ).forEach( function ( sub ) {
					body.append( key + '[' + sub + ']', value[ sub ] === true ? '1' : value[ sub ] === false ? '' : value[ sub ] );
				} );
			} else {
				body.append( key, value === true ? '1' : value === false ? '' : value );
			}
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
					error.code = ( json && json.data && json.data.code ) || '';
					throw error;
				}
				return json.data;
			} );
	}

	function busy( button, on ) {
		if ( ! button ) {
			return;
		}
		if ( on ) {
			button.setAttribute( 'aria-busy', 'true' );
			button.disabled = true;
		} else {
			button.removeAttribute( 'aria-busy' );
			button.disabled = false;
			// Disabling the focused button dropped focus to the page: put it
			// back (never taken from a step that received focus meanwhile).
			if ( ! document.activeElement || document.activeElement === document.body ) {
				button.focus();
			}
		}
	}

	function showError( scope, message ) {
		var el = $( '[data-error]', scope );
		if ( ! el ) {
			return;
		}
		el.textContent = message || '';
		el.hidden = ! message;
		if ( message ) {
			announce( message );
		}
	}

	function formValues( form ) {
		var values = {};
		$$( 'input, select, textarea', form ).forEach( function ( field ) {
			if ( ! field.name || field.disabled ) {
				return;
			}
			if ( field.type === 'radio' ) {
				if ( field.checked ) {
					values[ field.name ] = field.value;
				}
				return;
			}
			if ( field.type === 'checkbox' ) {
				values[ field.name ] = field.checked;
				return;
			}
			values[ field.name ] = field.value;
		} );
		return values;
	}

	/* ---------- steps ---------- */

	function flow() {
		return FLOWS[ state.path ] || FLOWS[ '' ];
	}

	function applyPath() {
		root.setAttribute( 'data-path', state.path );

		$$( '[data-path-only]' ).forEach( function ( el ) {
			var paths = el.getAttribute( 'data-path-only' ).split( ' ' );
			el.hidden = paths.indexOf( state.path ) === -1;
		} );

		var steps = flow();
		$$( '[data-epm-steps] [data-step]' ).forEach( function ( item ) {
			var key = item.getAttribute( 'data-step' );
			// Before a path is chosen, show the full journey of the longest flow.
			item.hidden = state.path ? steps.indexOf( key ) === -1 : FLOWS.move.indexOf( key ) === -1 && key !== 'path';
		} );
	}

	function go( step, options ) {
		var steps = flow();
		if ( steps.indexOf( step ) === -1 ) {
			step = steps[ 0 ];
		}

		var previous = state.step;
		state.step = step;

		$$( '[data-panel]' ).forEach( function ( panel ) {
			var active = panel.getAttribute( 'data-panel' ) === step;
			panel.hidden = ! active;
			if ( active && previous !== step ) {
				panel.classList.remove( 'is-entering' );
				// Restart the entrance animation.
				void panel.offsetWidth;
				panel.classList.add( 'is-entering' );
			}
		} );

		var index = steps.indexOf( step );
		$$( '[data-epm-steps] [data-step]' ).forEach( function ( item ) {
			var key = item.getAttribute( 'data-step' );
			var position = steps.indexOf( key );
			item.classList.toggle( 'is-done', position > -1 && position < index );
			if ( key === step ) {
				item.setAttribute( 'aria-current', 'step' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );

		try {
			var url = new window.URL( window.location.href );
			url.searchParams.set( 'step', step );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {
			// Older browsers: the step is simply not kept on reload.
		}

		var panel = $( '[data-panel="' + step + '"]' );
		if ( panel && ! ( options && options.noFocus ) ) {
			panel.focus( { preventScroll: true } );
			window.scrollTo( { top: 0 } );
			var title = $( '.epm-panel__title', panel );
			announce(
				format(
					/* translators: 1: step number, 2: number of steps, 3: step title */
					__( 'Step %1$s of %2$s: %3$s', 'elementor-podcast-manager' ),
					index + 1,
					steps.length,
					// innerText skips the hidden titles of the other paths.
					title ? title.innerText.trim() : step
				)
			);
		}

		if ( step === 'done' ) {
			finish();
		}
	}

	function next() {
		var steps = flow();
		var index = steps.indexOf( state.step );
		go( steps[ Math.min( index + 1, steps.length - 1 ) ] );
	}

	function back() {
		var steps = flow();
		var index = steps.indexOf( state.step );
		var target = steps[ Math.max( index - 1, 0 ) ];
		// Never step back into a finished import.
		if ( target === 'import' ) {
			target = 'connect';
		}
		go( target );
	}

	/* ---------- step 1: path ---------- */

	function savePath( form ) {
		var values = formValues( form );
		var button = $( '[type="submit"]', form );

		if ( ! values.path ) {
			showError( form, __( 'Choose where your podcast lives to continue.', 'elementor-podcast-manager' ) );
			form.querySelector( '[name="path"]' ).focus();
			return;
		}

		showError( form, '' );
		busy( button, true );

		request( 'epm_setup_save', { step: 'path', data: { path: values.path } } )
			.then( function () {
				state.path = values.path;
				applyPath();
				next();
			} )
			.catch( function ( error ) {
				showError( form, error.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	/* ---------- step 2: connect ---------- */

	var connectForm = $( '[data-step-form="connect"]' );
	var importButton = $( '[data-import-button]' );

	function selectedProvider() {
		var values = formValues( connectForm );
		if ( values.provider === 'more' ) {
			return values.provider_more || 'other';
		}
		return values.provider || '';
	}

	function updateProviderHelp() {
		var provider = app.providers[ selectedProvider() ] || app.providers.other;
		var more = $( '[data-more-hosts]', connectForm );
		var help = $( '[data-feed-help]', connectForm );
		var input = connectForm.querySelector( '[name="feed_url"]' );

		if ( more ) {
			more.hidden = formValues( connectForm ).provider !== 'more';
		}
		if ( help && provider ) {
			help.textContent = provider.feedHelp;
		}
		if ( input && provider && provider.example ) {
			input.placeholder = provider.example;
		}
	}

	function resetPreview() {
		state.preview = null;
		state.check++;
		showProgress( '' );
		var box = $( '[data-preview]', connectForm );
		if ( box ) {
			box.hidden = true;
		}
		fieldError( connectForm, 'confirm_owner', false );
		fieldError( connectForm, 'accept_partial', false );
		importButton.disabled = true;
	}

	function showProgress( message ) {
		var el = $( '[data-preview-progress]', connectForm );
		if ( el ) {
			el.textContent = message || '';
			el.hidden = ! message;
		}
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
		return request( 'epm_import_more', { token: result.token }, app.importNonce ).then( function ( next ) {
			return readAll( next, run );
		} );
	}

	/**
	 * "Try reading the rest again": continue from the page that failed.
	 */
	function retryFeed( button ) {
		if ( ! state.preview ) {
			return;
		}
		busy( button, true );
		announce( app.strings.checking );
		var run = ++state.check;

		request( 'epm_import_more', { token: state.preview.token }, app.importNonce )
			.then( function ( result ) {
				return readAll( result, run );
			} )
			.then( function ( result ) {
				state.preview = result;
				fieldError( connectForm, 'accept_partial', false );
				renderPreview( result );
				// The callout (and this button) may be gone: focus the result.
				var target = result.catalog && ! result.catalog.complete ? $( '[data-preview-incomplete]', connectForm ) : $( '[data-preview-title]', connectForm );
				target.setAttribute( 'tabindex', '-1' );
				target.focus();
			} )
			.catch( function ( error ) {
				if ( ! error || run !== state.check ) {
					return;
				}
				showProgress( '' );
				$( '[data-preview-incomplete-text]', connectForm ).textContent = error.message;
				announce( error.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	/**
	 * The incomplete-feed callout and, for a move, the confirmation that
	 * names what is missing.
	 */
	function renderCompleteness( result ) {
		var catalog = result.catalog || { complete: true };
		var incomplete = ! catalog.complete;

		$( '[data-preview-incomplete]', connectForm ).hidden = ! incomplete;
		$( '[data-preview-incomplete-text]', connectForm ).textContent = incomplete ? catalog.message : '';
		$( '[data-retry-wrap]', connectForm ).hidden = ! ( incomplete && catalog.retry );

		var accept = connectForm.querySelector( '[name="accept_partial"]' );
		accept.checked = false;
		$( '[data-accept-partial]', connectForm ).hidden = ! ( incomplete && state.path === 'move' );
		$( '[data-accept-partial-label]', connectForm ).textContent = incomplete
			? format(
					/* translators: %1$s: number of episodes found in the feed */
					_n( 'Move only the %1$s episode that was found. The missing episodes stay at the old host and will not be on this website.', 'Move only the %1$s episodes that were found. The missing episodes stay at the old host and will not be on this website.', result.episodes, 'elementor-podcast-manager' ),
					result.episodes
			  )
			: '';
	}

	function callout( selector, text ) {
		var el = $( selector, connectForm );
		if ( ! el ) {
			return;
		}
		el.hidden = ! text;
		var p = $( 'p', el );
		if ( p && text ) {
			p.textContent = text;
		}
	}

	function checkFeed( trigger ) {
		var input = connectForm.querySelector( '[name="feed_url"]' );
		var url = input.value.trim();

		if ( ! url ) {
			input.setAttribute( 'aria-invalid', 'true' );
			showError( connectForm, __( 'Paste your RSS feed address, Apple Podcasts link or show page.', 'elementor-podcast-manager' ) );
			input.focus();
			return Promise.resolve( null );
		}

		input.removeAttribute( 'aria-invalid' );
		showError( connectForm, '' );
		resetPreview();
		busy( trigger, true );
		announce( app.strings.checking );
		var run = state.check;

		return request( 'epm_import_preview', { url: url }, app.importNonce )
			.then( function ( result ) {
				return readAll( result, run );
			} )
			.then( function ( result ) {
				state.preview = result;
				renderPreview( result );
				return result;
			} )
			.catch( function ( error ) {
				if ( ! error || run !== state.check ) {
					return null;
				}
				showProgress( '' );
				input.setAttribute( 'aria-invalid', 'true' );
				showError( connectForm, error.message );
				input.focus();
				return null;
			} )
			.then( function ( result ) {
				busy( trigger, false );
				return result;
			} );
	}

	function renderPreview( result ) {
		var channel = result.channel || {};
		var box = $( '[data-preview]', connectForm );
		var art = $( '[data-preview-art]', connectForm );
		var input = connectForm.querySelector( '[name="feed_url"]' );

		input.value = result.feed_url;

		if ( channel.image ) {
			art.onerror = function () {
				art.hidden = true;
			};
			art.src = channel.image;
			art.hidden = false;
		} else {
			art.removeAttribute( 'src' );
			art.hidden = true;
		}

		$( '[data-preview-title]', connectForm ).textContent = channel.title || result.feed_url;
		$( '[data-preview-meta]', connectForm ).textContent = [ channel.author, result.provider_name ].filter( Boolean ).join( ' · ' );
		$( '[data-preview-episodes]', connectForm ).textContent = String( result.episodes );
		$( '[data-preview-newest]', connectForm ).textContent = result.newest || '—';
		$( '[data-preview-oldest]', connectForm ).textContent = result.oldest || '—';

		// Pick the detected host so its instructions show up later.
		if ( result.provider ) {
			var radio = connectForm.querySelector( '[name="provider"][value="' + result.provider + '"]' );
			if ( radio ) {
				radio.checked = true;
			} else {
				var more = connectForm.querySelector( '[name="provider"][value="more"]' );
				var select = connectForm.querySelector( '[name="provider_more"]' );
				if ( more && select && select.querySelector( 'option[value="' + result.provider + '"]' ) ) {
					more.checked = true;
					select.value = result.provider;
				}
			}
			updateProviderHelp();
		}

		var locked = !! result.locked && state.path === 'move';
		$( '[data-preview-locked]', connectForm ).hidden = ! locked;
		$( '[data-confirm-owner]', connectForm ).hidden = ! locked;

		callout(
			'[data-preview-existing]',
			result.existing > 0
				? format(
						/* translators: %1$s: number of episodes */
						_n( '%1$s of these episodes is already on this website. It is updated, never duplicated.', '%1$s of these episodes are already on this website. They are updated, never duplicated.', result.existing, 'elementor-podcast-manager' ),
						result.existing
				  )
				: ''
		);
		callout(
			'[data-preview-moved]',
			result.moved_to
				? format(
						/* translators: %1$s: feed address */
						__( 'This feed says the show moved to %1$s. Check that address to import the current version.', 'elementor-podcast-manager' ),
						result.moved_to
				  )
				: ''
		);

		var notes = [];
		if ( result.duplicates && result.duplicates.length ) {
			notes.push(
				format(
					/* translators: %1$s: number of episodes */
					_n( '%1$s episode shares its ID with another episode in the feed and is skipped, as Apple Podcasts does.', '%1$s episodes share their ID with another episode in the feed and are skipped, as Apple Podcasts does.', result.duplicates.length, 'elementor-podcast-manager' ),
					result.duplicates.length
				)
			);
		}
		if ( result.blocked > 0 ) {
			notes.push(
				format(
					/* translators: %1$s: number of episodes */
					_n( '%1$s episode is hidden from Apple Podcasts at your host; it is imported as a draft.', '%1$s episodes are hidden from Apple Podcasts at your host; they are imported as drafts.', result.blocked, 'elementor-podcast-manager' ),
					result.blocked
				)
			);
		}
		if ( result.tracked > 0 ) {
			notes.push( __( 'The audio links include download statistics services (for example Podtrac or OP3). They keep working after the import.', 'elementor-podcast-manager' ) );
		}
		callout( '[data-preview-notes]', notes.join( ' ' ) );
		renderCompleteness( result );

		importButton.textContent = format(
			/* translators: %1$s: number of episodes */
			_n( 'Import %1$s episode', 'Import %1$s episodes', result.episodes, 'elementor-podcast-manager' ),
			result.episodes
		);
		importButton.disabled = result.episodes < 1;
		box.hidden = false;

		announce(
			format(
				/* translators: 1: podcast title, 2: number of episodes */
				_n( '%1$s: %2$s episode found.', '%1$s: %2$s episodes found.', result.episodes, 'elementor-podcast-manager' ),
				channel.title || result.feed_url,
				result.episodes
			)
		);
	}

	function startImport( form ) {
		var values = formValues( form );

		if ( ! state.preview ) {
			checkFeed( $( '[data-action="check-feed"]', form ) );
			return;
		}

		if ( state.path === 'move' && state.preview.locked && ! values.confirm_owner ) {
			// The message sits under the consent box, which gets focus.
			fieldError( form, 'confirm_owner', true ).focus();
			return;
		}
		fieldError( form, 'confirm_owner', false );

		// Moving part of a show only after the informed confirmation.
		var incomplete = state.preview.catalog && ! state.preview.catalog.complete;
		if ( state.path === 'move' && incomplete && ! values.accept_partial ) {
			fieldError( form, 'accept_partial', true ).focus();
			return;
		}
		fieldError( form, 'accept_partial', false );
		showError( form, '' );
		busy( importButton, true );

		var hosting = {
			provider: selectedProvider() || state.preview.provider || 'other',
			feed_url: state.preview.feed_url,
		};
		if ( state.path === 'external' ) {
			hosting.new_status = values.new_status || 'publish';
			hosting.sync = true;
			hosting.redirect = true;
		}

		request( 'epm_setup_save', { step: 'hosting', data: hosting } )
			.then( function () {
				return request(
					'epm_import_start',
					{
						token: state.preview.token,
						purpose: state.path === 'move' ? 'move' : 'mirror',
						status: state.path === 'external' ? values.new_status || 'publish' : 'publish',
						download_media: state.path === 'move' && !! values.download_media,
						confirm_owner: !! values.confirm_owner,
						accept_partial: state.path === 'move' && incomplete && !! values.accept_partial,
						apply_channel: true,
						overwrite_channel: false,
					},
					app.importNonce
				);
			} )
			.then( function ( job ) {
				go( 'import' );
				renderJob( job );
				stepImport();
			} )
			.catch( function ( error ) {
				if ( error.code === 'epm_import_incomplete' ) {
					fieldError( form, 'accept_partial', true ).focus();
					return;
				}
				showError( form, error.message );
			} )
			.then( function () {
				busy( importButton, false );
			} );
	}

	/* ---------- step 3: import ---------- */

	var importPanel = $( '[data-panel="import"]' );

	function renderJob( job ) {
		if ( ! job ) {
			return;
		}

		var total = Math.max( 0, job.total || 0 );
		var done = Math.min( total, job.done || 0 );
		var ratio = total > 0 ? done / total : job.status === 'done' ? 1 : 0;
		var track = $( '.epm-progress__track', importPanel );
		var bar = $( '.epm-progress__bar', importPanel );

		bar.style.setProperty( '--epm-progress', String( ratio ) );
		track.setAttribute( 'aria-valuenow', String( Math.round( ratio * 100 ) ) );

		$( '[data-import-count]', importPanel ).textContent = format( app.strings.progress, done, total );

		var counts = job.counts || {};
		var summary = [];
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
		Object.keys( labels ).forEach( function ( key ) {
			if ( counts[ key ] > 0 ) {
				summary.push( counts[ key ] + ' ' + labels[ key ] );
			}
		} );
		$( '[data-import-summary]', importPanel ).textContent = summary.join( ' · ' );
		// Waiting, the file in progress, what is still at the old host.
		var media = importResult.render( importPanel, job );

		var log = $( '[data-import-log]', importPanel );
		log.textContent = '';
		( job.log || [] ).slice( 0, 50 ).forEach( function ( entry ) {
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

		var error = $( '[data-import-error]', importPanel );
		error.hidden = ! job.error;
		if ( job.error ) {
			$( 'p', error ).textContent = job.error;
		}

		// Imported from part of the feed: say so, also after the import.
		var partial = $( '[data-import-incomplete]', importPanel );
		var incomplete = job.catalog && ! job.catalog.complete && job.catalog.message;
		partial.hidden = ! incomplete;
		if ( incomplete ) {
			$( 'p', partial ).textContent = format(
				/* translators: %1$s: why the feed could not be read completely */
				__( 'This import covers only part of the feed. %1$s', 'elementor-podcast-manager' ),
				job.catalog.message
			);
		}

		// A move that left files at the old host goes on only after they
		// were copied again or the move was finished knowingly.
		var finished = job.status === 'done' || job.status === 'cancelled' || job.status === 'failed';
		$( '[data-import-continue]', importPanel ).disabled = ! finished;
		$( '[data-action="cancel-import"]', importPanel ).hidden = ! importResult.active( job );

		if ( job.status === 'done' || job.status === 'done_with_problems' || job.status === 'waiting' ) {
			announce( format( app.strings.progress, done, total ) + ( media ? ' ' + media : '' ) + ( incomplete ? ' ' + $( 'p', partial ).textContent : '' ) );
		}
	}

	/* ---------- an unfinished move ---------- */

	function retryCopies( button ) {
		busy( button, true );
		request( 'epm_import_retry', {}, app.importNonce )
			.then( function ( job ) {
				renderJob( job );
				importPanel.focus();
				stepImport();
			} )
			.catch( function ( error ) {
				announce( error.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	function confirmMove( button ) {
		var box = $( '[data-confirm-move]', importPanel );
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
		request( 'epm_import_confirm', { confirm_remaining: 1 }, app.importNonce )
			.then( function ( job ) {
				renderJob( job );
				$( '[data-import-continue]', importPanel ).focus();
			} )
			.catch( function ( failure ) {
				announce( failure.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	function stepImport() {
		if ( state.stepping ) {
			return;
		}
		state.stepping = true;

		function loop() {
			request( 'epm_import_step', {}, app.importNonce )
				.then( function ( job ) {
					renderJob( job );
					if ( importResult.active( job ) ) {
						// A busy lock (cron or a sync is working) just waits a
						// bit; a host that asked to wait, until then.
						window.setTimeout( loop, importResult.delay( job ) );
						return;
					}
					state.stepping = false;
				} )
				.catch( function ( error ) {
					state.stepping = false;
					var box = $( '[data-import-error]', importPanel );
					box.hidden = false;
					$( 'p', box ).textContent = error.message + ' ' + app.strings.leaveImport;
					$( '[data-import-continue]', importPanel ).disabled = false;
				} );
		}

		loop();
	}

	/* ---------- step 4: show details ---------- */

	var showForm = $( '[data-step-form="show"]' );

	function fieldError( form, name, show ) {
		var field = form.querySelector( '[name="' + name + '"]' );
		var message = $( '[data-error-for="' + name + '"]', form );
		if ( field ) {
			if ( show ) {
				field.setAttribute( 'aria-invalid', 'true' );
			} else {
				field.removeAttribute( 'aria-invalid' );
			}
		}
		if ( message ) {
			message.hidden = ! show;
		}
		return show ? field : null;
	}

	function saveShow( form ) {
		var values = formValues( form );
		var emailInvalid = !! values.owner_email && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( values.owner_email.trim() );

		// Every error is shown; focus goes to the first field to fix.
		var titleField = fieldError( form, 'title', ! values.title.trim() );
		var emailField = fieldError( form, 'owner_email', emailInvalid );
		var firstInvalid = titleField || emailField;

		if ( firstInvalid ) {
			firstInvalid.focus();
			return;
		}

		// The textarea shows the description as plain text; an unchanged
		// description is not sent, so its formatting (links, bold) stays.
		var description = form.querySelector( '[name="description"]' );
		if ( description && description.value === description.getAttribute( 'data-initial' ) ) {
			delete values.description;
		}

		var button = $( '[type="submit"]', form );
		busy( button, true );
		showError( form, '' );

		request( 'epm_setup_save', { step: 'show', data: values } )
			.then( function ( result ) {
				renderArtworkChecks( result.artwork );
				next();
			} )
			.catch( function ( error ) {
				showError( form, error.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	function renderArtworkChecks( check ) {
		var list = $( '[data-artwork-checks]', showForm );
		if ( ! list || ! check ) {
			return;
		}
		list.textContent = '';
		( check.messages || [] ).forEach( function ( message ) {
			var li = document.createElement( 'li' );
			li.className = 'epm-checklist__item epm-checklist__item--warning';
			li.innerHTML = '<span class="epm-checklist__icon" aria-hidden="true">!</span><span class="epm-checklist__label"></span>';
			li.querySelector( '.epm-checklist__label' ).textContent = message;
			list.appendChild( li );
		} );
	}

	function chooseArtwork( button ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		var frame = window.wp.media( {
			title: app.strings.chooseImage,
			button: { text: app.strings.useImage },
			library: { type: [ 'image/jpeg', 'image/png' ] },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var input = showForm.querySelector( '[name="artwork_id"]' );
			var preview = $( '[data-artwork-preview]', showForm );
			var url = ( attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url ) || '';

			input.value = attachment.id;
			preview.classList.add( 'has-image' );
			preview.textContent = '';
			var img = document.createElement( 'img' );
			img.src = url;
			img.alt = attachment.alt || '';
			preview.appendChild( img );

			// Immediate feedback; the server repeats the check on save.
			var messages = [];
			if ( [ 'image/jpeg', 'image/png' ].indexOf( attachment.mime ) === -1 ) {
				messages.push( __( 'Use a JPEG or PNG file.', 'elementor-podcast-manager' ) );
			}
			if ( attachment.width && attachment.width !== attachment.height ) {
				/* translators: 1: width, 2: height */
				messages.push( format( __( 'The image is %1$s×%2$s px. It must be exactly square.', 'elementor-podcast-manager' ), attachment.width, attachment.height ) );
			}
			if ( attachment.width && ( attachment.width < 1400 || attachment.width > 3000 ) ) {
				/* translators: %1$s: width in pixels */
				messages.push( format( __( 'The image is %1$s px wide. Use 1400 to 3000 px (3000 px is best).', 'elementor-podcast-manager' ), attachment.width ) );
			}
			renderArtworkChecks( { messages: messages } );
			button.focus();
		} );

		frame.open();
	}

	/* ---------- step 5: look ---------- */

	function saveLook( form ) {
		var values = formValues( form );
		var button = $( '[type="submit"]', form );
		busy( button, true );
		showError( form, '' );

		request( 'epm_setup_save', { step: 'design', data: { preset: values.preset || '', create_page: !! values.create_page } } )
			.then( function ( result ) {
				if ( result.page && result.page.url ) {
					state.pageUrl = result.page.url;
				}
				next();
			} )
			.catch( function ( error ) {
				showError( form, error.message );
			} )
			.then( function () {
				busy( button, false );
			} );
	}

	/* ---------- step 6: done ---------- */

	function finish() {
		var provider = app.providers[ ( app.hosting && app.hosting.provider ) || '' ] || null;
		var connectProvider = app.providers[ selectedProvider() ] || provider || app.providers.other;
		var help = $( '[data-redirect-help]' );
		if ( help && connectProvider ) {
			help.textContent = connectProvider.redirect;
			var title = $( '[data-redirect-title]' );
			if ( title && connectProvider.name ) {
				/* translators: %1$s: podcast host name */
				title.textContent = format( __( 'Set the redirect at %1$s', 'elementor-podcast-manager' ), connectProvider.name );
			}
		}

		var pageLink = $( '[data-page-link]' );
		if ( pageLink && state.pageUrl ) {
			pageLink.href = state.pageUrl;
			pageLink.hidden = false;
		}

		request( 'epm_setup_save', { step: 'finish', data: { done: 1 } } )
			.then( function ( result ) {
				renderReadiness( result.readiness );
			} )
			.catch( function () {
				// The summary is optional; the dashboard shows it too.
			} );
	}

	function renderReadiness( report ) {
		var box = $( '[data-readiness]' );
		var list = $( '[data-readiness-list]' );
		if ( ! box || ! list || ! report ) {
			return;
		}
		var problems = ( report.checks || [] ).filter( function ( check ) {
			return check.status !== 'ok';
		} );
		list.textContent = '';
		problems.slice( 0, 8 ).forEach( function ( check ) {
			var li = document.createElement( 'li' );
			li.className = 'epm-checklist__item epm-checklist__item--' + check.status;
			li.innerHTML = '<span class="epm-checklist__icon" aria-hidden="true"></span><span class="epm-checklist__label"></span><p class="epm-checklist__text"></p>';
			li.querySelector( '.epm-checklist__icon' ).textContent = check.status === 'error' ? '!' : '•';
			li.querySelector( '.epm-checklist__label' ).textContent = check.label;
			li.querySelector( '.epm-checklist__text' ).textContent = check.message;
			list.appendChild( li );
		} );
		box.hidden = problems.length === 0;
	}

	/* ---------- copy buttons ---------- */

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
			// Nothing else to try: the address stays selectable on screen.
		}
		document.body.removeChild( area );
	}

	/* ---------- wiring ---------- */

	root.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '[data-step-form]' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();

		switch ( form.getAttribute( 'data-step-form' ) ) {
			case 'path':
				savePath( form );
				break;
			case 'connect':
				startImport( form );
				break;
			case 'show':
				saveShow( form );
				break;
			case 'look':
				saveLook( form );
				break;
		}
	} );

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
			case 'back':
				back();
				break;
			case 'next':
				// After an import the show details were filled from the feed:
				// reload so the form shows them.
				if ( state.step === 'import' ) {
					var url = new window.URL( window.location.href );
					url.searchParams.set( 'step', 'show' );
					window.location.assign( url.toString() );
					return;
				}
				next();
				break;
			case 'check-feed':
				checkFeed( target );
				break;
			case 'retry-feed':
				retryFeed( target );
				break;
			case 'cancel-import':
				busy( target, true );
				request( 'epm_import_cancel', {}, app.importNonce )
					.then( function ( job ) {
						renderJob( job );
						// The stop button is gone: Continue is next.
						$( '[data-import-continue]', importPanel ).focus();
					} )
					.catch( function ( error ) {
						announce( error.message );
					} )
					.then( function () {
						busy( target, false );
					} );
				break;
			case 'choose-artwork':
				chooseArtwork( target );
				break;
			case 'retry-copies':
				retryCopies( target );
				break;
			case 'confirm-move':
				confirmMove( target );
				break;
		}
	} );

	if ( connectForm ) {
		connectForm.addEventListener( 'change', function ( event ) {
			if ( event.target.name === 'provider' || event.target.name === 'provider_more' ) {
				updateProviderHelp();
			}
		} );
		connectForm.querySelector( '[name="feed_url"]' ).addEventListener( 'input', function () {
			// Also stops a feed that is still being read page by page.
			resetPreview();
		} );
		connectForm.querySelector( '[name="accept_partial"]' ).addEventListener( 'change', function ( event ) {
			if ( event.target.checked ) {
				fieldError( connectForm, 'accept_partial', false );
			}
		} );
		connectForm.querySelector( '[name="feed_url"]' ).addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! state.preview ) {
				event.preventDefault();
				checkFeed( $( '[data-action="check-feed"]', connectForm ) );
			}
		} );
		updateProviderHelp();
	}

	// Leaving during an import is fine (it continues in the background),
	// but say so.
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.stepping ) {
			event.preventDefault();
			event.returnValue = app.strings.leaveImport;
		}
	} );

	/* ---------- start ---------- */

	applyPath();

	var requested = '';
	try {
		requested = new window.URL( window.location.href ).searchParams.get( 'step' ) || '';
	} catch ( e ) {
		requested = '';
	}

	var job = app.job || {};
	if ( importResult.active( job ) && state.path && state.path !== 'new' ) {
		go( 'import', { noFocus: true } );
		renderJob( job );
		stepImport();
	} else if ( job.status === 'done_with_problems' && state.path === 'move' && ( ! requested || requested === 'import' ) ) {
		go( 'import', { noFocus: true } );
		renderJob( job );
	} else if ( requested && flow().indexOf( requested ) > -1 && requested !== 'import' ) {
		go( requested, { noFocus: true } );
	} else {
		go( 'path', { noFocus: true } );
	}
} )();
