/**
 * Distribution screen: per-platform progress and listing links.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-epm-distribution]' );
	var app = window.epmApp;

	if ( ! root || ! app ) {
		return;
	}

	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};

	var announcer = root.querySelector( '[data-epm-announce]' );

	function announce( message ) {
		if ( ! announcer ) {
			return;
		}
		announcer.textContent = '';
		window.setTimeout( function () {
			announcer.textContent = message;
		}, 50 );
	}

	var labels = {
		'': __( 'Not submitted', 'elementor-podcast-manager' ),
		submitted: __( 'Submitted', 'elementor-podcast-manager' ),
		listed: __( 'Listed', 'elementor-podcast-manager' ),
	};

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d+)\$s/g, function ( match, index ) {
			var value = args[ Number( index ) - 1 ];
			return value === undefined ? match : String( value );
		} );
	}

	/**
	 * Tie an error message to its field (or untie it) for screen readers.
	 */
	function describe( field, id, on ) {
		var ids = ( field.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( function ( value ) {
			return value && value !== id;
		} );
		if ( on ) {
			ids.push( id );
		}
		field.setAttribute( 'aria-describedby', ids.join( ' ' ) );
	}

	/**
	 * The essential-platform count in the header, and the one "Submit to"
	 * button that is primary: the first essential platform not submitted.
	 */
	function updateProgress() {
		var rows = Array.prototype.slice.call( root.querySelectorAll( '[data-essential]' ) );
		var done = 0;
		var next = null;

		rows.forEach( function ( row ) {
			var badge = row.querySelector( '[data-status-badge]' );
			if ( badge && badge.getAttribute( 'data-status' ) ) {
				done++;
			} else if ( ! next ) {
				next = row;
			}
		} );

		root.querySelectorAll( '[data-submit-link]' ).forEach( function ( link ) {
			link.classList.toggle( 'button-primary', !! next && next.contains( link ) );
		} );

		var score = root.querySelector( '[data-dist-score]' );
		if ( score ) {
			/* translators: 1: platforms done, 2: essential platforms */
			score.textContent = format( __( '%1$s of %2$s', 'elementor-podcast-manager' ), done, rows.length );
		}
	}

	function save( form ) {
		var id = form.getAttribute( 'data-directory-form' );
		var row = root.querySelector( '[data-directory="' + id + '"]' );
		var submitted = form.querySelector( '[name="submitted"]' );
		var url = form.querySelector( '[name="url"]' );
		var button = form.querySelector( '[type="submit"]' );
		var error = form.querySelector( '[data-error]' );

		if ( url.value && ! /^https?:\/\/\S+\.\S+/.test( url.value.trim() ) ) {
			error.textContent = __( 'Paste the full link to your show, starting with https://', 'elementor-podcast-manager' );
			error.hidden = false;
			url.setAttribute( 'aria-invalid', 'true' );
			describe( url, error.id, true );
			url.focus();
			return;
		}
		error.hidden = true;
		url.removeAttribute( 'aria-invalid' );
		describe( url, error.id, false );

		var body = new window.FormData();
		body.append( 'action', 'epm_distribution_save' );
		body.append( 'nonce', app.nonce );
		body.append( 'directory', id );
		body.append( 'status', url.value.trim() ? 'listed' : submitted && submitted.checked ? 'submitted' : '' );
		body.append( 'url', url.value.trim() );

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		window
			.fetch( app.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || app.strings.failed );
				}
				var status = json.data.status || '';
				var badge = row.querySelector( '[data-status-badge]' );
				if ( badge ) {
					badge.textContent = labels[ status ] || '';
					badge.className = 'epm-badge' + ( status === 'listed' ? ' epm-badge--ok' : status === 'submitted' ? ' epm-badge--info' : '' );
					badge.setAttribute( 'data-status', status );
				}
				if ( submitted ) {
					submitted.checked = status !== '';
				}
				updateProgress();
				announce( window.wp.i18n.sprintf(
					/* translators: %s: submission status */
					window.wp.i18n.__( 'Saved: %s', 'elementor-podcast-manager' ), labels[ status ] || '' ) );
			} )
			.catch( function ( e ) {
				error.textContent = e.message;
				error.hidden = false;
				describe( url, error.id, true );
				announce( e.message );
			} )
			.then( function () {
				button.disabled = false;
				button.removeAttribute( 'aria-busy' );
			} );
	}

	root.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '[data-directory-form]' );
		if ( form ) {
			event.preventDefault();
			save( form );
		}
	} );

	// Ticking "I submitted" saves right away.
	root.addEventListener( 'change', function ( event ) {
		if ( event.target.name === 'submitted' ) {
			save( event.target.closest( '[data-directory-form]' ) );
		}
	} );

	function serverCheck( button ) {
		var list = root.querySelector( '[data-server-checks]' );
		var body = new window.FormData();
		body.append( 'action', 'epm_server_check' );
		body.append( 'nonce', app.nonce );

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		window
			.fetch( app.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || app.strings.failed );
				}
				list.textContent = '';
				var problems = 0;
				json.data.checks.forEach( function ( check ) {
					var li = document.createElement( 'li' );
					li.className = 'epm-checklist__item epm-checklist__item--' + check.status;
					var icon = document.createElement( 'span' );
					icon.className = 'epm-checklist__icon';
					icon.setAttribute( 'aria-hidden', 'true' );
					icon.textContent = check.status === 'ok' ? '✓' : '!';
					var label = document.createElement( 'span' );
					label.className = 'epm-checklist__label';
					label.textContent = check.label;
					var text = document.createElement( 'div' );
					text.className = 'epm-checklist__text';
					text.textContent = check.message;
					if ( check.details ) {
						var detail = document.createElement( 'details' );
						var summary = document.createElement( 'summary' );
						summary.textContent = window.wp.i18n.__( 'Technical details', 'elementor-podcast-manager' );
						var diagnostic = document.createElement( 'p' );
						diagnostic.textContent = check.details;
						detail.appendChild( summary );
						detail.appendChild( diagnostic );
						text.appendChild( detail );
					}
					li.appendChild( icon );
					li.appendChild( label );
					li.appendChild( text );
					list.appendChild( li );
					if ( check.status !== 'ok' ) {
						problems++;
					}
				} );
				list.hidden = false;
				announce(
					problems
						? __( 'The delivery test found problems.', 'elementor-podcast-manager' )
						: __( 'The delivery test passed.', 'elementor-podcast-manager' )
				);
			} )
			.catch( function ( e ) {
				announce( e.message );
			} )
			.then( function () {
				button.disabled = false;
				button.removeAttribute( 'aria-busy' );
			} );
	}

	root.addEventListener( 'click', function ( event ) {
		var check = event.target.closest( '[data-action="server-check"]' );
		if ( check ) {
			serverCheck( check );
			return;
		}

	} );
} )();
