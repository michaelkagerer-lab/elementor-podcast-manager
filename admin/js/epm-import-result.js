/**
 * The result of an import, shared by Hosting & import and the setup
 * assistant (markup: admin/views/partials/import-result.php):
 *
 * - a host that asked the import to wait (HTTP 429), and until when;
 * - the file being copied over several requests, with its progress;
 * - every file that still loads from the old host, per kind (audio,
 *   episode images, WebVTT/SRT transcript files, transcripts in other
 *   formats), each episode linked with the reason;
 * - a move that is not finished because of those files: copy them again,
 *   or finish the move after confirming what stays behind;
 * - when everything was copied, that it was.
 *
 * The screens handle the buttons (data-action="retry-copies" and
 * "confirm-move") with their own requests.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var _n = i18n ? i18n._n : function ( single, plural, count ) {
		return count === 1 ? single : plural;
	};

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d+)\$s/g, function ( match, index ) {
			var value = args[ Number( index ) - 1 ];
			return value === undefined ? match : String( value );
		} );
	}

	function $( selector, scope ) {
		return scope.querySelector( selector );
	}

	function size( bytes ) {
		var mb = bytes / 1048576;
		return mb >= 10 ? Math.round( mb ) + ' MB' : mb >= 1 ? mb.toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
	}

	function kindNoun( kind ) {
		switch ( kind ) {
			case 'audio':
				return __( 'the audio', 'elementor-podcast-manager' );
			case 'image':
				return __( 'the episode image', 'elementor-podcast-manager' );
			default:
				return __( 'the transcript file', 'elementor-podcast-manager' );
		}
	}

	var finished = [ 'done', 'done_with_problems', 'cancelled', 'failed' ];

	/**
	 * Show a job's result in a box that holds the partial's markup.
	 *
	 * @param {Element} box Box.
	 * @param {Object}  job Client state.
	 * @return {string} What to announce ('' when nothing).
	 */
	function render( box, job ) {
		var said = [];
		var remaining = job.remaining || {};
		var kinds = Object.keys( remaining );
		var over = finished.indexOf( job.status ) > -1;

		// The host asked to wait.
		var waiting = $( '[data-import-waiting]', box );
		if ( waiting ) {
			waiting.hidden = job.status !== 'waiting';
			if ( job.status === 'waiting' ) {
				var until = new Date( job.wait_until * 1000 );
				var text = format(
					/* translators: 1: time, e.g. "14:05", 2: what the host answered, e.g. "The host answered HTTP 429 (Too Many Requests)." */
					__( 'The old host asked the import to wait. It continues by itself at %1$s, also when you leave this page. %2$s', 'elementor-podcast-manager' ),
					until.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ),
					job.wait_reason || ''
				);
				$( 'p', waiting ).textContent = text;
				said.push( text );
			}
		}

		// A large file over several requests.
		var current = $( '[data-copy-current]', box );
		if ( current ) {
			current.hidden = ! job.current;
			if ( job.current ) {
				current.textContent = job.current.total > 0
					? format(
						/* translators: 1: "the audio", "the episode image" …, 2: episode title, 3: amount copied, e.g. "120 MB", 4: file size */
						__( 'Copying %1$s of “%2$s”: %3$s of %4$s.', 'elementor-podcast-manager' ),
						kindNoun( job.current.kind ),
						job.current.title,
						size( job.current.bytes ),
						size( job.current.total )
					)
					: format(
						/* translators: 1: "the audio", "the episode image" …, 2: episode title, 3: amount copied, e.g. "120 MB" */
						__( 'Copying %1$s of “%2$s”: %3$s so far.', 'elementor-podcast-manager' ),
						kindNoun( job.current.kind ),
						job.current.title,
						size( job.current.bytes )
					);
			}
		}

		// What still loads from the old host.
		var callout = $( '[data-remaining]', box );
		if ( callout ) {
			callout.hidden = ! ( over && kinds.length );
			if ( ! callout.hidden ) {
				var unfinished = job.status === 'done_with_problems';
				var title = unfinished
					? __( 'The move is not finished.', 'elementor-podcast-manager' )
					: __( 'These files still load from the old host.', 'elementor-podcast-manager' );
				$( '[data-remaining-title]', callout ).textContent = title;
				$( '[data-remaining-text]', callout ).textContent = unfinished
					? job.problems
					: __( 'Open each episode to add the file, or run the import again with “Copy audio”, before you close the old account.', 'elementor-podcast-manager' );
				said.push( title );

				var groups = $( '[data-remaining-groups]', callout );
				groups.textContent = '';
				kinds.forEach( function ( kind ) {
					var group = remaining[ kind ];
					var heading = document.createElement( 'p' );
					heading.className = 'epm-remaining__kind';
					var strong = document.createElement( 'strong' );
					strong.textContent = group.label;
					heading.appendChild( strong );
					groups.appendChild( heading );

					var list = document.createElement( 'ul' );
					list.className = 'epm-callout__list';
					( group.episodes || [] ).forEach( function ( episode ) {
						var li = document.createElement( 'li' );
						var link = document.createElement( episode.edit ? 'a' : 'span' );
						link.textContent = episode.title;
						if ( episode.edit ) {
							link.href = episode.edit;
						}
						li.appendChild( link );
						if ( episode.reason ) {
							li.appendChild( document.createTextNode( ': ' + episode.reason ) );
						}
						list.appendChild( li );
					} );
					var more = group.count - ( group.episodes || [] ).length;
					if ( more > 0 ) {
						var li = document.createElement( 'li' );
						li.textContent = format(
							/* translators: %1$s: number of episodes */
							_n( 'and %1$s more', 'and %1$s more', more, 'elementor-podcast-manager' ),
							more
						);
						list.appendChild( li );
					}
					groups.appendChild( list );
				} );

				var retry = $( '[data-action="retry-copies"]', callout );
				retry.hidden = ! job.can_retry;
				var confirm = $( '[data-confirm-move]', callout );
				confirm.hidden = ! ( unfinished && job.purpose === 'move' );
				if ( ! confirm.hidden ) {
					$( '[data-confirm-move-label]', confirm ).textContent = format(
						/* translators: %1$s: list such as "1 audio file, 2 episode images" */
						__( 'Finish the move anyway. These stay at the old host and stop working when that account is closed: %1$s.', 'elementor-podcast-manager' ),
						kinds.map( function ( kind ) {
							return remaining[ kind ].label;
						} ).join( ', ' )
					);
				}
				$( '[data-remaining-actions]', callout ).hidden = retry.hidden && confirm.hidden;
			}
		}

		// Everything was copied.
		var complete = $( '[data-copy-complete]', box );
		if ( complete ) {
			complete.hidden = ! ( job.status === 'done' && job.copy_media && ! kinds.length );
			if ( ! complete.hidden ) {
				said.push( complete.textContent );
			}
		}

		return said.join( ' ' );
	}

	/**
	 * Milliseconds until the screen should ask for the job again.
	 *
	 * @param {Object} job Client state.
	 * @return {number}
	 */
	function delay( job ) {
		if ( job.status === 'waiting' ) {
			return Math.min( 60000, Math.max( 1000, job.wait_until * 1000 - Date.now() + 1000 ) );
		}
		return job.busy ? 3000 : 150;
	}

	window.epmImportResult = {
		render: render,
		delay: delay,
		active: function ( job ) {
			return job.status === 'running' || job.status === 'waiting';
		},
	};
} )();
