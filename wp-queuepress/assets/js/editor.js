(function (wp) {
	'use strict';

	var __ = wp.i18n.__;
	var RadioControl   = wp.components.RadioControl;
	var Spinner        = wp.components.Spinner;
	var Button         = wp.components.Button;
	var Modal          = wp.components.Modal;

	// wp.editor.* is the correct namespace since WP 6.6
	// (wp.editPost.* still works but emits deprecation warnings).
	var PluginDocumentSettingPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel)
		|| wp.editPost.PluginDocumentSettingPanel;
	var PluginPrePublishPanel = (wp.editor && wp.editor.PluginPrePublishPanel)
		|| wp.editPost.PluginPrePublishPanel;

	var createElement    = wp.element.createElement;
	var Fragment         = wp.element.Fragment;
	var useState         = wp.element.useState;
	var useEffect        = wp.element.useEffect;
	var useSelect        = wp.data.useSelect;
	var useDispatch      = wp.data.useDispatch;
	var registerPlugin   = wp.plugins.registerPlugin;
	var apiFetch         = wp.apiFetch;

	// ─── Main panel component ─────────────────────────────────────────────────────

	function QueueModePanel() {
		var postId = useSelect(function (select) {
			return select('core/editor').getCurrentPostId();
		}, []);

		var postStatus = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('status');
		}, []);

		var meta = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('meta') || {};
		}, []);

		// Site timezone string shown in the confirmation panel.
		var siteTz = useSelect(function (select) {
			var site = select('core').getSite() || {};
			return site.timezone_string
				|| (site.gmt_offset !== undefined
					? 'UTC' + (site.gmt_offset >= 0 ? '+' : '') + site.gmt_offset
					: 'UTC');
		}, []);

		var editorDispatch   = useDispatch('core/editor');
		var noticesDispatch  = useDispatch('core/notices');
		var editPost         = editorDispatch.editPost;
		var lockAutosaving   = editorDispatch.lockPostAutosaving;
		var unlockAutosaving = editorDispatch.unlockPostAutosaving;
		var savePost         = editorDispatch.savePost;
		var LOCK_KEY         = 'wp-queuepress';

		var storedMode  = (meta && meta._wp_queuepress_queue_mode) ? meta._wp_queuepress_queue_mode : '';
		var initialMode = storedMode || 'none';

		var modeRes     = useState(initialMode);
		var mode        = modeRes[0];
		var setMode     = modeRes[1];

		var loadRes     = useState(false);
		var isLoading   = loadRes[0];
		var setIsLoading = loadRes[1];

		// slotInfo: { date, time, iso } – estimated slot for the new post
		var slotRes     = useState(null);
		var slotInfo    = slotRes[0];
		var setSlotInfo = slotRes[1];

		var errRes        = useState('');
		var errorMessage  = errRes[0];
		var setErrorMessage = errRes[1];

		// addFirstPreview: { new_post: {...}, affected: [...] } | null
		// Populated when mode === 'add_first' and the user clicks Schedule.
		var previewRes    = useState(null);
		var addFirstPreview = previewRes[0];
		var setAddFirstPreview = previewRes[1];

		// showModal: true while the AddFirst confirmation modal is open.
		var modalRes    = useState(false);
		var showModal   = modalRes[0];
		var setShowModal = modalRes[1];

		// Autosave lock: hold while a queue mode is active so Gutenberg cannot
		// silently auto-save the post to "future" before the user confirms.
		useEffect(function () {
			if ('none' !== mode) {
				lockAutosaving(LOCK_KEY);
			} else {
				unlockAutosaving(LOCK_KEY);
			}
			return function () { unlockAutosaving(LOCK_KEY); };
		}, [mode]);

		// Automatically recalculate slot when opening a draft with a pre-existing queue mode
		useEffect(function () {
			if (postId && 'none' !== mode && null === slotInfo && !isLoading) {
				setIsLoading(true);
				apiFetch({
					path: '/wp-queuepress/v1/posts/' + postId + '/next-slot?mode=' + mode,
					method: 'GET'
				}).then(function (response) {
					setSlotInfo({ date: response.date, time: response.time, iso: response.iso });
					editPost({ date: response.iso });
					noticesDispatch.createSuccessNotice(
						__('Queue preview loaded.', 'wp-queuepress'),
						{ type: 'snackbar' }
					);
				}).catch(function (error) {
					setMode('none');
					setQueueMeta('none');
					setSlotInfo(null);
					var msg = error.message || __('No free publishing slots are currently available.', 'wp-queuepress');
					setErrorMessage(msg);
					clearQueueMode(postId);
					noticesDispatch.createErrorNotice(msg, { type: 'snackbar' });
				}).finally(function () {
					setIsLoading(false);
				});
			}
		}, [postId, mode, slotInfo, isLoading]);

		// ── Helpers ───────────────────────────────────────────────────────────────

		function formatSlotLabel(date, time) {
			if (!date || !time) { return ''; }
			var d = new Date(date + 'T' + time + ':00');
			return d.toLocaleDateString(undefined, {
				weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
			}) + ' at ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		}

		function setQueueMeta(nextMode) {
			var nextMeta = Object.assign({}, meta);
			if ('none' === nextMode) {
				delete nextMeta._wp_queuepress_queue_mode;
			} else {
				nextMeta._wp_queuepress_queue_mode = nextMode;
			}
			editPost({ meta: nextMeta });
		}

		function clearQueueMode(id) {
			return apiFetch({
				path: '/wp-queuepress/v1/posts/' + id + '/queue-mode',
				method: 'DELETE'
			});
		}

		// ── Mode change ───────────────────────────────────────────────────────────

		function onModeChange(nextMode) {
			if (!postId || isLoading) { return; }

			setMode(nextMode);
			setSlotInfo(null);
			setAddFirstPreview(null);
			setShowModal(false);
			setErrorMessage('');
			setQueueMeta(nextMode);

			if ('none' === nextMode) {
				setIsLoading(true);
				editPost({ date: null, status: 'draft' });
				clearQueueMode(postId)
					.catch(function () {})
					.finally(function () { setIsLoading(false); });
				return;
			}

			setIsLoading(true);

			apiFetch({
				path: '/wp-queuepress/v1/posts/' + postId + '/next-slot?mode=' + nextMode,
				method: 'GET'
			}).then(function (response) {
				setSlotInfo({ date: response.date, time: response.time, iso: response.iso });
				editPost({ date: response.iso });
				noticesDispatch.createSuccessNotice(
					__('Queue preview updated.', 'wp-queuepress'),
					{ type: 'snackbar' }
				);
			}).catch(function (error) {
				setMode('none');
				setQueueMeta('none');
				setSlotInfo(null);
				var msg = error.message || __('No free publishing slots are currently available.', 'wp-queuepress');
				setErrorMessage(msg);
				clearQueueMode(postId);
				noticesDispatch.createErrorNotice(msg, { type: 'snackbar' });
			}).finally(function () {
				setIsLoading(false);
			});
		}

		// ── AddFirst: Schedule button handler ─────────────────────────────────────
		// Called when user clicks "Schedule" in Gutenberg while mode is add_first.
		// Fetches the full preview from the server, then shows the confirmation modal.
		// The actual save is deferred until the user clicks "Confirm" in the modal.

		function onScheduleAddFirst() {
			setIsLoading(true);
			apiFetch({
				path: '/wp-queuepress/v1/posts/' + postId + '/add-first-preview',
				method: 'GET'
			}).then(function (preview) {
				setAddFirstPreview(preview);
				setShowModal(true);
			}).catch(function (error) {
				var msg = error.message || __('Could not load queue preview.', 'wp-queuepress');
				setErrorMessage(msg);
				noticesDispatch.createErrorNotice(msg, { type: 'snackbar' });
			}).finally(function () {
				setIsLoading(false);
			});
		}

		function onModalConfirm() {
			setShowModal(false);
			// Unlock autosave so WordPress can proceed with the full save.
			unlockAutosaving(LOCK_KEY);
			savePost();
			// Re-lock is not needed — once saved the post is scheduled and the
			// mode clears from meta, so the effect will call unlockAutosaving.
		}

		function onModalCancel() {
			setShowModal(false);
			// Keep the autosave lock in place; the user changed their mind.
		}

		// ── Early exit ────────────────────────────────────────────────────────────

		if (!postId || postStatus === 'publish') { return null; }

		// ── Derived display values ────────────────────────────────────────────────

		var isQueueMode = ('add_to_queue' === mode || 'add_first' === mode);
		var slotLabel   = slotInfo ? formatSlotLabel(slotInfo.date, slotInfo.time) : '';

		var helpText = __('Choose how QueuePress should prepare this post.', 'wp-queuepress');
		if (isLoading) {
			helpText = __('Calculating preview...', 'wp-queuepress');
		} else if (errorMessage) {
			helpText = errorMessage;
		} else if (slotLabel) {
			var prefix = 'add_first' === mode
				? __('Estimated first slot: ', 'wp-queuepress')
				: __('Estimated slot: ', 'wp-queuepress');
			helpText = prefix + slotLabel;
		}

		// ── Pre-publish panel (add_to_queue only) ─────────────────────────────────
		// For add_to_queue we use PluginPrePublishPanel — shows in Gutenberg's
		// native pre-publish checklist when the user clicks Schedule.
		// For add_first we show our own modal (see below) so we intercept
		// the Schedule action via a custom button in the panel instead.
		var prePublishPanel = ('add_to_queue' === mode && slotInfo)
			? createElement(
				PluginPrePublishPanel,
				{
					name: 'wp-queuepress-prepublish',
					title: __('QueuePress Scheduling', 'wp-queuepress'),
					initialOpen: true
				},
				createElement('p', { style: { margin: '0 0 4px' } },
					__('This post will be scheduled at:', 'wp-queuepress')
				),
				createElement('p', { style: { fontWeight: 600, margin: '0 0 8px' } }, slotLabel),
				createElement('p', { style: { color: '#757575', fontSize: '12px', margin: 0 } },
					__('Site timezone: ', 'wp-queuepress') + siteTz
				)
			)
			: null;

		// ── AddFirst confirmation modal ───────────────────────────────────────────
		// Shows: new post slot + list of all affected (shifted) scheduled posts.
		var confirmModal = (showModal && addFirstPreview)
			? createElement(
				Modal,
				{
					title: __('Confirm Queue Rebuild (Add First)', 'wp-queuepress'),
					onRequestClose: onModalCancel,
					size: 'medium'
				},
				// New post row
				createElement('p', { style: { marginBottom: '4px', fontWeight: 600 } },
					__('Your post will be scheduled at:', 'wp-queuepress')
				),
				createElement('p', { style: { marginBottom: '16px' } },
					addFirstPreview.new_post.new_date_label
				),
				// Affected posts list
				addFirstPreview.affected.length > 0
					? createElement(
						Fragment,
						null,
						createElement('p', { style: { fontWeight: 600, marginBottom: '4px' } },
							__('The following scheduled posts will be shifted:', 'wp-queuepress')
						),
						createElement(
							'table',
							{ style: { width: '100%', borderCollapse: 'collapse', marginBottom: '16px', fontSize: '13px' } },
							createElement('thead', null,
								createElement('tr', null,
									createElement('th', { style: { textAlign: 'left', paddingBottom: '4px', borderBottom: '1px solid #ddd' } }, __('Post', 'wp-queuepress')),
									createElement('th', { style: { textAlign: 'left', paddingBottom: '4px', borderBottom: '1px solid #ddd' } }, __('Current date', 'wp-queuepress')),
									createElement('th', { style: { textAlign: 'left', paddingBottom: '4px', borderBottom: '1px solid #ddd' } }, __('New date', 'wp-queuepress'))
								)
							),
							createElement(
								'tbody',
								null,
								addFirstPreview.affected.map(function (item) {
									return createElement('tr', { key: item.id },
										createElement('td', { style: { padding: '4px 8px 4px 0' } }, item.title || '#' + item.id),
										createElement('td', { style: { padding: '4px 8px 4px 0', color: '#757575' } }, item.old_date_label),
										createElement('td', { style: { padding: '4px 0', color: '#1e1e1e' } }, item.new_date_label)
									);
								})
							)
						)
					)
					: createElement('p', { style: { color: '#757575', marginBottom: '16px' } },
						__('No other scheduled posts will be affected.', 'wp-queuepress')
					),
				// Timezone note
				createElement('p', { style: { color: '#757575', fontSize: '12px', marginBottom: '20px' } },
					__('Site timezone: ', 'wp-queuepress') + siteTz
				),
				// Action buttons
				createElement(
					'div',
					{ style: { display: 'flex', gap: '8px', justifyContent: 'flex-end' } },
					createElement(Button, { variant: 'primary', onClick: onModalConfirm },
						__('Confirm & Schedule', 'wp-queuepress')
					),
					createElement(Button, { variant: 'tertiary', onClick: onModalCancel },
						__('Cancel', 'wp-queuepress')
					)
				)
			)
			: null;

		// ── AddFirst: custom "Schedule" button in pre-publish panel ───────────────
		// When mode is add_first, we render a PluginPrePublishPanel with our own
		// button. The user clicks it → we fetch the preview → show the modal.
		// The native Gutenberg "Schedule" button is still present but the autosave
		// lock prevents it from completing the save until we unlock it.
		var addFirstPrePublishPanel = ('add_first' === mode && slotInfo)
			? createElement(
				PluginPrePublishPanel,
				{
					name: 'wp-queuepress-addfirst-prepublish',
					title: __('QueuePress Add First', 'wp-queuepress'),
					initialOpen: true
				},
				createElement('p', { style: { margin: '0 0 4px' } },
					__('This post will go first in the queue. All scheduled posts will shift forward.', 'wp-queuepress')
				),
				createElement('p', { style: { fontWeight: 600, margin: '0 0 8px' } },
					__('Estimated first slot: ', 'wp-queuepress') + slotLabel
				),
				isLoading
					? createElement(Spinner, null)
					: createElement(Button,
						{ variant: 'primary', onClick: onScheduleAddFirst, style: { marginTop: '4px' } },
						__('Review & Confirm Queue Rebuild', 'wp-queuepress')
					)
			)
			: null;

		// ── Render ────────────────────────────────────────────────────────────────

		return createElement(
			Fragment,
			null,
			createElement(
				PluginDocumentSettingPanel,
				{
					name: 'wp-queuepress-panel',
					title: __('QueuePress', 'wp-queuepress'),
					icon: 'calendar-alt'
				},
				createElement(RadioControl, {
					label: __('Queue mode', 'wp-queuepress'),
					selected: mode,
					disabled: isLoading,
					options: [
						{ label: __('None',         'wp-queuepress'), value: 'none'        },
						{ label: __('Add to Queue', 'wp-queuepress'), value: 'add_to_queue' },
						{ label: __('Add First',    'wp-queuepress'), value: 'add_first'    }
					],
					onChange: onModeChange
				}),
				isLoading ? createElement(Spinner, null) : null,
				createElement(
					'p',
					{
						className: errorMessage
							? 'components-base-control__help is-error'
							: 'components-base-control__help'
					},
					helpText
				)
			),
			prePublishPanel,
			addFirstPrePublishPanel,
			confirmModal
		);
	}

	registerPlugin('wp-queuepress', {
		render: QueueModePanel
	});

}(window.wp));
