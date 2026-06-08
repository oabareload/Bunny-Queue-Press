/**
 * Pipeline — Buffer action menu and autosave.
 *
 * Responsibilities:
 *   1. Toggle the ⋮ action menu open/closed per card.
 *   2. Close any open menu when clicking outside.
 *   3. Handle "Send to Buffer" clicks: AJAX request, loader, feedback.
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
			var toggle = e.target.closest( '.qps-action-menu-toggle' );

			// Close all menus first.
			document.querySelectorAll( '.qps-action-menu-list' ).forEach( function ( list ) {
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
			var menu = btn.closest( '.qps-action-menu-list' );
			if ( menu ) {
				menu.hidden = true;
				var toggle = menu.previousElementSibling;
				if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
			}

			var card    = btn.closest( '.qps-card' );
			var postId  = btn.getAttribute( 'data-post-id' );
			var nonce   = btn.getAttribute( 'data-nonce' );
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
					// Prefer an aggregated message provided by the server.
					var aggMsg = ( data.data && data.data.message ) ? data.data.message : null;
					var sentAt = ( data.data && data.data.sent_at ) ? data.data.sent_at : '';
					if ( aggMsg ) {
						showFeedback( feedback, 'success', aggMsg );
					} else {
						showFeedback( feedback, 'success', i18n.sent || 'Sent' );
					}

					// Update the indicator if any channel succeeded.
					var results = ( data.data && data.data.results ) ? data.data.results : null;
					var anySuccess = false;
					if ( results ) {
						for ( var k in results ) {
							if ( results[k] && results[k].success ) { anySuccess = true; break; }
						}
					}
					if ( anySuccess ) { updateBufferIndicator( card, sentAt ); }
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
		el.textContent  = msg;
		el.className    = 'qps-card-feedback qps-card-feedback--' + state;
	}

	/**
	 * Updates the Buffer sent indicator checkmark on the card.
	 * If the indicator already exists, update its title; otherwise create it.
	 *
	 * @param {Element|null} card   The card li element.
	 * @param {string}       sentAt Sent datetime string.
	 */
	function updateBufferIndicator( card, sentAt ) {
		if ( ! card ) { return; }
		var actionsEl = card.querySelector( '.qps-card-actions' );
		if ( ! actionsEl ) { return; }

		var indicator = actionsEl.querySelector( '.qps-buffer-indicator' );
		var title     = ( i18n.sentOn || 'Sent to Buffer on' ) + ' ' + sentAt;

		if ( indicator ) {
			indicator.setAttribute( 'title', title );
		} else {
			indicator          = document.createElement( 'span' );
			indicator.className = 'qps-buffer-indicator';
			indicator.setAttribute( 'title', title );
			indicator.innerHTML = '&#10003;';
			actionsEl.insertBefore( indicator, actionsEl.firstChild );
		}
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		initMenus();
		initSendToBuffer();
	} );

} ( window, document ) );
