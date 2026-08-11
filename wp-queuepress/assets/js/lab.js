/**
 * QueuePress Lab — JavaScript controller.
 *
 * Handles:
 *   Locked state
 *     - "Enable Lab Mode" button → confirmation dialog → AJAX toggle → page reload.
 *   Lab Controls card
 *     - "Disable Lab Mode" button → AJAX toggle → page reload.
 *     - Debug Logging checkbox → AJAX save → status label update.
 *   GraphQL Playground card
 *     - Execute button → AJAX → populates Response Viewer.
 *   Debug Console card
 *     - Refresh → window.location.reload().
 *     - Download Log → Blob download from embedded JSON.
 *     - Clear Log → confirm → AJAX → clear rendered entries.
 *
 * Depends on qpsLab (wp_localize_script):
 *   ajaxUrl, labEnabled, nonceExecute, nonceClear, nonceToggle, nonceDebug, i18n
 */
/* global qpsLab */
(function () {
	'use strict';

	// ── Helpers ───────────────────────────────────────────────────────────────

	function post(action, extra) {
		var body = new URLSearchParams(Object.assign({ action: action }, extra));
		return fetch(qpsLab.ajaxUrl, { method: 'POST', body: body })
			.then(function (r) { return r.json(); });
	}

	function prettyJson(value) {
		try { return JSON.stringify(value, null, 2); } catch (e) { return String(value); }
	}

	function showFeedback(el, msg, isError) {
		if (!el) { return; }
		el.textContent = msg;
		el.style.color = isError ? '#b32d2e' : '#1e6a1e';
		setTimeout(function () { el.textContent = ''; }, 4000);
	}

	// ── Locked state ──────────────────────────────────────────────────────────

	var enableBtn = document.getElementById('qps-lab-enable-btn');
	var overlay = document.getElementById('qps-lab-confirm-overlay');
	var cancelBtn = document.getElementById('qps-lab-confirm-cancel');
	var confirmOkBtn = document.getElementById('qps-lab-confirm-ok');

	if (enableBtn && overlay) {
		enableBtn.addEventListener('click', function () {
			overlay.hidden = false;
			if (confirmOkBtn) { confirmOkBtn.focus(); }
		});

		if (cancelBtn) {
			cancelBtn.addEventListener('click', function () {
				overlay.hidden = true;
				enableBtn.focus();
			});
		}

		if (confirmOkBtn) {
			confirmOkBtn.addEventListener('click', function () {
				confirmOkBtn.disabled = true;
				post('qps_lab_toggle_mode', {
					_ajax_nonce: enableBtn.dataset.nonce,
					enable: '1',
				}).then(function (json) {
					if (json.success) {
						window.location.reload();
					} else {
						overlay.hidden = true;
						confirmOkBtn.disabled = false;
						alert(json.data && json.data.message ? json.data.message : qpsLab.i18n.unknownError);
					}
				}).catch(function () {
					overlay.hidden = true;
					confirmOkBtn.disabled = false;
					alert(qpsLab.i18n.networkError);
				});
			});
		}

		// Close overlay on backdrop click.
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) { overlay.hidden = true; }
		});
	}

	// ── Lab Controls — Disable Lab Mode ───────────────────────────────────────

	var disableBtn = document.getElementById('qps-lab-disable-btn');

	if (disableBtn) {
		disableBtn.addEventListener('click', function () {
			disableBtn.disabled = true;
			post('qps_lab_toggle_mode', {
				_ajax_nonce: disableBtn.dataset.nonce,
				enable: '0',
			}).then(function (json) {
				if (json.success) {
					window.location.reload();
				} else {
					disableBtn.disabled = false;
					alert(json.data && json.data.message ? json.data.message : qpsLab.i18n.unknownError);
				}
			}).catch(function () {
				disableBtn.disabled = false;
				alert(qpsLab.i18n.networkError);
			});
		});
	}

	// ── Lab Controls — Debug Logging toggle ───────────────────────────────────

	var debugChk = document.getElementById('qps-lab-debug-chk');
	var debugStatus = document.getElementById('qps-debug-status');

	if (debugChk) {
		debugChk.addEventListener('change', function () {
			var enabled = debugChk.checked;
			post('qps_lab_save_debug', {
				_ajax_nonce: debugChk.dataset.nonce,
				debug: enabled ? '1' : '0',
			}).then(function (json) {
				if (json.success && debugStatus) {
					debugStatus.textContent = enabled
						? qpsLab.i18n.debugEnabled
						: qpsLab.i18n.debugDisabled;
					debugStatus.className = 'qps-lab-control-status ' +
						(enabled ? 'qps-lab-status--on' : 'qps-lab-status--off');
				} else if (!json.success) {
					// Revert the checkbox on failure.
					debugChk.checked = !enabled;
				}
			}).catch(function () {
				debugChk.checked = !enabled;
			});
		});
	}

	// ── GraphQL Playground ────────────────────────────────────────────────────

	var textarea = document.getElementById('qps-lab-graphql');
	var executeBtn = document.getElementById('qps-lab-execute');
	var logChk = document.getElementById('qps-lab-log');
	var playError = document.getElementById('qps-lab-playground-error');

	var resultsEmpty = document.getElementById('qps-lab-results-empty');
	var resultsPanel = document.getElementById('qps-lab-results');
	var reqPre = document.getElementById('qps-lab-request');
	var resPre = document.getElementById('qps-lab-response');
	var statusEl = document.getElementById('qps-lab-status');
	var elapsedEl = document.getElementById('qps-lab-elapsed');
	var tsEl = document.getElementById('qps-lab-ts');

	function clearPlayError() {
		if (playError) { playError.hidden = true; playError.textContent = ''; }
	}

	function showPlayError(msg) {
		if (playError) { playError.textContent = msg; playError.hidden = false; }
		if (resultsPanel) { resultsPanel.hidden = true; }
		if (resultsEmpty) { resultsEmpty.hidden = false; }
	}

	if (executeBtn) {
		executeBtn.addEventListener('click', function () {
			var graphql = textarea ? textarea.value.trim() : '';
			if (!graphql) {
				showPlayError(qpsLab.i18n.emptyInput);
				return;
			}

			clearPlayError();
			executeBtn.disabled = true;
			executeBtn.textContent = qpsLab.i18n.executing;

			post('qps_lab_execute_graphql', {
				_ajax_nonce: executeBtn.dataset.nonce,
				graphql: graphql,
				log: logChk && logChk.checked ? '1' : '0',
			}).then(function (json) {
				executeBtn.disabled = false;
				executeBtn.textContent = qpsLab.i18n.execute;

				if (!json.success) {
					showPlayError(json.data && json.data.message ? json.data.message : qpsLab.i18n.unknownError);
					return;
				}

				var d = json.data;

				if (reqPre) { reqPre.textContent = prettyJson({ query: graphql }); }
				if (resPre) { resPre.textContent = d.body !== null ? prettyJson(d.body) : '(empty response body)'; }

				if (statusEl) {
					var ok = d.http_status === 200;
					statusEl.textContent = 'HTTP ' + (d.http_status !== null ? d.http_status : '\u2014');
					statusEl.className = 'qps-lab-meta-item qps-lab-status--' + (ok ? 'ok' : 'err');
				}
				if (elapsedEl) { elapsedEl.textContent = d.elapsed_ms + ' ms'; }
				if (tsEl) { tsEl.textContent = d.timestamp + ' UTC'; }

				if (resultsEmpty) { resultsEmpty.hidden = true; }
				if (resultsPanel) { resultsPanel.hidden = false; }
			}).catch(function () {
				executeBtn.disabled = false;
				executeBtn.textContent = qpsLab.i18n.execute;
				showPlayError(qpsLab.i18n.networkError);
			});
		});
	}

	// ── Debug Console — Refresh ───────────────────────────────────────────────

	var refreshBtn = document.getElementById('qps-lab-refresh');
	if (refreshBtn) {
		refreshBtn.addEventListener('click', function () { window.location.reload(); });
	}

	// ── Debug Console — Clear Log ─────────────────────────────────────────────

	var clearBtn = document.getElementById('qps-lab-clear');
	var consoleFeedback = document.getElementById('qps-lab-console-feedback');
	var logContainer = document.getElementById('qps-lab-log');
	var logDataEl = document.getElementById('qps-lab-log-data');

	if (clearBtn) {
		clearBtn.addEventListener('click', function () {
			var confirmMsg = clearBtn.dataset.confirm || qpsLab.i18n.clearConfirm;
			if (!window.confirm(confirmMsg)) { return; }

			clearBtn.disabled = true;
			post('qps_lab_clear_log', { _ajax_nonce: clearBtn.dataset.nonce })
				.then(function (json) {
					clearBtn.disabled = false;
					if (json.success) {
						if (logContainer) {
							logContainer.innerHTML = '<p class="qps-lab-log-empty">' +
								qpsLab.i18n.logCleared + '</p>';
						}
						if (logDataEl) { logDataEl.textContent = '[]'; }
						showFeedback(consoleFeedback, qpsLab.i18n.logCleared, false);
					} else {
						showFeedback(
							consoleFeedback,
							json.data && json.data.message ? json.data.message : qpsLab.i18n.unknownError,
							true
						);
					}
				}).catch(function () {
					clearBtn.disabled = false;
					showFeedback(consoleFeedback, qpsLab.i18n.networkError, true);
				});
		});
	}

	// ── Debug Console — Copy individual log entry ────────────────────────────

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.qps-lab-copy-entry');
		if (!btn) { return; }
		var entry = btn.closest('.qps-lab-log-entry');
		if (!entry) { return; }
		var pre = entry.querySelector('.qps-lab-log-entry-body');
		if (!pre) { return; }
		var text = pre.textContent || '';
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				var orig = btn.textContent;
				btn.textContent = '✓ Copied';
				setTimeout(function () { btn.textContent = orig; }, 1500);
			}).catch(function () {
				fallbackCopy(text, btn);
			});
		} else {
			fallbackCopy(text, btn);
		}
	});

	function fallbackCopy(text, btn) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.focus();
		ta.select();
		try { document.execCommand('copy'); } catch (e) { }
		document.body.removeChild(ta);
		var orig = btn.textContent;
		btn.textContent = '✓ Copied';
		setTimeout(function () { btn.textContent = orig; }, 1500);
	}

	// ── Debug Console — Download Log ──────────────────────────────────────────

	var downloadBtn = document.getElementById('qps-lab-download');

	if (downloadBtn) {
		downloadBtn.addEventListener('click', function () {
			var data = [];
			if (logDataEl) {
				try { data = JSON.parse(logDataEl.textContent); } catch (e) { data = []; }
			}
			var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = 'queuepress-debug.log';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		});
	}

	// ── Buffer Queue Settings ────────────────────────────────────────────────

	var queueChk = document.getElementById('qps-lab-queue-chk');
	var queueInterval = document.getElementById('qps-lab-queue-interval');
	var queueStatus = document.getElementById('qps-queue-status');

	function saveQueueSettings() {
		if (!queueChk) return;
		var enabled = queueChk.checked;
		var interval = queueInterval ? queueInterval.value : 15;

		post('qps_lab_save_queue_settings', {
			_ajax_nonce: queueChk.dataset.nonce,
			enabled: enabled ? '1' : '0',
			interval: interval
		}).then(function (json) {
			if (json.success && queueStatus) {
				// Use server-returned values when available to reflect the true saved state.
				var srvEnabled = json.data && typeof json.data.enabled !== 'undefined' ? !!json.data.enabled : enabled;
				var srvInterval = json.data && typeof json.data.interval !== 'undefined' ? String(json.data.interval) : String(interval);
				queueChk.checked = srvEnabled;
				if (queueInterval) { queueInterval.value = srvInterval; }
				queueStatus.textContent = srvEnabled ? 'Enabled' : 'Disabled';
				queueStatus.className = 'qps-lab-control-status ' + (srvEnabled ? 'qps-lab-status--on' : 'qps-lab-status--off');
			}
		}).catch(function () {
			alert('Network error saving queue settings.');
		});
	}

	if (queueChk) {
		queueChk.addEventListener('change', saveQueueSettings);
	}
	if (queueInterval) {
		queueInterval.addEventListener('change', saveQueueSettings);
	}

	// ── Buffer Queue Actions ────────────────────────────────────────────────

	document.addEventListener('click', function (e) {
		var retryBtn = e.target.closest('.qps-lab-queue-retry');
		var cancelBtn = e.target.closest('.qps-lab-queue-cancel');

		if (retryBtn) {
			var jobId = retryBtn.dataset.id;
			var nonce = retryBtn.dataset.nonce;
			retryBtn.disabled = true;
			retryBtn.textContent = 'Retrying...';

			post('qps_lab_queue_retry', {
				_ajax_nonce: nonce,
				job_id: jobId
			}).then(function (json) {
				if (json.success) {
					window.location.reload();
				} else {
					retryBtn.disabled = false;
					retryBtn.textContent = 'Retry now';
					alert(json.data && json.data.message ? json.data.message : 'Error retrying job');
				}
			}).catch(function () {
				retryBtn.disabled = false;
				retryBtn.textContent = 'Retry now';
				alert('Network error.');
			});
		}

		if (cancelBtn) {
			var cancelJobId = cancelBtn.dataset.id;
			var cancelNonce = cancelBtn.dataset.nonce;
			cancelBtn.disabled = true;

			post('qps_lab_queue_cancel', {
				_ajax_nonce: cancelNonce,
				job_id: cancelJobId
			}).then(function (json) {
				if (json.success) {
					var row = document.getElementById('qps-job-' + cancelJobId);
					if (row) {
						var statusSpan = row.querySelector('.qps-job-status');
						if (statusSpan) {
							statusSpan.textContent = 'Cancelled';
							statusSpan.className = 'qps-job-status qps-job-status--cancelled';
						}
						cancelBtn.remove();
					}
				} else {
					cancelBtn.disabled = false;
					alert(json.data && json.data.message ? json.data.message : 'Error cancelling job');
				}
			}).catch(function () {
				cancelBtn.disabled = false;
				alert('Network error.');
			});
		}
	});

}());
