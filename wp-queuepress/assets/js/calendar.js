(function () {
	'use strict';

	function closest(element, selector) {
		while (element && element.nodeType === 1) {
			if (element.matches(selector)) {
				return element;
			}

			element = element.parentElement;
		}

		return null;
	}

	function getWeekStart() {
		var wrap = document.querySelector('[data-wp-queuepress-week]');

		return wrap ? wrap.getAttribute('data-wp-queuepress-week') : '';
	}

	function getGlobalManager() {
		return document.querySelector('.qps-global-slot-manager');
	}

	// Build a minimal weekday list used by the client for scope resolution.
	var WEEKDAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

	// Local staged weekly slots state. Populated from DOM on init.
	var weeklySlots = {};
	var isDirty = false;
	var saveButton = null;
	var originalSlots = {};

	function deepClone(obj) {
		try {
			return JSON.parse(JSON.stringify(obj));
		} catch (e) {
			var out = {};
			Object.keys(obj).forEach(function(k){ out[k] = Array.isArray(obj[k]) ? obj[k].slice() : obj[k]; });
			return out;
		}
	}

	function setMessage(manager, message, isError) {
		var messageNode = manager.querySelector('.qps-slot-message');

		if (!messageNode) {
			return;
		}

		messageNode.textContent = message || '';
		messageNode.classList.toggle('is-error', !!isError);
		messageNode.classList.toggle('is-success', !!message && !isError);
	}

	function buildWeeklySlotsFromDOM() {
		WEEKDAYS.forEach(function(day) { weeklySlots[day] = []; });

		document.querySelectorAll('.qps-slot-manager[data-day]').forEach(function(manager) {
			var day = manager.getAttribute('data-day');
			var list = manager.querySelectorAll('.qps-slot-list .qps-slot');
			weeklySlots[day] = [];
			list.forEach(function(li){
				var time = li.getAttribute('data-time');
				if (time) {
					weeklySlots[day].push(time);
				}
			});
		});
	}

	// Compare current staged slots with original baseline.
	function slotsAreEqual(a, b) {
		try {
			return JSON.stringify(a) === JSON.stringify(b);
		} catch (e) {
			return false;
		}
	}

	function renderDaysFromState() {
		Object.keys(weeklySlots).forEach(function(day) {
			var manager = document.querySelector('.qps-slot-manager[data-day="' + day + '"]');
			if (!manager) return;
			var wrap = manager.querySelector('.qps-slot-list-wrap');
			if (!wrap) return;
			if (!weeklySlots[day] || weeklySlots[day].length === 0) {
				wrap.innerHTML = '<div class="qps-empty"><span>' + (window.wpQueuePressSlots && window.wpQueuePressSlots.messages && window.wpQueuePressSlots.messages.emptyText ? window.wpQueuePressSlots.messages.emptyText : 'No configured slots.') + '</span></div>';
				return;
			}

			var html = '<ul class="qps-slot-list">';
			weeklySlots[day].forEach(function(time) {
				html += '<li class="qps-slot is-free" data-time="' + time + '">';
				html += '<div class="qps-slot-main"><time>' + time + '</time></div>';
				html += '<button type="button" class="button-link-delete qps-slot-delete" data-day="' + day + '" data-time="' + time + '">' + (window.wpQueuePressSlots && window.wpQueuePressSlots.messages && window.wpQueuePressSlots.messages.deleteText ? window.wpQueuePressSlots.messages.deleteText : 'Delete') + '</button>';
				html += '</li>';
			});
			html += '</ul>';
			wrap.innerHTML = html;
		});
	}

	function markDirty(state) {
		isDirty = !!state;
		if (saveButton) {
			saveButton.disabled = !isDirty;
		}
	}

	function updateDays(days) {
		Object.keys(days || {}).forEach(function (day) {
			var manager = document.querySelector('.qps-slot-manager[data-day="' + day + '"]');
			var list = manager ? manager.querySelector('.qps-slot-list-wrap') : null;

			if (list) {
				list.innerHTML = days[day].html;
			}
		});
	}

	function postSlotAction(action, payload) {
		var body = new window.FormData();

		body.append('action', action);
		body.append('nonce', window.wpQueuePressSlots.nonce);
		body.append('week_start', getWeekStart());

		Object.keys(payload).forEach(function (key) {
			body.append(key, payload[key]);
		});

		return window.fetch(window.wpQueuePressSlots.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok || !json.success) {
					throw new Error(json.data && json.data.message ? json.data.message : response.statusText);
				}

				return json.data;
			});
		});
	}

	/**
	 * Maps Schedule For value to scope and determines if specific day selector should show.
	 *
	 * @param {string} scheduleFor The Schedule For dropdown value.
	 * @returns {{scope: string, isSpecificDay: boolean}}
	 */
	function parseScheduleFor(scheduleFor) {
		var scopeMap = {
			'specific-day': 'day',
			'weekdays': 'weekdays',
			'weekends': 'weekends',
			'everyday': 'everyday'
		};

		return {
			scope: scopeMap[scheduleFor] || 'day',
			isSpecificDay: scheduleFor === 'specific-day'
		};
	}

	/**
	 * Toggles visibility of specific day selector based on Schedule For value.
	 *
	 * @param {HTMLElement} manager The slot manager container.
	 */
	function updateSpecificDayVisibility(manager) {
		var scheduleFor = manager.querySelector('.qps-slot-schedule-for');
		var specificDayWrap = manager.querySelector('.qps-specific-day-wrap');

		if (!scheduleFor || !specificDayWrap) {
			return;
		}

		var parsed = parseScheduleFor(scheduleFor.value);
		specificDayWrap.hidden = !parsed.isSpecificDay;
	}

	function init() {
		document.addEventListener('click', function (event) {
			var toggle = closest(event.target, '.qps-add-slot-toggle');
			var cancel = closest(event.target, '.qps-slot-cancel');
			var save = closest(event.target, '.qps-slot-save');
			var remove = closest(event.target, '.qps-slot-delete');

			if (toggle) {
				var toggleManager = closest(toggle, '.qps-slot-manager');
				var form = toggleManager.querySelector('.qps-slot-form');
				form.hidden = !form.hidden;
				setMessage(toggleManager, '', false);
				updateSpecificDayVisibility(toggleManager);
				return;
			}

			if (cancel) {
				var cancelManager = closest(cancel, '.qps-slot-manager');
				cancelManager.querySelector('.qps-slot-form').hidden = true;
				setMessage(cancelManager, '', false);
				return;
			}

			if (save) {
				var saveManager = closest(save, '.qps-slot-manager');
				var time = saveManager.querySelector('.qps-slot-time').value;
				var scheduleFor = saveManager.querySelector('.qps-slot-schedule-for').value;
				var day = saveManager.querySelector('.qps-slot-day').value;
				var parsed = parseScheduleFor(scheduleFor);

				if (!time) {
					setMessage(saveManager, window.wpQueuePressSlots.messages.invalidTime, true);
					return;
				}

				save.disabled = true;
				setMessage(saveManager, window.wpQueuePressSlots.messages.saving, false);

				// Local-only mutation: resolve target days and update weeklySlots.
				var targets = [];
				if (parsed.scope === 'day') {
					targets = [day];
				} else if (parsed.scope === 'weekdays') {
					targets = ['monday','tuesday','wednesday','thursday','friday'];
				} else if (parsed.scope === 'weekends') {
					targets = ['saturday','sunday'];
				} else if (parsed.scope === 'everyday') {
					targets = WEEKDAYS.slice();
				}

				targets.forEach(function(targetDay) {
					weeklySlots[targetDay] = weeklySlots[targetDay] || [];
					if (-1 === weeklySlots[targetDay].indexOf(time)) {
						weeklySlots[targetDay].push(time);
						weeklySlots[targetDay].sort();
					}
				});

				renderDaysFromState();
				setMessage(saveManager, window.wpQueuePressSlots.messages.saving, false);
				saveManager.querySelector('.qps-slot-form').hidden = true;
				saveManager.querySelector('.qps-slot-time').value = '';
				markDirty(true);
				save.disabled = false;
			}

			if (remove) {
				var removeManager = closest(remove, '.qps-slot-manager');
				var messageManager = getGlobalManager() || removeManager;

				var day = remove.getAttribute('data-day');
				var time = remove.getAttribute('data-time');

				if (day && time && Array.isArray(weeklySlots[day])) {
					weeklySlots[day] = weeklySlots[day].filter(function(t){ return t !== time; });
					renderDaysFromState();
					markDirty(true);
				}
			}
		});

		// Handle Schedule For dropdown changes for conditional day visibility.
		document.addEventListener('change', function (event) {
			var scheduleFor = closest(event.target, '.qps-slot-schedule-for');

			if (scheduleFor) {
				var manager = closest(scheduleFor, '.qps-slot-manager');
				updateSpecificDayVisibility(manager);
			}
		});
	}

	// Save Changes handler (Save full weeklySlots to server)
	function saveWeeklySlots() {
		if (! isDirty) {
			return Promise.resolve();
		}

		if (!window.wpQueuePressSlots) {
			return Promise.reject(new Error('Missing localization data'));
		}

		var body = new window.FormData();
		body.append('action', 'wp_queuepress_save_weekly_slots');
		body.append('nonce', window.wpQueuePressSlots.nonce);
		body.append('slots', JSON.stringify(weeklySlots));

		return window.fetch(window.wpQueuePressSlots.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function(response){
			return response.json().then(function(json){
				if (!response.ok || !json.success) {
					throw new Error(json.data && json.data.message ? json.data.message : response.statusText);
				}
				return json.data;
			});
		});
	}

	function showRebuildNotice(count) {
		var container = document.querySelector('.qps-rebuild-notice');
		if (!container) return;
		if (!count || count < 1) {
			container.hidden = true;
			return;
		}
		container.hidden = false;
		container.innerHTML = '<div class="qps-rebuild-message">' + count + ' scheduled posts may no longer match the current queue settings.</div>' +
			'<div class="qps-rebuild-actions"><button class="button button-primary qps-preview-rebuild">Preview Rebuild</button> <button class="button qps-rebuild-dismiss">Dismiss</button></div>';

		var preview = container.querySelector('.qps-preview-rebuild');
		var dismiss = container.querySelector('.qps-rebuild-dismiss');

		if (preview) {
			preview.addEventListener('click', function(){
				preview.disabled = true;
				var body = new FormData();
				body.append('action', 'wp_queuepress_preview_rebuild');
				body.append('nonce', window.wpQueuePressSlots.nonce);

				window.fetch(window.wpQueuePressSlots.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				}).then(function(response){
					return response.json().then(function(json){
						if (!response.ok || !json.success) {
							throw new Error(json.data && json.data.message ? json.data.message : response.statusText);
						}
						return json.data;
					});
				}).then(function(data){
					renderRebuildPreview(container, data);
				}).catch(function(err){
					container.querySelector('.qps-rebuild-message').textContent = err.message;
				}).finally(function(){
					preview.disabled = false;
				});
			});
		}

		function renderRebuildPreview(container, data) {
			// Build and open modal overlay
			var existingModal = document.querySelector('.qps-modal-backdrop');
			if (existingModal) { existingModal.remove(); }

			var backdrop = document.createElement('div');
			backdrop.className = 'qps-modal-backdrop';

			var modal = document.createElement('div');
			modal.className = 'qps-modal';

			var header = document.createElement('header');
			header.className = 'qps-modal-header';
			var h2 = document.createElement('h2');
			h2.textContent = 'Queue Rebuild Preview';
			header.appendChild(h2);
			var p = document.createElement('p');
			p.className = 'qps-modal-explain';
			p.textContent = 'These scheduled posts will be reassigned to the next available slots based on the current Calendar Settings.';
			header.appendChild(p);
			modal.appendChild(header);

			var body = document.createElement('div');
			body.className = 'qps-modal-body';

			if (data.conflicts && data.conflicts.length) {
				var warn = document.createElement('div');
				warn.className = 'qps-rebuild-conflicts';
				warn.innerHTML = '<strong>Warning:</strong> ' + data.conflicts.length + ' posts could not be assigned a slot within the search window.';
				body.appendChild(warn);
			}

			var tableWrap = document.createElement('div');
			tableWrap.className = 'qps-modal-table-wrap';
			var table = document.createElement('table');
			table.className = 'qps-rebuild-table';
			var thead = document.createElement('thead');
			thead.innerHTML = '<tr><th>Post</th><th>Current Schedule</th><th>New Schedule</th></tr>';
			table.appendChild(thead);
			var tbody = document.createElement('tbody');

			(data.preview || []).forEach(function(row) {
				var tr = document.createElement('tr');
				var titleCell = document.createElement('td');
				titleCell.textContent = row.post_title || ('Post #' + row.post_id);
				var oldCell = document.createElement('td');
				oldCell.textContent = row.old_date || '';
				var newCell = document.createElement('td');
				newCell.textContent = row.new_date || '';
				tr.appendChild(titleCell);
				tr.appendChild(oldCell);
				tr.appendChild(newCell);
				tbody.appendChild(tr);
			});

			table.appendChild(tbody);
			tableWrap.appendChild(table);
			body.appendChild(tableWrap);
			modal.appendChild(body);

			var footer = document.createElement('footer');
			footer.className = 'qps-modal-footer';
			var cancelBtn = document.createElement('button');
			cancelBtn.className = 'button qps-modal-cancel';
			cancelBtn.textContent = 'Cancel';
			var applyBtn = document.createElement('button');
			applyBtn.className = 'button button-primary qps-modal-apply';
			applyBtn.textContent = 'Apply Rebuild';
			footer.appendChild(cancelBtn);
			footer.appendChild(applyBtn);
			modal.appendChild(footer);

			backdrop.appendChild(modal);
			document.body.appendChild(backdrop);

			function closeModal() { backdrop.remove(); }

			cancelBtn.addEventListener('click', function(){ closeModal(); });
			backdrop.addEventListener('click', function(e){ if (e.target === backdrop) closeModal(); });
			applyBtn.addEventListener('click', function(){
				// Keep modal open, switch to applying state.
				applyBtn.disabled = true;
				cancelBtn.disabled = true;
				footer.querySelectorAll('button').forEach(function(b){ b.disabled = true; });

				// Clear modal body and render progress UI
				var mb = modal.querySelector('.qps-modal-body');
				mb.innerHTML = '';

				var progressWrap = document.createElement('div');
				progressWrap.className = 'qps-apply-progress-wrap';

				var spinner = document.createElement('div');
				spinner.className = 'qps-spinner';
				progressWrap.appendChild(spinner);

				var progressBar = document.createElement('div');
				progressBar.className = 'qps-progress';
				var progressInner = document.createElement('div');
				progressInner.className = 'qps-progress-inner';
				progressBar.appendChild(progressInner);
				progressWrap.appendChild(progressBar);

				var progressText = document.createElement('div');
				progressText.className = 'qps-progress-text';
				progressText.textContent = 'Applying rebuild...';
				progressWrap.appendChild(progressText);

				mb.appendChild(progressWrap);

				// Start fake progress since server responds in one request
				var progress = 0;
				var progressTimer = setInterval(function(){
					progress = Math.min(95, progress + Math.random() * 10);
					progressInner.style.width = progress + '%';
					progressText.textContent = 'Applying rebuild... ' + Math.floor(progress) + '%';
				}, 400);

				var body = new FormData();
				body.append('action', 'wp_queuepress_apply_rebuild');
				body.append('nonce', window.wpQueuePressSlots.nonce);

				window.fetch(window.wpQueuePressSlots.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				}).then(function(response){
					return response.json().then(function(json){
						if (!response.ok || !json.success) {
							throw new Error(json.data && json.data.message ? json.data.message : response.statusText);
						}
						return json.data;
					});
				}).then(function(data){
					clearInterval(progressTimer);
					progressInner.style.width = '100%';
					progressText.textContent = 'Finalizing...';

					// Build results summary UI
					mb.innerHTML = '';
					var resultWrap = document.createElement('div');
					resultWrap.className = 'qps-apply-result-wrap';

					var summary = document.createElement('div');
					summary.className = 'qps-apply-summary';
					summary.innerHTML = '<strong>' + (data.applied || 0) + '</strong> posts updated. <strong>' + (data.conflicts ? data.conflicts.length : 0) + '</strong> conflicts.';
					resultWrap.appendChild(summary);

					if (data.conflicts && data.conflicts.length) {
						var conflictList = document.createElement('div');
						conflictList.className = 'qps-conflict-list';
						var ul = document.createElement('ul');
						data.conflicts.forEach(function(c){
							var li = document.createElement('li');
							li.textContent = (c.post_id ? ('#' + c.post_id + ': ') : '') + (c.message || JSON.stringify(c));
							ul.appendChild(li);
						});
						conflictList.appendChild(ul);
						resultWrap.appendChild(conflictList);
					}

					mb.appendChild(resultWrap);

					// Footer actions: Close and Reload Pipeline
					footer.innerHTML = '';
					var closeBtn = document.createElement('button');
					closeBtn.className = 'button';
					closeBtn.textContent = 'Close';
					var reloadBtn = document.createElement('button');
					reloadBtn.className = 'button button-primary';
					reloadBtn.textContent = 'Go to Pipeline';
					footer.appendChild(closeBtn);
					footer.appendChild(reloadBtn);

					closeBtn.addEventListener('click', function(){ closeModal(); });
					reloadBtn.addEventListener('click', function(){
						if (window.wpQueuePressSlots && window.wpQueuePressSlots.pipelineUrl) {
							window.location.href = window.wpQueuePressSlots.pipelineUrl;
						} else {
							window.location.reload();
						}
					});
				}).catch(function(err){
					clearInterval(progressTimer);
					// Show inline error with retry option
					mb.innerHTML = '';
					var errWrap = document.createElement('div');
					errWrap.className = 'qps-apply-error-wrap';
					var msg = document.createElement('div');
					msg.className = 'qps-apply-error';
					msg.textContent = err.message || 'Failed to apply rebuild.';
					errWrap.appendChild(msg);

					var retry = document.createElement('button');
					retry.className = 'button button-primary qps-apply-retry';
					retry.textContent = 'Retry';
					errWrap.appendChild(retry);

					var cancel = document.createElement('button');
					cancel.className = 'button';
					cancel.textContent = 'Close';
					errWrap.appendChild(cancel);

					mb.appendChild(errWrap);

					retry.addEventListener('click', function(){ applyBtn.click(); });
					cancel.addEventListener('click', function(){ closeModal(); });
				});
			});
		}

		if (dismiss) {
			dismiss.addEventListener('click', function(){
				container.hidden = true;
			});
		}
	}

	function initSaveButton() {
		saveButton = document.querySelector('.qps-save-weekly-slots');
		if (!saveButton) return;
		saveButton.disabled = true;
		saveButton.addEventListener('click', function(){
			saveButton.disabled = true;
			var status = document.querySelector('.qps-save-status');
			if (status) { status.textContent = window.wpQueuePressSlots.messages.saving || 'Saving...'; }
			saveWeeklySlots().then(function(resp){
				var data = resp || {};

				if (data.days) {
					updateDays(data.days);
				}

				// Re-sync client staged state with server-rendered DOM so dirty tracking is accurate.
				buildWeeklySlotsFromDOM();

				// Update original baseline to the just-saved staged state.
				originalSlots = deepClone(weeklySlots);

				// Clear dirty flag and disable save.
				markDirty(false);

				// Defensive message handling: never assume `data.message` exists.
				var msg = (typeof data.message === 'string' && data.message.length) ? data.message : 'Slots saved.';
				if (status) { status.textContent = msg; }

				// Show rebuild notice once after save (if any scheduled posts exist).
				showRebuildNotice(data.scheduled_posts_count || 0);
			}).catch(function(err){
				if (status) { status.textContent = err.message || 'Failed to save slots.'; }
			}).finally(function(){
				// Re-enable or disable save button based on current dirty state.
				if (saveButton) { saveButton.disabled = !isDirty; }
			});
		});
	}

	function initUnsavedWarning() {
		window.addEventListener('beforeunload', function(e){
			if (isDirty) {
				e.preventDefault();
				e.returnValue = '';
			}
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', function(){
			buildWeeklySlotsFromDOM();
			// establish clean baseline
			originalSlots = deepClone(weeklySlots);
			renderDaysFromState();
			initSaveButton();
			initUnsavedWarning();
			init();
		});
	} else {
		buildWeeklySlotsFromDOM();
		// establish clean baseline
		originalSlots = deepClone(weeklySlots);
		renderDaysFromState();
		initSaveButton();
		initUnsavedWarning();
		init();
	}
}());
