/**
 * AI Post Creator — settings page helpers.
 */
(function () {
	'use strict';

	if (!window.AIPC) {
		return;
	}

	var CFG = window.AIPC;

	function t(key) {
		return (CFG.i18n && CFG.i18n[key]) ? CFG.i18n[key] : key;
	}

	function fmt(str, n) {
		return String(str).replace('%d', String(n));
	}

	function setStatus(el, text, ok) {
		if (!el) { return; }
		el.textContent = text;
		el.classList.remove('is-ok', 'is-err');
		if (typeof ok === 'boolean') {
			el.classList.add(ok ? 'is-ok' : 'is-err');
		}
	}

	function api(action, data, method) {
		return fetch(CFG.restUrl + action, {
			method: method || 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce
			},
			body: method === 'GET' ? undefined : JSON.stringify(data || {})
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok) {
					throw new Error(json && json.message ? json.message : 'HTTP ' + res.status);
				}
				return json;
			});
		});
	}

	function initTest() {
		var btn = document.getElementById('aipc-test');
		var out = document.getElementById('aipc-test-result');
		if (!btn || !out) { return; }

		btn.addEventListener('click', function () {
			setStatus(out, t('testing'));
			btn.disabled = true;

			api('test', {}).then(function (res) {
				if (res && res.ok) {
					if (res.models && res.models.length) {
						setStatus(out, fmt(t('okModels'), res.models.length), true);
					} else {
						setStatus(out, t('okNoModels'), true);
					}
				} else {
					setStatus(out, t('failed'), false);
				}
			}).catch(function (err) {
				setStatus(out, t('failed') + ' ' + (err.message || ''), false);
			}).finally(function () {
				btn.disabled = false;
			});
		});
	}

	function initModels() {
		var btn = document.getElementById('aipc-fetch-models');
		var out = document.getElementById('aipc-models-status');
		var list = document.getElementById('aipc-model-list');
		if (!btn || !out || !list) { return; }

		btn.addEventListener('click', function () {
			setStatus(out, t('loadingModels'));
			btn.disabled = true;

			api('models', null, 'GET').then(function (res) {
				var models = (res && res.models) || [];
				list.innerHTML = '';
				models.forEach(function (id) {
					var opt = document.createElement('option');
					opt.value = id;
					list.appendChild(opt);
				});
				setStatus(out, fmt(t('modelsOk'), models.length), true);
			}).catch(function (err) {
				setStatus(out, t('modelsFail') + ' ' + (err.message || ''), false);
			}).finally(function () {
				btn.disabled = false;
			});
		});
	}

	function initKeyToggle() {
		var input = document.getElementById('aipc-api-key');
		var btn = document.getElementById('aipc-toggle-key');
		if (!input || !btn) { return; }

		btn.addEventListener('click', function () {
			var show = input.type === 'password';
			input.type = show ? 'text' : 'password';
			btn.textContent = show ? t('hide') : t('show');
		});
	}

	initTest();
	initModels();
	initKeyToggle();
})();
