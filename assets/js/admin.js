/**
 * iWorks.ie Search & Replace admin screen.
 *
 * Plain ES5 so it runs in every browser WordPress supports. The page is rendered
 * by PHP; this file only validates via AJAX, drives the chunked job and reloads.
 */
(function () {
	'use strict';

	var cfg = window.iwiesrAdmin;
	if (!cfg) {
		return;
	}
	var strings = cfg.strings || {};
	var running = false;
	var cancelRequested = false;

	function $(sel, root) {
		return (root || document).querySelector(sel);
	}
	function $all(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function post(action, data) {
		var body = data instanceof FormData ? data : new FormData();
		if (!(data instanceof FormData) && data) {
			Object.keys(data).forEach(function (k) {
				body.append(k, data[k]);
			});
		}
		body.append('action', action);
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (res) {
			return res.json().catch(function () {
				return { success: false, data: { messages: [strings.failed] } };
			});
		});
	}

	function showErrors(list) {
		var box = $('[data-iwiesr-errors]');
		if (!box) {
			return;
		}
		box.innerHTML = '';
		if (!list || !list.length) {
			box.hidden = true;
			return;
		}
		var notice = document.createElement('div');
		notice.className = 'notice notice-error inline';
		list.forEach(function (msg) {
			var p = document.createElement('p');
			p.textContent = String(msg);
			notice.appendChild(p);
		});
		box.appendChild(notice);
		box.hidden = false;
		box.scrollIntoView({ block: 'center' });
	}

	function messagesFrom(json) {
		if (json && json.data && json.data.messages) {
			return json.data.messages;
		}
		return [strings.failed];
	}

	function setBusy(busy) {
		running = busy;
		$all('[data-iwiesr-preview], [data-iwiesr-apply], [data-iwiesr-undo], [data-iwiesr-discard], [data-iwiesr-delete-run], [data-iwiesr-resume], [data-iwiesr-stop]').forEach(function (el) {
			el.disabled = busy;
		});
		var runner = $('[data-iwiesr-runner]');
		if (runner && busy) {
			runner.hidden = false;
		}
		var cancel = $('[data-iwiesr-cancel]');
		if (cancel) {
			cancel.hidden = !busy;
			cancel.disabled = false;
		}
	}

	function progress(pct, msg) {
		if (window.iwtCoreUi && window.iwtCoreUi.setProgress) {
			window.iwtCoreUi.setProgress('iwiesr-progress', pct, msg);
		}
	}

	function reload(anchor) {
		var url = window.location.href.split('#')[0].replace(/([?&])iwiesr_t=\d+&?/, '$1').replace(/[?&]$/, '');
		url += (url.indexOf('?') === -1 ? '?' : '&') + 'iwiesr_t=' + Date.now();
		window.location.href = url + (anchor ? '#' + anchor : '');
	}

	function jobPost(action) {
		return post(action, { nonce: cfg.jobsNonce, job_id: cfg.jobId });
	}

	function finish(state) {
		setBusy(false);
		var result = state.result || {};
		if (result.error || state.cancelled) {
			if (state.cancelled) {
				reload();
				return;
			}
			showErrors([state.message || strings.failed]);
			return;
		}
		reload(result.mode === 'preview' ? 'iwiesr-preview' : '');
	}

	function step() {
		if (cancelRequested) {
			return jobPost('iwiesr_job_cancel').then(function () {
				progress(0, strings.stopped);
				setBusy(false);
				reload();
			});
		}
		return jobPost('iwiesr_job_step').then(function (json) {
			if (!json || !json.success) {
				setBusy(false);
				showErrors(messagesFrom(json));
				return null;
			}
			var state = json.data || {};
			progress(state.progress || 0, state.message || '');
			if (state.done) {
				finish(state);
				return null;
			}
			return step();
		});
	}

	function startWithToken(token) {
		var body = new FormData();
		body.append('nonce', cfg.jobsNonce);
		body.append('job_id', cfg.jobId);
		body.append('params[token]', token);
		return post('iwiesr_job_start', body).then(function (json) {
			if (!json || !json.success) {
				setBusy(false);
				showErrors(messagesFrom(json));
				return null;
			}
			var state = json.data || {};
			progress(state.progress || 0, state.message || '');
			if (state.done) {
				finish(state);
				return null;
			}
			return step();
		});
	}

	function prepare(fields) {
		if (running) {
			return;
		}
		showErrors([]);
		cancelRequested = false;
		setBusy(true);
		progress(0, strings.preparing);
		var body = fields instanceof FormData ? fields : new FormData();
		if (!(fields instanceof FormData)) {
			Object.keys(fields).forEach(function (k) {
				body.append(k, fields[k]);
			});
		}
		body.append('nonce', cfg.nonce);
		post('iwiesr_prepare', body)
			.then(function (json) {
				if (!json || !json.success) {
					setBusy(false);
					var runner = $('[data-iwiesr-runner]');
					if (runner && !$('[data-iwiesr-resume]')) {
						runner.hidden = true;
					}
					showErrors(messagesFrom(json));
					return null;
				}
				return startWithToken(json.data.token);
			})
			.catch(function () {
				setBusy(false);
				showErrors([strings.failed]);
			});
	}

	function updateShort(form) {
		var input = $('[name="search"]', form);
		var box = $('[data-iwiesr-short]', form);
		var regex = $('[data-iwiesr-regex]', form);
		if (!input || !box) {
			return;
		}
		var len = Array.from ? Array.from(input.value).length : input.value.length;
		box.hidden = !(len > 0 && len < 4 && !(regex && regex.checked));
	}

	function updateCount(form) {
		var out = $('[data-iwiesr-pick-count]', form);
		if (!out) {
			return;
		}
		var n = $all('input[name="tables[]"]:checked', form).length;
		out.textContent = (strings.selected || '%d').replace('%d', String(n));
	}

	document.addEventListener('DOMContentLoaded', function () {
		$all('[data-iwiesr-form]').forEach(function (form) {
			updateShort(form);
			updateCount(form);

			form.addEventListener('input', function () {
				updateShort(form);
			});
			form.addEventListener('change', function () {
				updateShort(form);
				updateCount(form);
			});

			$all('[data-iwiesr-pick]', form).forEach(function (btn) {
				btn.addEventListener('click', function () {
					var which = btn.getAttribute('data-iwiesr-pick');
					$all('input[name="tables[]"]', form).forEach(function (box) {
						box.checked = which === 'all' || (which === 'core' && box.getAttribute('data-core') === '1');
					});
					updateCount(form);
				});
			});

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var data = new FormData(form);
				var regex = $('[data-iwiesr-regex]', form);
				if (regex && regex.checked) {
					data.set('mode', 'regex');
				}
				data.append('what', 'preview');
				prepare(data);
			});
		});

		$all('[data-iwiesr-apply]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				prepare({ what: 'apply', preview_id: btn.getAttribute('data-iwiesr-apply') });
			});
		});

		document.addEventListener('click', function (e) {
			var target = e.target;
			if (!(target instanceof Element)) {
				return;
			}
			var undo = target.closest('[data-iwiesr-undo]');
			if (undo) {
				prepare({ what: 'undo', run_id: undo.getAttribute('data-iwiesr-undo') });
			}
		});

		$all('[data-iwiesr-discard]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				btn.disabled = true;
				post('iwiesr_discard', { nonce: cfg.nonce }).then(function () {
					reload();
				});
			});
		});

		$all('[data-iwiesr-delete-run]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				// eslint-disable-next-line no-alert
				if (!window.confirm(strings.confirmDel)) {
					return;
				}
				btn.disabled = true;
				post('iwiesr_delete_run', { nonce: cfg.nonce, run_id: btn.getAttribute('data-iwiesr-delete-run') }).then(function (json) {
					if (!json || !json.success) {
						btn.disabled = false;
						showErrors(messagesFrom(json));
						return;
					}
					reload();
				});
			});
		});

		var resume = $('[data-iwiesr-resume]');
		if (resume) {
			resume.addEventListener('click', function () {
				cancelRequested = false;
				setBusy(true);
				step();
			});
		}
		var stop = $('[data-iwiesr-stop]');
		if (stop) {
			stop.addEventListener('click', function () {
				setBusy(true);
				jobPost('iwiesr_job_cancel').then(function () {
					reload();
				});
			});
		}
		var cancel = $('[data-iwiesr-cancel]');
		if (cancel) {
			cancel.addEventListener('click', function () {
				cancelRequested = true;
				cancel.disabled = true;
			});
		}

		window.addEventListener('beforeunload', function (e) {
			if (running) {
				e.preventDefault();
				e.returnValue = strings.leaveWarning;
				return strings.leaveWarning;
			}
			return undefined;
		});
	});
})();
