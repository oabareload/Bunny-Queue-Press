/**
 * Pipeline — Buffer action menu and platform status strip.
 *
 * Responsibilities:
 *   1. Toggle the ⋮ action menu open/closed per card.
 *   2. Close any open menu when clicking outside.
 *   3. Handle "Send to Buffer" clicks: AJAX request, loader, feedback.
 *   4. Handle per-service resend clicks (clickable platform icons).
 *   5. Handle "Delete Buffer Posts" clicks: AJAX request, confirmation, reset.
 *   6. After a successful operation, update the platform status strip
 *      per channel.
 *
 * Error messages from Buffer are displayed exactly as received.
 * No translation. No reinterpretation.
 *
 * @package QueuePostScheduler
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.qpsPipelineBuffer || {};
	var ajaxUrl = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
	var i18n    = cfg.i18n  || {};

	// -------------------------------------------------------------------------
	// Action menu — toggle
	// -------------------------------------------------------------------------

	function initMenus() {
		document.addEventListener( 'click', function ( e ) {
			// If the click is on the View Post link, let it through naturally.
			if ( e.target.closest( 'a.qps-view-post' ) ) { return; }

			var toggle = e.target.closest( '.qps-image-menu-toggle' );

			// Close all menus first.
			document.querySelectorAll( '.qps-image-menu-list' ).forEach( function ( list ) {
				list.hidden = true;
				var btn = list.previousElementSibling;
				if ( btn ) { btn.setAttribute( 'aria-expanded', 'false' ); }
			} );

			if ( ! toggle ) { return; }

			// Open the clicked menu.
			var menu = toggle.nextElementSibling;
			if ( menu ) {
				menu.hidden = false;
				toggle.setAttribute( 'aria-expanded', 'true' );
				e.stopPropagation();
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Shows or updates the inline feedback area on a card.
	 *
	 * @param {Element|null} el    The feedback element.
	 * @param {string}       state 'sending' | 'success' | 'error'
	 * @param {string}       msg  Message to display.
	 */
	function showFeedback( el, state, msg ) {
		if ( ! el ) { return; }
		el.textContent = msg;
		el.className   = 'qps-card-feedback qps-card-feedback--' + state;
	}

	/**
	 * Posts an AJAX request with the given action and FormData body.
	 *
	 * @param {string} action AJAX action name.
	 * @param {FormData} body Form data including the nonce.
	 * @returns {Promise<Object>} Parsed JSON response.
	 */
	function postAction( action, body ) {
		body.append( 'action', action );
		return fetch( ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/**
	 * Updates the platform status strip on a card after a publish/resend attempt.
	 *
	 * For each result, finds the matching `.qps-platform[data-service="..."]`
	 * element and switches its state modifier to `--success` or `--error`.
	 *
	 * @param {Element|null} card    The card li element.
	 * @param {Object}       results Map of channel_id => { service, success, status, sent_at }.
	 */
	function updatePlatformStrip( card, results ) {
		if ( ! card || ! results ) { return; }
		var strip = card.querySelector( '.qps-platform-strip' );
		if ( ! strip ) { return; }

		Object.keys( results ).forEach( function ( cid ) {
			var r = results[ cid ];
			if ( ! r || typeof r !== 'object' ) { return; }
			var service = ( r.service || '' ).toLowerCase();
			if ( ! service ) { return; }
			var platform = strip.querySelector( '.qps-platform[data-service="' + service + '"]' );
			if ( ! platform ) { return; }

			// Strip all known state modifiers and the clickable variant.
			platform.classList.remove(
				'qps-platform--idle',
				'qps-platform--success',
				'qps-platform--error',
				'qps-platform--pending',
				'qps-platform--scheduled',
				'qps-platform--queued',
				'qps-platform--added_to_queue'
			);
			if ( r.success ) {
				var statusClass = 'qps-platform--success';
				var knownGreen = [ 'scheduled', 'queued', 'added_to_queue', 'success' ];
				if ( r.status && knownGreen.indexOf( r.status.toLowerCase() ) !== -1 ) {
					statusClass = 'qps-platform--' + r.status.toLowerCase();
				}
				platform.classList.add( statusClass );
			} else {
				platform.classList.add( 'qps-platform--error' );
			}

			// Update tooltip.
			var label = ( service.charAt( 0 ).toUpperCase() + service.slice( 1 ) );
			var lines = [ label, r.success ? ( i18n.published || 'Published' ) : ( i18n.error || 'Error' ) ];
			if ( r.sent_at ) { lines.push( r.sent_at ); }
			if ( r.status )  { lines.push( r.status ); }
			platform.setAttribute( 'title', lines.join( '\n' ) );
		} );
	}

	/**
	 * Resets the platform strip of a card to all-idle, removing any
	 * clickable affordances. Used after a successful delete.
	 *
	 * @param {Element|null} card
	 */
	function resetPlatformStrip( card ) {
		if ( ! card ) { return; }
		var strip = card.querySelector( '.qps-platform-strip' );
		if ( ! strip ) { return; }
		strip.querySelectorAll( '.qps-platform' ).forEach( function ( p ) {
			p.classList.remove(
				'qps-platform--success',
				'qps-platform--error',
				'qps-platform--pending',
				'qps-platform--scheduled',
				'qps-platform--queued',
				'qps-platform--added_to_queue',
				'qps-platform--busy'
			);
			p.classList.add( 'qps-platform--idle' );
			p.setAttribute( 'title', '' );
		} );
	}

	// -------------------------------------------------------------------------
	// Send to Buffer
	// -------------------------------------------------------------------------

	function initSendToBuffer() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.qps-send-to-buffer' );
			if ( ! btn ) { return; }

			// Defense in depth: server also enforces this.
			if ( btn.getAttribute( 'data-publishable' ) === '0' ) { return; }
			if ( btn.disabled || btn.getAttribute( 'aria-disabled' ) === 'true' ) { return; }

			closeMenuOf( btn );

			var card     = btn.closest( '.qps-card' );
			var postId   = btn.getAttribute( 'data-post-id' );
			var nonce    = btn.getAttribute( 'data-nonce' );
			var feedback = card ? card.querySelector( '.qps-card-feedback' ) : null;

			if ( ! postId || ! nonce ) { return; }

			showFeedback( feedback, 'sending', i18n.sending || 'Sending…' );

			var body = new FormData();
			body.append( 'post_id',     postId );
			body.append( '_ajax_nonce', nonce );

			postAction( 'qps_send_to_buffer', body )
				.then( function ( data ) { return handlePublishResponse( data, feedback, card ); } )
				.catch( function () { showFeedback( feedback, 'error', i18n.networkError || 'Network error. Please try again.' ); } );
		} );
	}

	// -------------------------------------------------------------------------
	// Resend by service (clickable platform icons)
	// -------------------------------------------------------------------------

	function initResendByService() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.qps-platform--clickable' );
			if ( ! btn ) { return; }
			// Only <button> elements (real records) carry the data-* attributes.
			if ( ! btn.dataset || ! btn.dataset.service ) { return; }

			var card     = btn.closest( '.qps-card' );
			var postId   = btn.getAttribute( 'data-post-id' );
			var service  = btn.getAttribute( 'data-service' );
			var nonce    = btn.getAttribute( 'data-nonce' );
			var feedback = card ? card.querySelector( '.qps-card-feedback' ) : null;

			if ( ! postId || ! service || ! nonce ) { return; }

			// Visual busy state.
			btn.classList.add( 'qps-platform--busy' );

			var body = new FormData();
			body.append( 'post_id',     postId );
			body.append( 'service',     service );
			body.append( '_ajax_nonce', nonce );

			postAction( 'qps_send_to_buffer_service', body )
				.then( function ( data ) {
					btn.classList.remove( 'qps-platform--busy' );
					handlePublishResponse( data, feedback, card );
				} )
				.catch( function () {
					btn.classList.remove( 'qps-platform--busy' );
					showFeedback( feedback, 'error', i18n.networkError || 'Network error. Please try again.' );
				} );
		} );
	}

	// -------------------------------------------------------------------------
	// Delete Buffer Posts
	// -------------------------------------------------------------------------

	function initDeleteBufferPosts() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.qps-delete-buffer-posts' );
			if ( ! btn ) { return; }

			closeMenuOf( btn );

			var card     = btn.closest( '.qps-card' );
			var postId   = btn.getAttribute( 'data-post-id' );
			var nonce    = btn.getAttribute( 'data-nonce' );
			var feedback = card ? card.querySelector( '.qps-card-feedback' ) : null;

			if ( ! postId || ! nonce ) { return; }

			var confirmMsg = i18n.deleteConfirm || 'Delete all Buffer publications for this post?';
			if ( ! window.confirm( confirmMsg ) ) { return; }

			showFeedback( feedback, 'sending', i18n.deleting || 'Deleting…' );

			var body = new FormData();
			body.append( 'post_id',     postId );
			body.append( '_ajax_nonce', nonce );

			postAction( 'qps_delete_buffer_posts', body )
				.then( function ( data ) {
					if ( data && data.success ) {
						resetPlatformStrip( card );
						// Hide the Delete menu item on this card, since the state is gone.
						btn.closest( 'li' ).style.display = 'none';
						var msg = ( data.data && data.data.message ) ? data.data.message : ( i18n.deleted || 'Deleted' );
						showFeedback( feedback, 'success', msg );
					} else {
						var errMsg = ( data && data.data && data.data.message )
							? data.data.message
							: ( i18n.error || 'Error.' );
						showFeedback( feedback, 'error', errMsg );
					}
				} )
				.catch( function () {
					showFeedback( feedback, 'error', i18n.networkError || 'Network error. Please try again.' );
				} );
		} );
	}

	// -------------------------------------------------------------------------
	// Shared response handler for publish / resend
	// -------------------------------------------------------------------------

	function handlePublishResponse( data, feedback, card ) {
		if ( data && data.success ) {
			var aggMsg = ( data.data && data.data.message ) ? data.data.message : null;
			if ( aggMsg ) {
				showFeedback( feedback, 'success', aggMsg );
			} else {
				showFeedback( feedback, 'success', i18n.sent || 'Sent' );
			}
			var results = ( data.data && data.data.results ) ? data.data.results : null;
			if ( results ) { updatePlatformStrip( card, results ); }
		} else {
			var err = ( data && data.data && data.data.message )
				? data.data.message
				: ( i18n.error || 'Error sending to Buffer.' );
			showFeedback( feedback, 'error', err );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function closeMenuOf( btn ) {
		var menu = btn.closest( '.qps-image-menu-list' );
		if ( ! menu ) { return; }
		menu.hidden = true;
		var toggle = menu.previousElementSibling;
		if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		initMenus();
		initSendToBuffer();
		initResendByService();
		initDeleteBufferPosts();
	} );

} ( window, document ) );
