/**
 * AI Post Creator — schedule page driver (Bale test / chat-id detection).
 *
 * No dependencies.
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

	function setStatus(text, cls) {
		var el = document.getElementById('aipc-bale-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = 'aipc-inline-status' + (cls ? ' ' + cls : '');
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
			return res.json().then(function (json) {
				return { ok: res.ok, json: json };
			});
		});
	}

	function tokenField() {
		var el = document.querySelector('input[name="token"]');
		return el ? el.value.trim() : '';
	}

	function chatsField() {
		var el = document.getElementById('aipc-bale-chats');
		return el ? el.value.trim() : '';
	}

	function bindTest() {
		var btn = document.querySelector('.aipc-btn-bale-test');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var token = tokenField();
			var chats = chatsField();
			if (!token || !chats) {
				setStatus(token ? t('needChat') : t('needToken'), 'is-err');
				return;
			}
			setStatus(t('testing'));
			api('bale/test', { token: token, chat_ids: chats }).then(function (r) {
				if (r.ok && r.json && r.json.ok) {
					setStatus(t('ok'), 'is-ok');
				} else {
					var msg = (r.json && (r.json.error || r.json.message)) || (r.ok ? '' : 'HTTP error');
					setStatus(t('failed') + ' ' + msg, 'is-err');
				}
			}).catch(function (err) {
				setStatus(t('failed') + ' ' + (err && err.message ? err.message : ''), 'is-err');
			});
		});
	}

	function bindChatId() {
		var btn = document.querySelector('.aipc-btn-bale-chatid');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var token = tokenField();
			if (!token) {
				setStatus(t('needToken'), 'is-err');
				return;
			}
			setStatus(t('fetching'));
			api('bale/chat-id', { token: token }).then(function (r) {
				if (r.ok && r.json && r.json.ok) {
					var box = document.getElementById('aipc-bale-chats');
					if (box) {
						var current = box.value.trim();
						if (current.split(/[\n,]+/).map(function (s) { return s.trim(); }).indexOf(r.json.chat_id) === -1) {
							box.value = current ? current + '\n' + r.json.chat_id : r.json.chat_id;
						}
					}
					setStatus(t('chatFound').replace('%s', r.json.chat_id + (r.json.name ? ' (' + r.json.name + ')' : '')), 'is-ok');
				} else {
					var msg = (r.json && (r.json.error || r.json.message)) || '';
					setStatus(msg || t('noChat'), 'is-err');
				}
			}).catch(function (err) {
				setStatus(t('failed') + ' ' + (err && err.message ? err.message : ''), 'is-err');
			});
		});
	}

	bindTest();
	bindChatId();
})();
