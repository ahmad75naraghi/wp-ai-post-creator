/**
 * AI Post Creator — agent console driver.
 *
 * Drives the step-by-step REST pipeline and renders a live terminal,
 * progress bar and step checklist. No dependencies.
 */
(function () {
	'use strict';

	if (!window.AIPC) {
		return;
	}

	var CFG = window.AIPC;

	var el = {
		wrap: document.getElementById('aipc-form-card'),
		topic: document.getElementById('aipc-topic'),
		postSelect: document.getElementById('aipc-rewrite-post'),
		publishMode: document.getElementById('aipc-publish-mode'),
		publishDelay: document.getElementById('aipc-publish-delay'),
		publishDelayField: document.getElementById('aipc-publish-delay-field'),
		tone: document.getElementById('aipc-tone'),
		length: document.getElementById('aipc-length'),
		language: document.getElementById('aipc-language'),
		languageCustomField: document.getElementById('aipc-language-custom-field'),
		languageCustom: document.getElementById('aipc-language-custom'),
		optImage: document.getElementById('aipc-opt-image'),
		optFaq: document.getElementById('aipc-opt-faq'),
		optToc: document.getElementById('aipc-opt-toc'),
		start: document.getElementById('aipc-start'),
		console: document.getElementById('aipc-console'),
		terminal: document.getElementById('aipc-terminal'),
		steps: document.getElementById('aipc-steps'),
		bar: document.getElementById('aipc-bar'),
		barTrack: document.querySelector('#aipc-console .aipc-bar-track'),
		pct: document.getElementById('aipc-pct'),
		cancel: document.getElementById('aipc-cancel'),
		retry: document.getElementById('aipc-retry'),
		result: document.getElementById('aipc-result'),
		resultTitle: document.getElementById('aipc-result-title'),
		resultEdit: document.getElementById('aipc-result-edit'),
		resultView: document.getElementById('aipc-result-view'),
		resultStats: document.getElementById('aipc-result-stats'),
		newBtn: document.getElementById('aipc-new')
	};

	var state = {
		jobId: null,
		since: 0,
		running: false,
		abort: null,
		t0: 0,
		lastSig: null,
		stalled: 0
	};

	function t(key) {
		return (CFG.i18n && CFG.i18n[key]) ? CFG.i18n[key] : key;
	}

	function sleep(ms) {
		return new Promise(function (resolve) { setTimeout(resolve, ms); });
	}

	function esc(text) {
		var d = document.createElement('div');
		d.textContent = String(text == null ? '' : text);
		return d.innerHTML;
	}

	/* ------------------------------------------------------------------ */
	/* REST helper                                                        */
	/* ------------------------------------------------------------------ */

	function api(action, data) {
		var ctrl = new AbortController();
		state.abort = ctrl;
		var timer = setTimeout(function () { ctrl.abort(); }, 300000);

		return fetch(CFG.restUrl + action, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce
			},
			body: JSON.stringify(data || {}),
			signal: ctrl.signal
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok) {
					var msg = json && json.message ? json.message : 'HTTP ' + res.status;
					throw new Error(msg);
				}
				return json;
			});
		}).finally(function () {
			clearTimeout(timer);
		});
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	function timestamp() {
		var d = new Date();
		var p = function (n) { return (n < 10 ? '0' : '') + n; };
		return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
	}

	function log(level, msg) {
		var line = document.createElement('div');
		line.className = 'aipc-line aipc-l-' + esc(level);
		line.innerHTML =
			'<span class="aipc-time">' + timestamp() + '</span>' +
			'<span class="aipc-tag">' + esc(level) + '</span>' +
			'<span class="aipc-msg">' + esc(msg) + '</span>';
		el.terminal.appendChild(line);
		var nearBottom = el.terminal.scrollHeight - el.terminal.scrollTop - el.terminal.clientHeight < 120;
		if (nearBottom) {
			el.terminal.scrollTop = el.terminal.scrollHeight;
		}
	}

	function renderSteps(steps) {
		if (!el.steps) {
			return;
		}
		el.steps.innerHTML = '';
		(steps || []).forEach(function (step) {
			var li = document.createElement('li');
			li.className = 'aipc-step s-' + step.status;
			li.innerHTML =
				'<span class="aipc-step-ico" aria-hidden="true"></span>' +
				'<span class="aipc-step-label">' + esc(step.label) + '</span>';
			el.steps.appendChild(li);
		});
	}

	function applyState(st) {
		renderSteps(st.steps);

		if (st.progress === null || st.progress === undefined) {
			if (el.barTrack) { el.barTrack.classList.add('indeterminate'); }
			if (el.pct) { el.pct.textContent = '…'; }
		} else {
			if (el.barTrack) { el.barTrack.classList.remove('indeterminate'); }
			if (el.bar) { el.bar.style.width = st.progress + '%'; }
			if (el.pct) { el.pct.textContent = st.progress + '%'; }
		}

		(st.logs || []).forEach(function (entry) {
			log(entry.level || 'info', entry.msg || '');
		});
		state.since = st.since;
	}

	function setRunningUI(on) {
		state.running = on;
		if (el.start) { el.start.disabled = on; }
		if (el.cancel) { el.cancel.classList.toggle('aipc-hidden', !on); }
		if (!on && el.retry) { el.retry.classList.remove('aipc-hidden'); }
		if (on && el.retry) { el.retry.classList.add('aipc-hidden'); }
	}

	function showConsole() {
		if (el.console) {
			el.console.classList.remove('aipc-hidden');
			el.console.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	/* ------------------------------------------------------------------ */
	/* Agent loop (viewer: the background runner drives the steps, we      */
	/* only poll the read-only /state endpoint)                            */
	/* ------------------------------------------------------------------ */

	function progressSignature(st) {
		var done = 0;
		(st.steps || []).forEach(function (s) {
			if (s.status === 'done' || s.status === 'skipped') { done++; }
		});
		return done + '|' + (st.since || 0);
	}

	function loop() {
		if (!state.running) {
			return;
		}
		return api('state', { job_id: state.jobId, since: state.since }).then(function (st) {
			if (!state.running) {
				return;
			}
			applyState(st);

			var sig = progressSignature(st);
			if (sig === state.lastSig) {
				state.stalled = (state.stalled || 0) + 1;
				if (state.stalled === 120) {
					// ~90s without any progress while the job still runs.
					log('warn', t('serverStalled'));
				}
			} else {
				state.stalled = 0;
			}
			state.lastSig = sig;

			if (st.status === 'done') {
				success(st);
			} else if (st.status === 'error') {
				fail();
			} else if (st.status === 'cancelled') {
				setRunningUI(false);
			} else {
				return sleep(750).then(loop);
			}
		}).catch(function (err) {
			if (!state.running) {
				return;
			}
			if (err && err.name === 'AbortError') {
				log('error', t('timeout'));
			} else {
				log('error', t('networkError') + ' ' + (err && err.message ? err.message : ''));
			}
			fail();
		});
	}

	function fail() {
		setRunningUI(false);
		log('error', t('failed'));
	}

	function success(st) {
		setRunningUI(false);
		var r = (st && st.result) || {};

		if (el.resultTitle) { el.resultTitle.textContent = r.title || ''; }
		if (el.resultEdit) { el.resultEdit.href = r.edit || '#'; }
		if (el.resultView) { el.resultView.href = r.view || '#'; }

		var stats = [];
		if (r.words) {
			stats.push(r.words.toLocaleString() + ' ' + t('words'));
		}
		var tokens = st && st.usage ? (Number(st.usage.prompt) + Number(st.usage.completion)) : 0;
		if (tokens > 0) {
			stats.push(tokens.toLocaleString() + ' ' + t('tokens'));
		}
		var secs = Math.max(1, Math.round((Date.now() - state.t0) / 1000));
		stats.push(secs + ' ' + t('sec'));

		if (el.resultStats) { el.resultStats.textContent = stats.join('  ·  '); }
		if (el.result) {
			el.result.classList.remove('aipc-hidden');
			el.result.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	function collectArgs() {
		var args = {
			topic: el.topic ? el.topic.value.trim() : '',
			post_id: el.postSelect ? el.postSelect.value : '',
			tone: el.tone ? el.tone.value : '',
			length: el.length ? el.length.value : '',
			language: el.language ? el.language.value : '',
			language_custom: el.languageCustom ? el.languageCustom.value.trim() : '',
			image: !!(el.optImage && el.optImage.checked),
			faq: !!(el.optFaq && el.optFaq.checked),
			toc: !!(el.optToc && el.optToc.checked)
		};
		if (CFG.extraArgs) {
			Object.keys(CFG.extraArgs).forEach(function (key) {
				args[key] = CFG.extraArgs[key];
			});
		}
		if (el.publishMode && el.publishMode.value !== 'draft') {
			args.publish_mode = el.publishMode.value;
			if (el.publishMode.value === 'delay' && el.publishDelay) {
				args.publish_delay = parseInt(el.publishDelay.value, 10) || 60;
			}
		}
		if (args.mode === 'rewrite' && !args.post_id) {
			return null;
		}
		return args;
	}

	function resumeAgent(jobId) {
		state.t0 = Date.now();
		state.since = 0;
		state.jobId = jobId;
		if (el.terminal) { el.terminal.innerHTML = ''; }
		if (el.result) { el.result.classList.add('aipc-hidden'); }
		if (el.bar) { el.bar.style.width = '0%'; }
		if (el.pct) { el.pct.textContent = '…'; }
		log('info', t('resuming'));
		setRunningUI(true);
		showConsole();
		loop();
	}

	function bindPublishToggle() {
		if (!el.publishMode || !el.publishDelayField) { return; }
		el.publishMode.addEventListener('change', function () {
			el.publishDelayField.classList.toggle('aipc-hidden', el.publishMode.value !== 'delay');
		});
	}

	function startAgent() {
		var args = collectArgs();
		if (!args) {
			log('error', t('needPost'));
			return;
		}

		state.t0 = Date.now();
		state.since = 0;
		state.jobId = null;

		if (el.terminal) { el.terminal.innerHTML = ''; }
		if (el.result) { el.result.classList.add('aipc-hidden'); }
		if (el.bar) { el.bar.style.width = '0%'; }
		if (el.pct) { el.pct.textContent = '…'; }

		log('info', t('starting'));
		setRunningUI(true);
		showConsole();

		api('start', args).then(function (st) {
			state.jobId = st.id;
			applyState(st);
			return loop();
		}).catch(function (err) {
			log('error', t('networkError') + ' ' + (err && err.message ? err.message : ''));
			fail();
		});
	}

	function cancelAgent() {
		if (!window.confirm(t('cancelConfirm'))) {
			return;
		}
		var wasRunning = state.running;
		state.running = false;
		setRunningUI(false);
		if (state.abort) {
			try { state.abort.abort(); } catch (e) { /* noop */ }
		}
		if (wasRunning && state.jobId) {
			api('cancel', { job_id: state.jobId }).catch(function () { /* noop */ });
			log('warn', t('cancelled'));
		}
	}

	function retryAgent() {
		if (!state.jobId) {
			return;
		}
		if (el.retry) { el.retry.classList.add('aipc-hidden'); }
		setRunningUI(true);
		api('retry', { job_id: state.jobId }).then(function (st) {
			applyState(st);
			return loop();
		}).catch(function (err) {
			log('error', t('networkError') + ' ' + (err && err.message ? err.message : ''));
			fail();
		});
	}

	function resetUI() {
		state.running = false;
		state.jobId = null;
		if (el.terminal) { el.terminal.innerHTML = ''; }
		if (el.steps) { el.steps.innerHTML = ''; }
		if (el.console) { el.console.classList.add('aipc-hidden'); }
		if (el.result) { el.result.classList.add('aipc-hidden'); }
		if (el.start) { el.start.disabled = false; }
		if (el.topic) {
			el.topic.value = '';
			el.topic.focus();
		}
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                               */
	/* ------------------------------------------------------------------ */

	function fillSelects() {
		var fill = function (select, options, selected) {
			if (!select || !options) { return; }
			var html = '';
			Object.keys(options).forEach(function (code) {
				html += '<option value="' + esc(code) + '">' + esc(options[code]) + '</option>';
			});
			select.innerHTML = html;
			if (selected && options[selected]) {
				select.value = selected;
			}
		};

		var d = CFG.defaults || {};
		fill(el.tone, CFG.tones, d.tone);
		fill(el.length, CFG.lengths, d.length);
		fill(el.language, CFG.languages, d.language);
		if (el.optImage) { el.optImage.checked = d.image !== false; }
		if (el.optFaq) { el.optFaq.checked = d.faq !== false; }
		if (el.optToc) { el.optToc.checked = d.toc !== false; }
	}

	function bindEvents() {
		if (el.start) { el.start.addEventListener('click', startAgent); }
		if (el.cancel) { el.cancel.addEventListener('click', cancelAgent); }
		if (el.retry) { el.retry.addEventListener('click', retryAgent); }
		if (el.newBtn) { el.newBtn.addEventListener('click', resetUI); }

		if (el.language) {
			el.language.addEventListener('change', function () {
				var isOther = el.language.value === 'other';
				if (el.languageCustomField) {
					el.languageCustomField.classList.toggle('aipc-hidden', !isOther);
				}
				if (isOther && el.languageCustom) {
					el.languageCustom.focus();
				}
			});
		}

		window.addEventListener('beforeunload', function (e) {
			if (state.running) {
				e.preventDefault();
				e.returnValue = '';
			}
		});
	}

	fillSelects();
	bindEvents();
	bindPublishToggle();

	// Adopt a job handed over by another screen (e.g. Schedule → Run now).
	if (CFG.resumeJobId) {
		resumeAgent(String(CFG.resumeJobId));
	}
})();
