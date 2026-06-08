/**
 * Buffer admin — sidebar navigation + AJAX autosave.
 *
 * Responsibilities:
 *   1. Sidebar tab switching (vertical profile navigation).
 *   2. Autosave: on any field change inside a channel form, serializes the
 *      full form and sends it to wp_ajax_qps_buffer_autosave_channel.
 *   3. Visual feedback: "Saving…" / "Saved" / "Error" per panel.
 *
 * No external dependencies. ES5-compatible for broad WP environment support.
 *
 * Autosave strategy:
 *   - Debounced 400ms after last change to avoid rapid successive saves.
 *   - Full form serialization — Channel_Config::save() receives the complete
 *     ch[] array, identical to the classic POST handler.
 *   - On 403 response (expired nonce), shows inline reload prompt.
 *   - The classic <button type="submit"> remains visible only when JS is active
 *     so it acts as a manual fallback without requiring page-noscript logic.
 *
 * @package QueuePostScheduler
 */
( function ( window, document ) {
	'use strict';

	var ajaxUrl = window.qpsBufferAdmin && window.qpsBufferAdmin.ajaxUrl
		? window.qpsBufferAdmin.ajaxUrl
		: '/wp-admin/admin-ajax.php';

	// -------------------------------------------------------------------------
	// Sidebar navigation
	// -------------------------------------------------------------------------

	function initSidebar() {
		var buttons = document.querySelectorAll( '.qps-sidebar-item' );
		if ( ! buttons.length ) { return; }

		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panelId = btn.getAttribute( 'data-panel' );
				if ( ! panelId ) { return; }

				// Deactivate all.
				document.querySelectorAll( '.qps-sidebar-item' ).forEach( function ( b ) {
					b.classList.remove( 'qps-sidebar-item--active' );
					b.setAttribute( 'aria-selected', 'false' );
				} );
				document.querySelectorAll( '.qps-channel-panel' ).forEach( function ( p ) {
					p.classList.remove( 'qps-channel-panel--active' );
				} );

				// Activate selected.
				btn.classList.add( 'qps-sidebar-item--active' );
				btn.setAttribute( 'aria-selected', 'true' );
				var panel = document.getElementById( panelId );
				if ( panel ) {
					panel.classList.add( 'qps-channel-panel--active' );
				}
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Autosave
	// -------------------------------------------------------------------------

	var saveTimers = {};

	/**
	 * Shows a feedback message in the panel's status area.
	 *
	 * @param {string} panelId
	 * @param {string} state   'saving' | 'saved' | 'error' | 'session'
	 */
	function showStatus( panelId, state ) {
		var el = document.querySelector( '.qps-autosave-status[data-panel="' + panelId + '"]' );
		if ( ! el ) { return; }

		var labels = {
			saving:  window.qpsBufferAdmin.i18n.saving  || 'Saving\u2026',
			saved:   window.qpsBufferAdmin.i18n.saved   || 'Saved',
			error:   window.qpsBufferAdmin.i18n.error   || 'Error saving',
			session: window.qpsBufferAdmin.i18n.session || 'Session expired. Please reload.'
		};

		el.textContent  = labels[ state ] || '';
		el.className    = 'qps-autosave-status qps-autosave-status--' + state;
		el.setAttribute( 'data-panel', panelId );

		if ( 'saved' === state ) {
			window.setTimeout( function () {
				el.textContent = '';
				el.className   = 'qps-autosave-status';
				el.setAttribute( 'data-panel', panelId );
			}, 2500 );
		}
	}

	/**
	 * Serializes a form into a plain object suitable for FormData.
	 * Handles checkboxes correctly (unchecked = absent key).
	 *
	 * @param  {HTMLFormElement} form
	 * @return {FormData}
	 */
	function buildFormData( form ) {
		var fd = new FormData();

		// AJAX action — overrides the classic POST action hidden input.
		fd.append( 'action', 'qps_buffer_autosave_channel' );

		// AJAX nonce.
		var nonceEl = form.querySelector( '.qps-ajax-nonce' );
		if ( nonceEl ) {
			fd.append( '_ajax_nonce', nonceEl.value );
		}

		// channel_id and service.
		fd.append( 'channel_id', form.getAttribute( 'data-channel-id' ) || '' );
		fd.append( 'service',    form.getAttribute( 'data-service' )    || '' );

		// All ch[] inputs — iterate named elements.
		var els = form.elements;
		for ( var i = 0; i < els.length; i++ ) {
			var el = els[ i ];
			if ( ! el.name || el.name.indexOf( 'ch[' ) !== 0 ) { continue; }
			if ( 'checkbox' === el.type ) {
				if ( el.checked ) {
					fd.append( el.name, el.value );
				}
				// Unchecked checkbox: do not append — handler treats absence as false.
			} else {
				fd.append( el.name, el.value );
			}
		}

		return fd;
	}

	/**
	 * Sends the full channel form via fetch() to the AJAX autosave endpoint.
	 *
	 * @param {HTMLFormElement} form
	 * @param {string}          panelId
	 */
	function doSave( form, panelId ) {
		showStatus( panelId, 'saving' );

		fetch( ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        buildFormData( form )
		} )
		.then( function ( response ) {
			if ( 403 === response.status ) {
				showStatus( panelId, 'session' );
				return null;
			}
			return response.json();
		} )
		.then( function ( data ) {
			if ( null === data ) { return; }
			if ( data && data.success ) {
				showStatus( panelId, 'saved' );
				// Sync the sidebar status dot for the enabled toggle.
				updateSidebarStatus( form );
			} else {
				showStatus( panelId, 'error' );
			}
		} )
		.catch( function () {
			showStatus( panelId, 'error' );
		} );
	}

	/**
	 * Reads the enabled checkbox state and updates the matching sidebar dot.
	 *
	 * @param {HTMLFormElement} form
	 */
	function updateSidebarStatus( form ) {
		var panelId  = form.getAttribute( 'data-panel' );
		var checkbox = form.querySelector( 'input[name="ch[enabled]"]' );
		if ( ! panelId || ! checkbox ) { return; }

		var btn = document.querySelector( '.qps-sidebar-item[data-panel="' + panelId + '"]' );
		if ( ! btn ) { return; }

		var dot = btn.querySelector( '.qps-sidebar-status' );
		if ( ! dot ) { return; }

		dot.classList.toggle( 'qps-sidebar-status--on',  checkbox.checked );
		dot.classList.toggle( 'qps-sidebar-status--off', ! checkbox.checked );
		dot.setAttribute( 'aria-label', checkbox.checked
			? ( window.qpsBufferAdmin.i18n.enabled  || 'Enabled' )
			: ( window.qpsBufferAdmin.i18n.disabled || 'Disabled' )
		);
	}

	/**
	 * Attaches change listeners to all channel forms.
	 * Debounces saves by 400ms.
	 */
	function initAutosave() {
		var forms = document.querySelectorAll( '.qps-channel-form' );
		if ( ! forms.length ) { return; }

		// Hide the JS-off fallback submit button when JS is active.
		document.querySelectorAll( '.qps-form-fallback' ).forEach( function ( el ) {
			el.style.display = 'none';
		} );

		forms.forEach( function ( form ) {
			var panelId = form.getAttribute( 'data-panel' );

			form.addEventListener( 'change', function () {
				if ( saveTimers[ panelId ] ) {
					window.clearTimeout( saveTimers[ panelId ] );
				}
				saveTimers[ panelId ] = window.setTimeout( function () {
					doSave( form, panelId );
				}, 400 );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		initSidebar();
		initAutosave();
	} );

} ( window, document ) );
