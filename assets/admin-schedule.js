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

	/* --- Topic queue: suggest topics from the research sources --- */

	function tqStatus(text, cls) {
		var el = document.getElementById('aipc-tq-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = 'aipc-inline-status' + (cls ? ' ' + cls : '');
	}

	function shownTexts() {
		var list = document.getElementById('aipc-tq-suggest-list');
		var texts = [];
		if (list) {
			list.querySelectorAll('input[type=checkbox]').forEach(function (box) {
				texts.push(box.value);
			});
		}
		return texts;
	}

	function renderSuggestionRow(list, item) {
		var row = document.createElement('div');
		row.className = 'aipc-tq-item';

		var label = document.createElement('label');
		var box = document.createElement('input');
		box.type = 'checkbox';
		box.checked = true;
		box.value = item.text;
		label.appendChild(box);
		label.appendChild(document.createTextNode(' ' + item.text));
		if (item.source) {
			var badge = document.createElement('span');
			badge.className = 'aipc-badge';
			badge.textContent = item.source;
			label.appendChild(badge);
		}
		row.appendChild(label);

		// ✕ — never show this headline again.
		var del = document.createElement('button');
		del.type = 'button';
		del.className = 'button-link aipc-tq-dismiss';
		del.textContent = '✕';
		del.title = t('dismissTitle');
		del.setAttribute('aria-label', t('dismissTitle'));
		del.addEventListener('click', function () {
			api('topics/dismiss', { text: item.text }).then(function () {
				if (row.parentNode) { row.parentNode.removeChild(row); }
				tqStatus(t('dismissed'), 'is-ok');
			}).catch(function (err) {
				tqStatus(t('dismissFail') + ' ' + (err && err.message ? err.message : ''), 'is-err');
			});
		});
		row.appendChild(del);

		list.appendChild(row);
	}

	function sourceReport(sources) {
		var parts = [];
		(sources || []).forEach(function (s) {
			parts.push(s.host + ': ' + (s.status === 'ok' ? s.found : t('noFeed')));
		});
		return parts.length ? ' — ' + parts.join(' · ') : '';
	}

	function fetchSuggestions(append) {
		var list = document.getElementById('aipc-tq-suggest-list');
		var actions = document.getElementById('aipc-tq-suggest-actions');
		tqStatus(t('suggesting'));
		var payload = { limit: 12 };
		if (append) { payload.exclude = shownTexts(); }
		api('topics/suggest', payload).then(function (r) {
			if (!r.ok || !r.json) {
				tqStatus(t('suggestFail') + ' HTTP error', 'is-err');
				return;
			}
			if (r.json.has_sources === false) {
				tqStatus(t('noSources'), 'is-err');
				return;
			}
			var items = r.json.suggestions || [];
			if (!items.length) {
				if (!append) {
					list.hidden = true;
					actions.hidden = true;
				}
				tqStatus(t('noneFound') + sourceReport(r.json.sources));
				return;
			}
			if (!append) {
				while (list.firstChild) { list.removeChild(list.firstChild); }
			}
			items.forEach(function (item) { renderSuggestionRow(list, item); });
			list.hidden = false;
			actions.hidden = false;
			tqStatus(sourceReport(r.json.sources).replace(/^ — /, ''), 'is-ok');
		}).catch(function (err) {
			tqStatus(t('suggestFail') + ' ' + (err && err.message ? err.message : ''), 'is-err');
		});
	}

	function bindSuggest() {
		var btn = document.getElementById('aipc-tq-suggest-btn');
		if (btn) {
			btn.addEventListener('click', function () { fetchSuggestions(false); });
		}
		var more = document.getElementById('aipc-tq-more-btn');
		if (more) {
			more.addEventListener('click', function () { fetchSuggestions(true); });
		}
	}

	function bindAddSelected() {
		var btn = document.getElementById('aipc-tq-add-selected');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var list = document.getElementById('aipc-tq-suggest-list');
			var texts = [];
			if (list) {
				list.querySelectorAll('input[type=checkbox]:checked').forEach(function (box) {
					texts.push(box.value);
				});
			}
			if (!texts.length) { return; }
			tqStatus(t('adding'));
			api('topics/add', { texts: texts, source: 'rss' }).then(function (r) {
				if (r.ok && r.json && r.json.added > 0) {
					tqStatus(t('added'), 'is-ok');
					window.setTimeout(function () { window.location.reload(); }, 600);
				} else {
					var msg = (r.json && (r.json.message || r.json.error)) || '';
					tqStatus(t('addFail') + ' ' + msg, 'is-err');
				}
			}).catch(function (err) {
				tqStatus(t('addFail') + ' ' + (err && err.message ? err.message : ''), 'is-err');
			});
		});
	}

	bindTest();
	bindChatId();
	bindSuggest();
	bindAddSelected();
})();
