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

	function setMessage(manager, message, isError) {
		var messageNode = manager.querySelector('.qps-slot-message');

		if (!messageNode) {
			return;
		}

		messageNode.textContent = message || '';
		messageNode.classList.toggle('is-error', !!isError);
		messageNode.classList.toggle('is-success', !!message && !isError);
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

				postSlotAction('wp_queuepress_add_slot', {
					day: day,
					time: time,
					scope: parsed.scope
				}).then(function (data) {
					updateDays(data.days);
					saveManager.querySelector('.qps-slot-form').hidden = true;
					saveManager.querySelector('.qps-slot-time').value = '';
					setMessage(saveManager, data.message, false);
				}).catch(function (error) {
					setMessage(saveManager, error.message, true);
				}).finally(function () {
					save.disabled = false;
				});
			}

			if (remove) {
				var removeManager = closest(remove, '.qps-slot-manager');
				var messageManager = getGlobalManager() || removeManager;

				remove.disabled = true;
				setMessage(messageManager, window.wpQueuePressSlots.messages.saving, false);

				postSlotAction('wp_queuepress_delete_slot', {
					day: remove.getAttribute('data-day'),
					time: remove.getAttribute('data-time')
				}).then(function (data) {
					updateDays(data.days);
					setMessage(messageManager, data.message, false);
				}).catch(function (error) {
					setMessage(messageManager, error.message, true);
					remove.disabled = false;
				});
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

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
