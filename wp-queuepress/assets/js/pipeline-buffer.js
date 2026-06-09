/**
 * Pipeline — Buffer action menu and platform status strip.
 *
 * Responsibilities:
 *   1. Toggle the ⋮ action menu open/closed per card.
 *   2. Close any open menu when clicking outside.
 *   3. Handle "Send to Buffer" clicks: AJAX request, loader, feedback.
 *   4. After a successful publication, update the platform status strip
 *      per channel (switch idle → success).
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
	// Send to Buffer
	// -------------------------------------------------------------------------

	function initSendToBuffer() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.qps-send-to-buffer' );
			if ( ! btn ) { return; }

			// Close the menu.
			var menu = btn.closest( '.qps-image-menu-list' );
			if ( menu ) {
				menu.hidden = true;
				var toggle = menu.previousElementSibling;
				if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
			}

			var card     = btn.closest( '.qps-card' );
			var postId   = btn.getAttribute( 'data-post-id' );
			var nonce    = btn.getAttribute( 'data-nonce' );
			var feedback = card ? card.querySelector( '.qps-card-feedback' ) : null;

			if ( ! postId || ! nonce ) { return; }

			showFeedback( feedback, 'sending', i18n.sending || 'Sending\u2026' );

			var body = new FormData();
			body.append( 'action',      'qps_send_to_buffer' );
			body.append( 'post_id',     postId );
			body.append( '_ajax_nonce', nonce );

			fetch( ajaxUrl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body
			} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				if ( data && data.success ) {
					var aggMsg = ( data.data && data.data.message ) ? data.data.message : null;
					var sentAt = ( data.data && data.data.sent_at ) ? data.data.sent_at : '';
					if ( aggMsg ) {
						showFeedback( feedback, 'success', aggMsg );
					} else {
						showFeedback( feedback, 'success', i18n.sent || 'Sent' );
					}

					// Update the platform status strip based on per-channel results.
					var results = ( data.data && data.data.results ) ? data.data.results : null;
					if ( results ) { updatePlatformStrip( card, results ); }
				} else {
					var errMsg = ( data && data.data && data.data.message )
						? data.data.message
						: ( i18n.error || 'Error sending to Buffer.' );
					showFeedback( feedback, 'error', errMsg );
				}
			} )
			.catch( function () {
				showFeedback( feedback, 'error', i18n.networkError || 'Network error. Please try again.' );
			} );
		} );
	}

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
	 * Updates the platform status strip on a card after a publication attempt.
	 *
	 * For each result, finds the matching `.qps-platform[data-service="..."]`
	 * span and switches its state modifier to `--success` or `--error`.
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

			platform.classList.remove( 'qps-platform--idle', 'qps-platform--success', 'qps-platform--error' );
			if ( r.success ) {
				platform.classList.add( 'qps-platform--success' );
			} else {
				platform.classList.add( 'qps-platform--error' );
			}

			// Update the tooltip with the latest state.
			var label = ( service.charAt( 0 ).toUpperCase() + service.slice( 1 ) );
			var lines = [ label, r.success ? ( i18n.published || 'Published' ) : ( i18n.error || 'Error' ) ];
			if ( r.sent_at ) { lines.push( r.sent_at ); }
			if ( r.status )  { lines.push( r.status ); }
			platform.setAttribute( 'title', lines.join( '\n' ) );
		} );
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		initMenus();
		initSendToBuffer();
	} );

} ( window, document ) );
