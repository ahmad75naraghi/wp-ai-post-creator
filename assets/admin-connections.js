/**
 * AI Post Creator — connections page helpers.
 *
 * Binds the "Test connection" and "Load models" buttons of every connection
 * form (add + edit) to the REST endpoints, reading the sibling form fields.
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

	function api(action, data) {
		return fetch(CFG.restUrl + action, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce
			},
			body: JSON.stringify(data || {})
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok) {
					throw new Error(json && json.message ? json.message : 'HTTP ' + res.status);
				}
				return json;
			});
		});
	}

	function formConfig(btn) {
		var form = btn.closest('form');
		if (!form) { return null; }
		return {
			id: (form.querySelector('[name="id"]') || {}).value || '',
			base_url: (form.querySelector('[name="base_url"]') || {}).value || '',
			api_key: (form.querySelector('[name="api_key"]') || {}).value || '',
			chat_model: (form.querySelector('[name="chat_model"]') || {}).value || ''
		};
	}

	function statusSpan(btn) {
		var actions = btn.parentNode;
		return actions ? actions.querySelector('.aipc-inline-status') : null;
	}

	function bind(className, handler) {
		Array.prototype.forEach.call(document.querySelectorAll('.' + className), function (btn) {
			btn.addEventListener('click', function () {
				handler(btn);
			});
		});
	}

	function onTest(btn) {
		var cfg = formConfig(btn);
		if (!cfg) { return; }
		var out = statusSpan(btn);
		btn.disabled = true;
		setStatus(out, t('testing'));

		api('connection/test', cfg).then(function (res) {
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
	}

	function onModels(btn) {
		var cfg = formConfig(btn);
		if (!cfg) { return; }
		var out = statusSpan(btn);
		var form = btn.closest('form');
		var list = form ? form.querySelector('datalist') : null;
		btn.disabled = true;
		setStatus(out, t('loadingModels'));

		api('connection/models', cfg).then(function (res) {
			var models = (res && res.models) || [];
			if (list) {
				list.innerHTML = '';
				models.forEach(function (id) {
					var opt = document.createElement('option');
					opt.value = id;
					list.appendChild(opt);
				});
			}
			setStatus(out, fmt(t('modelsOk'), models.length), true);
		}).catch(function (err) {
			setStatus(out, t('modelsFail') + ' ' + (err.message || ''), false);
		}).finally(function () {
			btn.disabled = false;
		});
	}

	bind('aipc-btn-test', onTest);
	bind('aipc-btn-models', onModels);
})();
