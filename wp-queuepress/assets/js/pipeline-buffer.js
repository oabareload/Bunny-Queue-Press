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
 *   7. Handle "Move Top", "Move Up", "Move Down" via SWAP REST endpoint.
 *   8. Handle Drag & Drop on Future cards (swap semantics, same endpoint).
 *
 * @package QueuePostScheduler
 */
( function ( window, document ) {
	'use strict';

	var cfg     = window.qpsPipelineBuffer || {};
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
				var knownGreen	= [ 'scheduled', 'queued', 'added_to_queue', 'success' ];
				if ( r.status && knownGreen.indexOf( r.status.toLowerCase() ) !== -1 ) {
					statusClass = 'qps-platform--' + r.status.toLowerCase();
				}
				platform.classList.add( statusClass );
			} else {
				platform.classList.add( 'qps-platform--error' );
			}

			// Update tooltip.
			var label = ( service.charAt( 0 ).toUpperCase() + service.slice( 1 ) );
			var lines  = [ label, r.success ? ( i18n.published || 'Published' ) : ( i18n.error || 'Error' ) ];
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

			showFeedback( feedback, 'sending', i18n.sending || 'Sending\u2026' );

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

			showFeedback( feedback, 'sending', i18n.deleting || 'Deleting\u2026' );

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
			showFeedback( feedback, 'success', aggMsg || i18n.sent || 'Sent' );
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
	// Swap — shared REST call and flat card list
	// -------------------------------------------------------------------------

	/**
	 * Returns all .qps-card--draggable elements within the Scheduled section,
	 * in their current visual DOM order (flattened across all day-group <ul>s).
	 * This is the authoritative ordered list for Move Up / Down / Top / Drag.
	 *
	 * @returns {NodeList}
	 */
	function scheduledCards() {
		// All swap-enabled cards live inside lists marked data-qps-swap-section="scheduled".
		// querySelectorAll on the document returns them in DOM order.
		return document.querySelectorAll( '[data-qps-swap-section="scheduled"] .qps-card--draggable' );
	}

	/**
	 * Converts the NodeList to an Array for index-based access.
	 *
	 * @returns {Element[]}
	 */
	function scheduledCardsArray() {
		return Array.prototype.slice.call( scheduledCards() );
	}

	/**
	 * Returns the SWAP REST endpoint URL.
	 *
	 * @returns {string}
	 */
	function swapEndpoint() {
		return ( cfg.restUrl || '' ).replace( /\/$/, '' ) + '/posts/swap';
	}

	/**
	 * Calls POST /wp-queuepress/v1/posts/swap with source and target post IDs.
	 * On success (applied === 2) reloads after a brief delay so the re-rendered
	 * page reflects the authoritative post_date order.
	 *
	 * @param {number}       sourceId   Source post ID.
	 * @param {number}       targetId   Target post ID.
	 * @param {Element|null} feedbackEl Feedback element on the source card.
	 */
	function callSwapAndReload( sourceId, targetId, feedbackEl ) {
		showFeedback( feedbackEl, 'sending', i18n.moving || 'Moving\u2026' );

		fetch( swapEndpoint(), {
			method:      'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.restNonce || ''
			},
			body: JSON.stringify( { source: sourceId, target: targetId } )
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data && data.applied === 2 ) {
					showFeedback( feedbackEl, 'success', i18n.swapSuccess || 'Schedule updated.' );
					window.setTimeout( function () { window.location.reload(); }, 350 );
				} else {
					var msg = ( data && data.message ) ? data.message : ( i18n.swapError || 'Could not swap. Please reload and try again.' );
					showFeedback( feedbackEl, 'error', msg );
				}
			} )
			.catch( function () {
				showFeedback( feedbackEl, 'error', i18n.networkError || 'Network error.' );
			} );
	}

	/**
	 * Reads the post ID from a card element.
	 *
	 * @param {Element} card
	 * @returns {number}
	 */
	function cardPostId( card ) {
		return parseInt( card.getAttribute( 'data-post-id' ), 10 ) || 0;
	}

	// -------------------------------------------------------------------------
	// Move Top — swap with the first card in the Scheduled list
	// -------------------------------------------------------------------------

	function initMoveTop() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.qps-move-top' );
			if ( ! btn ) { return; }
			if ( btn.disabled || btn.getAttribute( 'aria-disabled' ) === 'true' ) { return; }

			closeMenuOf( btn );

			var card   = btn.closest( '.qps-card' );
			if ( ! card ) { return; }

			var cards    = scheduledCardsArray();
			var firstCard = cards[ 0 ];
			if ( ! firstCard || firstCard === card ) { return; }

			var sourceId  = parseInt( btn.getAttribute( 'data-post-id' ), 10 );
			var targetId  = cardPostId( firstCard );
			if ( ! sourceId || ! targetId || sourceId === targetId ) { return; }

			var feedback = card.querySelector( '.qps-card-feedback' );
			callSwapAndReload( sourceId, targetId, feedback );
		} );
	}

	// -------------------------------------------------------------------------
	// Move Up — swap with the previous card in the flattened Scheduled list
	// -------------------------------------------------------------------------

	function initMoveUp() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.qps-move-up' );
			if ( ! btn ) { return; }
			if ( btn.disabled || btn.getAttribute( 'aria-disabled' ) === 'true' ) { return; }

			closeMenuOf( btn );

			var card = btn.closest( '.qps-card' );
			if ( ! card ) { return; }

			var cards = scheduledCardsArray();
			var idx   = cards.indexOf( card );
			if ( idx <= 0 ) { return; }

			var prevCard = cards[ idx - 1 ];
			var sourceId = parseInt( btn.getAttribute( 'data-post-id' ), 10 );
			var targetId = cardPostId( prevCard );
			if ( ! sourceId || ! targetId ) { return; }

			var feedback = card.querySelector( '.qps-card-feedback' );
			callSwapAndReload( sourceId, targetId, feedback );
		} );
	}

	// -------------------------------------------------------------------------
	// Move Down — swap with the next card in the flattened Scheduled list
	// -------------------------------------------------------------------------

	function initMoveDown() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.qps-move-down' );
			if ( ! btn ) { return; }
			if ( btn.disabled || btn.getAttribute( 'aria-disabled' ) === 'true' ) { return; }

			closeMenuOf( btn );

			var card = btn.closest( '.qps-card' );
			if ( ! card ) { return; }

			var cards    = scheduledCardsArray();
			var idx      = cards.indexOf( card );
			if ( idx < 0 || idx >= cards.length - 1 ) { return; }

			var nextCard = cards[ idx + 1 ];
			var sourceId = parseInt( btn.getAttribute( 'data-post-id' ), 10 );
			var targetId = cardPostId( nextCard );
			if ( ! sourceId || ! targetId ) { return; }

			var feedback = card.querySelector( '.qps-card-feedback' );
			callSwapAndReload( sourceId, targetId, feedback );
		} );
	}

	// -------------------------------------------------------------------------
	// Drag & Drop — swap semantics on drop, HTML5 native API
	// Dragging from the handle (`.qps-drag-handle`) is the intended gesture.
	// Dropping card A onto card B swaps their post_dates via the swap endpoint.
	// -------------------------------------------------------------------------

	var dragSourceId = null;

	function initDragDrop() {
		// dragstart — only allow drags that originate on the handle or card body,
		// not on the action menu. Store source post ID.
		document.addEventListener( 'dragstart', function ( e ) {
			var card = e.target.closest( '.qps-card--draggable' );
			if ( ! card ) { return; }
			if ( e.target.closest( '.qps-image-menu' ) ) { e.preventDefault(); return; }

			dragSourceId = cardPostId( card );

			try {
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', String( dragSourceId || '' ) );
			} catch ( _e ) { /* non-fatal */ }

			card.classList.add( 'qps-card--dragging' );
		} );

		// dragover — must preventDefault to allow drop; add highlight.
		document.addEventListener( 'dragover', function ( e ) {
			var card = e.target.closest( '.qps-card--draggable' );
			if ( ! card ) { return; }
			e.preventDefault();
			try { e.dataTransfer.dropEffect = 'move'; } catch ( _e ) {}
			// Only highlight if this is a valid different target.
			if ( cardPostId( card ) !== dragSourceId ) {
				card.classList.add( 'qps-card--drag-over' );
			}
		} );

		// dragleave — remove highlight when cursor leaves the target.
		document.addEventListener( 'dragleave', function ( e ) {
			var card = e.target.closest( '.qps-card--draggable' );
			if ( ! card ) { return; }
			// Only remove if the cursor left the card entirely (not just a child).
			if ( ! card.contains( e.relatedTarget ) ) {
				card.classList.remove( 'qps-card--drag-over' );
			}
		} );

		// dragend — clean up all drag state regardless of outcome.
		document.addEventListener( 'dragend', function () {
			document.querySelectorAll( '.qps-card--dragging, .qps-card--drag-over' ).forEach( function ( el ) {
				el.classList.remove( 'qps-card--dragging', 'qps-card--drag-over' );
			} );
			dragSourceId = null;
		} );

		// drop — fire swap between stored source and current target.
		document.addEventListener( 'drop', function ( e ) {
			var targetCard = e.target.closest( '.qps-card--draggable' );
			if ( ! targetCard ) { return; }
			e.preventDefault();
			targetCard.classList.remove( 'qps-card--drag-over' );

			var sourceId = dragSourceId;
			var targetId = cardPostId( targetCard );

			// Clear drag state before any async work.
			dragSourceId = null;
			document.querySelectorAll( '.qps-card--dragging' ).forEach( function ( el ) {
				el.classList.remove( 'qps-card--dragging' );
			} );

			if ( ! sourceId || ! targetId || sourceId === targetId ) { return; }

			// Show feedback anchored to the source card if we can find it.
			var sourceCard = document.querySelector( '.qps-card[data-post-id="' + sourceId + '"]' );
			var feedback   = ( sourceCard || targetCard ).querySelector( '.qps-card-feedback' );
			callSwapAndReload( sourceId, targetId, feedback );
		} );
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		initMenus();
		initSendToBuffer();
		initResendByService();
		initDeleteBufferPosts();
		initMoveTop();
		initMoveUp();
		initMoveDown();
		initDragDrop();
	} );

} ( window, document ) );
