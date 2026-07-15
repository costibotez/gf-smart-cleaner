(function () {
	'use strict';

	var cfg = window.gfscData || {};
	var i18n = cfg.i18n || {};
	var MAX_PASSES = 50;

	var runButton = document.getElementById('gfsc-run');
	var previewButton = document.getElementById('gfsc-preview');
	var progress = document.getElementById('gfsc-progress');
	var results = document.getElementById('gfsc-preview-results');

	function sprintf(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var index = 0;
		return String(template).replace(/%(\d+\$)?s/g, function (match, position) {
			if (position) {
				return String(args[parseInt(position, 10) - 1]);
			}
			return String(args[index++]);
		});
	}

	function setBusy(busy) {
		if (runButton) runButton.disabled = busy;
		if (previewButton) previewButton.disabled = busy;
	}

	function addMessage(container, tag, text) {
		var el = document.createElement(tag);
		el.textContent = text;
		container.insertBefore(el, container.firstChild);
	}

	async function post(action, params) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		body.set('form_id', cfg.formId);
		Object.keys(params || {}).forEach(function (key) {
			body.set(key, params[key]);
		});

		var response = await fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		});
		if (!response.ok) {
			throw new Error('HTTP ' + response.status);
		}
		var data = await response.json();
		if (!data || data.success !== true) {
			throw new Error((data && data.data && data.data.message) || 'Request failed');
		}
		return data.data;
	}

	async function runCleanup() {
		setBusy(true);
		progress.textContent = '';
		results.textContent = '';
		addMessage(progress, 'p', i18n.cleaning || 'Cleaning…');

		var totalDeleted = 0;
		var offset = 0;

		try {
			for (var pass = 1; pass <= MAX_PASSES; pass++) {
				var data = await post('gfsc_run', { offset: offset });
				totalDeleted += data.deleted;
				offset = data.next_offset;
				addMessage(progress, 'p', sprintf(i18n.passResult || 'Pass #%s: deleted %s, blocked %s.', pass, data.deleted, data.blocked));
				if (data.scanned === 0 || offset >= data.total) {
					break;
				}
			}
			addMessage(progress, 'h4', '✅ ' + sprintf(i18n.cleanupDone || 'Cleanup complete. Total deleted: %s', totalDeleted));
		} catch (err) {
			addMessage(progress, 'p', (i18n.error || 'Error:') + ' ' + err.message);
		} finally {
			setBusy(false);
		}
	}

	function renderPreviewTable(candidates) {
		var table = document.createElement('table');
		table.className = 'widefat striped';
		table.style.maxWidth = '900px';

		var thead = document.createElement('thead');
		var headRow = document.createElement('tr');
		[i18n.colEntry || 'Entry ID', i18n.colEmail || 'Email', i18n.colDate || 'Date', i18n.colReason || 'Reason'].forEach(function (label) {
			var th = document.createElement('th');
			th.textContent = label;
			headRow.appendChild(th);
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		candidates.forEach(function (candidate) {
			var row = document.createElement('tr');
			[candidate.id, candidate.email, candidate.date_created, candidate.reason].forEach(function (value) {
				var td = document.createElement('td');
				td.textContent = value == null ? '' : String(value);
				row.appendChild(td);
			});
			tbody.appendChild(row);
		});
		table.appendChild(tbody);

		results.appendChild(table);
	}

	async function runPreview() {
		setBusy(true);
		progress.textContent = '';
		results.textContent = '';
		addMessage(progress, 'p', i18n.previewing || 'Scanning…');

		try {
			var data = await post('gfsc_preview', {});
			progress.textContent = '';
			addMessage(progress, 'p', sprintf(i18n.previewDone || 'Found %s candidates out of %s entries.', data.candidates.length, data.scanned));
			if (data.candidates.length === 0) {
				addMessage(results, 'p', i18n.noCandidates || 'No spam candidates found.');
			} else {
				renderPreviewTable(data.candidates);
			}
		} catch (err) {
			addMessage(progress, 'p', (i18n.error || 'Error:') + ' ' + err.message);
		} finally {
			setBusy(false);
		}
	}

	if (runButton) runButton.addEventListener('click', runCleanup);
	if (previewButton) previewButton.addEventListener('click', runPreview);
})();
