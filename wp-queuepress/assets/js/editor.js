(function (wp) {
	'use strict';

	var __ = wp.i18n.__;
	var ToggleControl = wp.components.ToggleControl;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var createElement = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var registerPlugin = wp.plugins.registerPlugin;
	var apiFetch = wp.apiFetch;

	/**
	 * AddToQueue toggle for Gutenberg editor — Bunny Queue Press 1.2.1.
	 *
	 * Model:
	 *   - Finds the next free configured slot via REST GET /next-slot.
	 *   - Applies it with editPost({ date: isoString }) only — no status change.
	 *   - Gutenberg's own logic converts the Publish button to Schedule when
	 *     the editor date is in the future. No interception needed.
	 *   - Autosave is disabled while the toggle is on to prevent autosave from
	 *     silently committing the future date as a scheduled post.
	 *   - Autosave is re-enabled the moment the toggle is turned off.
	 *
	 * What this component never does:
	 *   - Never calls editPost({ status: ... })
	 *   - Never calls wp_update_post (server-side)
	 *   - Never locks or intercepts the Publish / Schedule button
	 *   - Never modifies post_date except through editPost({ date })
	 */
	function AddToQueuePanel() {
		// ── Selectors (top-level only — no hooks inside callbacks) ────────────────

		var postId = useSelect(function (select) {
			return select('core/editor').getCurrentPostId();
		}, []);

		var postStatus = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('status');
		}, []);

		// BUG 2 FIX — source of truth for initial toggle state on mount/reload.
		// Already populated by Gutenberg from the server-provided post data, so
		// it correctly reflects a previously queued draft on page reload.
		var postDate = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('date') || '';
		}, []);

		var editorDispatch   = useDispatch('core/editor');
		var editPost         = editorDispatch.editPost;
		var lockAutosaving   = editorDispatch.lockPostAutosaving;
		var unlockAutosaving = editorDispatch.unlockPostAutosaving;
		var noticesDispatch  = useDispatch('core/notices');

		// ── State ─────────────────────────────────────────────────────────────────

		// BUG 2 FIX — initialise from derived post state so the toggle survives
		// page reloads. A draft with a future post_date is the only persistent
		// evidence that AddToQueue was previously enabled. No meta needed.
		// Selectors are declared above so their values are available here.
		var isQueuedDraft = postStatus === 'draft' && !!postDate && new Date(postDate) > new Date();

		var enabledState = useState(isQueuedDraft);
		var isEnabled    = enabledState[0];
		var setIsEnabled = enabledState[1];

		var fetchingState = useState(false);
		var isFetching    = fetchingState[0];
		var setIsFetching = fetchingState[1];

		// Slot string shown in help text, e.g. "Tuesday Jun 10 at 09:00".
		var slotState    = useState('');
		var slotLabel    = slotState[0];
		var setSlotLabel = slotState[1];

		// ── Autosave lock ─────────────────────────────────────────────────────────
		//
		// When AddToQueue is on, autosave is locked so Gutenberg cannot silently
		// commit the future date and turn the draft into a scheduled post without
		// the user explicitly clicking Schedule.
		//
		// lockPostAutosaving / unlockPostAutosaving are the correct APIs for this:
		// they suppress autosave only, leaving manual Save Draft and the Schedule
		// button entirely unaffected.
		//
		// The cleanup function always unlocks so the lock is never left stranded
		// if the component unmounts (e.g. the user navigates away mid-session).

		var AUTOSAVE_LOCK = 'wp-queuepress';

		useEffect(function () {
			if (isEnabled) {
				lockAutosaving(AUTOSAVE_LOCK);
			} else {
				unlockAutosaving(AUTOSAVE_LOCK);
			}

			return function () {
				unlockAutosaving(AUTOSAVE_LOCK);
			};
		}, [isEnabled]);

		// ── Helpers ───────────────────────────────────────────────────────────────

		/**
		 * Formats a Y-m-d + H:i pair into a human-readable label.
		 *
		 * @param {string} date  "Y-m-d"
		 * @param {string} time  "H:i"
		 * @returns {string}
		 */
		function formatSlot(date, time) {
			// "T" separator makes the string valid ISO 8601 across all browsers.
			var d = new Date(date + 'T' + time + ':00');
			return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
				+ ' at '
				+ d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		}

		// ── Event handler ─────────────────────────────────────────────────────────

		/**
		 * Handles toggle changes.
		 *
		 * Enable path (optimistic):
		 *   1. Mark as enabled immediately so the toggle visually checks.
		 *   2. GET /next-slot — read-only, no server-side write.
		 *   3. On success: apply the slot date via editPost({ date }).
		 *      Gutenberg sees a future date and changes "Publish" → "Schedule".
		 *   4. On failure: roll back the toggle state and show an error notice.
		 *
		 * Disable path (immediate):
		 *   1. Clear the queue date via editPost({ date: '' }).
		 *      Gutenberg reverts "Schedule" → "Publish" / "Save Draft".
		 *   2. Mark as disabled — no API call needed.
		 *
		 * Rules of Hooks: no hook calls inside this function.
		 *
		 * @param {boolean} checked New toggle state.
		 */
		function onToggleChange(checked) {
			if (!postId || isFetching) {
				return;
			}

			if (checked) {
				// Optimistic enable: check the toggle now, fill the slot in asynchronously.
				setIsEnabled(true);
				setIsFetching(true);
				setSlotLabel('');

				apiFetch({
					path: '/wp-queuepress/v1/posts/' + postId + '/next-slot',
					method: 'GET'
				}).then(function (response) {
					// Apply the slot date to the editor — native Gutenberg scheduling.
					// DO NOT set status. Gutenberg handles the "Publish" → "Schedule"
					// button text change on its own when post.date is in the future.
					editPost({ date: response.iso });
					setSlotLabel(formatSlot(response.date, response.time));

					noticesDispatch.createSuccessNotice(
						__('Next available slot reserved.', 'wp-queuepress'),
						{ type: 'snackbar' }
					);
				}).catch(function (error) {
					// Roll back toggle. Use current time so the editor returns to a
					// normal draft state — not the future slot, not '' (REST rejects it).
					setIsEnabled(false);
					setSlotLabel('');
					editPost({ date: new Date().toISOString() });

					noticesDispatch.createErrorNotice(
						error.message || __('No free publishing slots are currently available.', 'wp-queuepress'),
						{ type: 'snackbar' }
					);
				}).finally(function () {
					setIsFetching(false);
				});

			} else {
				// Disable: set date to now so the post returns to a normal draft/publish
				// state. This clears the future slot from the editor and the saved post.
				// - new Date().toISOString() is always a valid REST date-time string
				// - a past/present date makes Gutenberg show Publish, not Schedule
				// - on Save Draft this current date is written to post_date, so
				//   isQueuedDraft evaluates to false on next reload — toggle stays OFF
				setIsEnabled(false);
				setSlotLabel('');
				editPost({ date: new Date().toISOString() });
			}
		}

		// ── Render guard ──────────────────────────────────────────────────────────

		// Hide the panel for posts that are already published.
		// Future (scheduled) posts and drafts always show the panel.
		if (!postId || postStatus === 'publish') {
			return null;
		}

		// ── Help text ─────────────────────────────────────────────────────────────

		var helpText;
		if (isFetching) {
			helpText = __('Finding next available slot\u2026', 'wp-queuepress');
		} else if (isEnabled && slotLabel) {
			helpText = __('Slot: ', 'wp-queuepress') + slotLabel
				+ ' \u2014 ' + __('Click Schedule to confirm.', 'wp-queuepress');
		} else if (isEnabled) {
			helpText = __('Slot reserved. Click Schedule to publish at that time.', 'wp-queuepress');
		} else {
			helpText = __('Reserve the next available slot. You must still click Schedule to publish.', 'wp-queuepress');
		}

		// ── Render ────────────────────────────────────────────────────────────────

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'wp-queuepress-panel',
				title: __('Add to Queue', 'wp-queuepress'),
				icon: 'calendar-alt'
			},
			createElement(
				ToggleControl,
				{
					label: __('Reserve next available slot', 'wp-queuepress'),
					help: helpText,
					checked: isEnabled,
					onChange: onToggleChange,
					disabled: isFetching
				}
			)
		);
	}

	registerPlugin('wp-queuepress', {
		render: AddToQueuePanel
	});
}(window.wp));
