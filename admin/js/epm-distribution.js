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
			url.focus();
			return;
		}
		error.hidden = true;
		url.removeAttribute( 'aria-invalid' );

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
				}
				if ( submitted ) {
					submitted.checked = status !== '';
				}
				announce( app.strings.saved + ': ' + ( labels[ status ] || '' ) );
			} )
			.catch( function ( e ) {
				error.textContent = e.message;
				error.hidden = false;
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
					var text = document.createElement( 'p' );
					text.className = 'epm-checklist__text';
					text.textContent = check.message;
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
		var button = event.target.closest( '[data-copy]' );
		if ( ! button ) {
			return;
		}
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
	} );
} )();
