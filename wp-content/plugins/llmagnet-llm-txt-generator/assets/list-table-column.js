/**
 * LLM Score list-table column — pending badge fill-in.
 *
 * Collects every `.llmagnet-score-pending[data-post-id]` placeholder on the
 * list table, makes EXACTLY ONE batch call to
 * GET /llm-analytics/v1/scores?post_ids=… and replaces placeholders whose
 * score exists. IDs without a stored score stay as an em dash — the endpoint
 * has already queued them for priority backfill, so they appear on the next
 * visit. No React, no per-row requests (adoption plan Feature 1).
 *
 * Badge markup/classes mirror List_Table_Columns::badge_html() in PHP.
 */
(function () {
	'use strict';

	var config = window.llmagnetListTableColumn;
	if (!config || !config.restUrl) {
		return;
	}

	function band(score) {
		if (score >= 90) {
			return 'green';
		}
		if (score >= 50) {
			return 'orange';
		}
		return 'red';
	}

	function sprintfD(template, value) {
		return String(template).replace('%d', String(value));
	}

	/**
	 * Build the badge element (same structure as the server-rendered one).
	 *
	 * @param {number} score  Score 0-100.
	 * @param {string} fixUrl Drawer deep-link ('' = no Fix link).
	 * @return {DocumentFragment}
	 */
	function buildBadge(score, fixUrl) {
		score = Math.max(0, Math.min(100, Math.round(score)));

		var fragment = document.createDocumentFragment();

		var badge = document.createElement('span');
		badge.className = 'llmagnet-score-badge llmagnet-score-badge--' + band(score);
		badge.setAttribute('role', 'img');
		badge.setAttribute('aria-label', sprintfD(config.i18n.scoreLabel, score));

		var dot = document.createElement('span');
		dot.className = 'llmagnet-score-badge__dot';
		dot.setAttribute('aria-hidden', 'true');
		badge.appendChild(dot);
		badge.appendChild(document.createTextNode(String(score)));
		fragment.appendChild(badge);

		if (fixUrl && score < 100) {
			fragment.appendChild(document.createTextNode(' '));
			var fix = document.createElement('a');
			fix.className = 'llmagnet-score-fix';
			fix.href = fixUrl;
			fix.title = config.i18n.fixTitle;
			fix.textContent = config.i18n.fix;
			fragment.appendChild(fix);
		}

		return fragment;
	}

	function run() {
		var placeholders = document.querySelectorAll('.llmagnet-score-pending[data-post-id]');
		if (!placeholders.length) {
			return;
		}

		var byId = {};
		Array.prototype.forEach.call(placeholders, function (el) {
			var id = parseInt(el.getAttribute('data-post-id'), 10);
			if (id > 0) {
				if (!byId[id]) {
					byId[id] = [];
				}
				byId[id].push(el);
			}
		});

		var ids = Object.keys(byId);
		if (!ids.length) {
			return;
		}

		var url = config.restUrl
			+ (config.restUrl.indexOf('?') === -1 ? '?' : '&')
			+ 'post_ids=' + encodeURIComponent(ids.join(','))
			+ '&context=list-table';

		window
			.fetch(url, {
				headers: { 'X-WP-Nonce': config.nonce },
				credentials: 'same-origin'
			})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('llmagnet scores request failed: ' + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				var scores = data && data.scores ? data.scores : {};
				ids.forEach(function (id) {
					var row = scores[id];
					if (!row || typeof row.score !== 'number') {
						return; // Never computed yet — endpoint queued it; keep the em dash.
					}
					byId[id].forEach(function (el) {
						var fixUrl = el.getAttribute('data-fix-url') || '';
						el.parentNode.replaceChild(buildBadge(row.score, fixUrl), el);
					});
				});
			})
			.catch(function () {
				// Leave placeholders as-is; scores appear on the next visit.
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
