/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Core export / import pipeline driver. Exposes window.ISX so isx-storage.js
 * (provider cards, Storage settings, backups list) can reuse the same poll /
 * upload logic instead of duplicating it.
 */
(function ($) {
	'use strict';

	/**
	 * Translate a UI string via the map ISX_Admin::js_i18n() localizes into
	 * isx.i18n (keyed by the English source), then fill %s / %d / %1$s
	 * placeholders from the remaining arguments.
	 */
	function t(text) {
		var args = arguments;
		var next = 1;
		var map = (window.isx && window.isx.i18n) || {};
		return (map[text] || text).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
			return String(pos ? args[parseInt(pos, 10)] : args[next++]);
		});
	}

	function post(action, data) {
		return $.post(
			isx.ajax_url,
			$.extend({ action: action, nonce: isx.nonce }, data || {})
		);
	}

	/**
	 * Beacon a browser-side event into the server log (see ajax_client_log).
	 * Fire-and-forget on purpose: this runs precisely when the connection is
	 * misbehaving, so it must never retry or surface an error of its own.
	 */
	function logToServer(level, message, context) {
		// Failures always go through; the routine per-poll trace only when
		// verbose logging is on, so normal runs don't pay an extra request
		// for every poll.
		if (level === 'debug' && !isx.verbose) {
			return;
		}
		try {
			post('isx_client_log', {
				level: level,
				message: message,
				context: context || {}
			});
		} catch (e) {
			/* logging must never break the pipeline */
		}
	}

	/**
	 * Everything about a failed request that helps identify who killed it.
	 * The status is the giveaway — 522/524/520 is a reverse proxy timing out on
	 * a slow origin, 403 with a Cloudflare body is a firewall rule, 0 means the
	 * connection died before any response arrived.
	 */
	function describeFailure(jqXHR, textStatus, errorThrown) {
		var body = '';
		var cfRay = '';
		try {
			body = (jqXHR.responseText || '').replace(/\s+/g, ' ').slice(0, 500);
			cfRay = jqXHR.getResponseHeader('cf-ray') || '';
		} catch (e) {
			/* cross-origin or aborted XHR — headers unreadable */
		}
		return {
			status: jqXHR && typeof jqXHR.status !== 'undefined' ? jqXHR.status : -1,
			text_status: textStatus || '',
			error: errorThrown ? String(errorThrown) : '',
			cf_ray: cfRay,
			body: body
		};
	}

	// Give up rather than hammer a site that is clearly not coming back. The
	// old code polled forever with the same "a firewall/proxy may be blocking it"
	// line, which sent people chasing a firewall while the real answer — a
	// server-side 502/504 or an expired nonce — was sitting in jqXHR.status.
	var POLL_FAIL_LIMIT = 30;

	/**
	 * Turn a failed poll into something that names the actual cause.
	 */
	function failureMessage(jqXHR, streak) {
		var status = jqXHR && typeof jqXHR.status !== 'undefined' ? jqXHR.status : 0;

		if (status === 502 || status === 503 || status === 504) {
			return t('The server dropped the connection midway (HTTP %s) — the job took longer than the server allows (see the Log page)', status);
		}
		if (status === 403) {
			return t('Session expired (HTTP 403) — please refresh this page and start again');
		}
		if (status === 500) {
			return t('Server error (HTTP 500) — see the Log page for details');
		}
		if (status === 0) {
			return t('Cannot reach the server — the connection dropped or the server is not responding');
		}
		if (streak >= 3) {
			return t('Connection failed %1$s times in a row (HTTP %2$s) — see the Log page', streak, status);
		}
		return t('Connection failed');
	}

	// Jobs the user cancelled, by id. Kept for the life of the page: the flag has
	// to outlive the in-flight poll request it is silencing.
	var cancelledJobs = {};

	// The job poll() is currently driving, so a "Cancel" button can act on it
	// without every caller having to thread the id and secret through itself.
	var activeJob = null;

	/**
	 * Stop a job at the user's request. Tells the server to end it (which also
	 * releases any half-finished upload sitting on the bucket), silences this
	 * page's poll loop for that job, then reports the outcome through the same
	 * onDone the poll would have used.
	 */
	function cancelJob(job, secret, onDone) {
		cancelledJobs[job] = true;
		activeJob = null;

		return post('isx_job_cancel', { job: job, secret: secret })
			.done(function (res) {
				var data = (res && res.data) || {};
				data.done = true;
				// A cancelled run is not a success, and the UI's red-message
				// path is the honest place for it — but never overwrite a
				// server message saying the job had already finished on its own.
				if (!res || !res.success) {
					data.error = true;
					if (!data.message) {
						data.message = t('Could not cancel');
					}
				} else {
					data.error = data.cancelled === true;
				}
				onDone(data);
			})
			.fail(function () {
				onDone({ done: true, error: true, message: t('Could not cancel') });
			});
	}

	/**
	 * Poll isx_run until done. Calls onTick(result) every step and
	 * onDone(result) once finished (result.done === true).
	 */
	function poll(job, secret, onTick, onDone) {
		activeJob = { job: job, secret: secret };
		var failStreak = 0;
		var startedAt = Date.now();
		var lastPhase = '';
		// Set once cancelJob() has taken over reporting for this job, so neither
		// the next tick nor an already in-flight response drives it further or
		// overwrites the cancellation with a stale status.
		var cancelled = function () {
			return cancelledJobs[job] === true;
		};
		// Adaptive poll spacing. A step that reports the same progress and
		// message as last time has nothing new to say, and asking again 200ms
		// later just spends a PHP worker that the running step needs — during a
		// multi-minute upload that was ~300 pointless requests a minute. Back off
		// while nothing changes, snap back to responsive the moment it does.
		var POLL_MIN_MS = 300;
		var POLL_MAX_MS = 2000;
		var pollDelay = POLL_MIN_MS;
		var lastSignature = null;

		function step() {
			if (cancelled()) {
				return;
			}
			var sentAt = Date.now();

			post('isx_run', { job: job, secret: secret })
				.done(function (res) {
					if (cancelled()) {
						return;
					}
					if (!res.success) {
						// Server-side errors are terminal — stop polling and let the
						// caller show the message instead of spinning forever.
						var data = res.data || {};
						data.error = true;
						data.done = true;
						onDone(data);
						return;
					}

					// The package is password-protected — pause and ask for it,
					// then resume the same job once decrypted.
					if (res.data.needs_password) {
						var password = window.prompt(res.data.message || t('Please enter the password'));
						if (password === null || password === '') {
							// End the job on the server too — left alone it sat
							// waiting for a password until the stall cleanup.
							cancelJob(job, secret, function () {
								onDone({ error: true, done: true, message: t('Import cancelled') });
							});
							return;
						}
						post('isx_import_decrypt', { job: job, secret: secret, password: password })
							.done(function (res2) {
								if (res2 && res2.success) {
									step();
								} else {
									onDone({ error: true, done: true, message: (res2 && res2.data && res2.data.message) || t('Incorrect password') });
								}
							})
							.fail(function () {
								onDone({ error: true, done: true, message: t('An error occurred') });
							});
						return;
					}

					failStreak = 0;

					var phaseChanged = !!res.data.phase && res.data.phase !== lastPhase;
					lastPhase = res.data.phase || lastPhase;

					var signature = lastPhase + '|' + res.data.progress + '|' + (res.data.message || '');
					if (signature === lastSignature) {
						pollDelay = Math.min(POLL_MAX_MS, Math.round(pollDelay * 1.5));
					} else {
						pollDelay = POLL_MIN_MS;
					}
					lastSignature = signature;

					// A request that suddenly takes far longer than the others is
					// the one about to get cut off — worth seeing in the log
					// alongside the failure that follows it. Only on a phase
					// change, though: beaconing every poll doubled the request
					// count for a line that repeated itself.
					if (phaseChanged) {
						logToServer('debug', t('Poll succeeded'), {
							job: job,
							phase: lastPhase,
							progress: res.data.progress,
							took_ms: Date.now() - sentAt
						});
					}

					onTick(res.data);
					if (res.data.done) {
						onDone(res.data);
					} else {
						setTimeout(step, pollDelay);
					}
				})
				.fail(function (jqXHR, textStatus, errorThrown) {
					if (cancelled()) {
						// Cancelling aborts the job server-side, so the poll
						// failing right afterwards is expected, not a fault.
						return;
					}
					failStreak++;

					var info = describeFailure(jqXHR, textStatus, errorThrown);
					info.job = job;
					info.phase = lastPhase;
					info.streak = failStreak;
					info.took_ms = Date.now() - sentAt;
					info.elapsed_s = Math.round((Date.now() - startedAt) / 1000);

					// The poll retries every second, so a connection that stays
					// down would otherwise write a log line per second forever.
					// The first few and then a periodic sample tell the same story.
					if (failStreak <= 5 || failStreak % 10 === 0) {
						logToServer('error', t('Poll failed (connection failed)'), info);
					}

					// Isolated blips recover on the next poll; a sustained run
					// means the site is not going to answer, so stop instead of
					// spinning up a four-figure failure count.
					if (failStreak >= POLL_FAIL_LIMIT) {
						logToServer('error', t('Stopped polling: too many consecutive failures'), info);
						onDone({
							error: true,
							done: true,
							message:
								failureMessage(jqXHR, failStreak) +
								t(' — stopped waiting after %s retries. The job may still be running on the server. Refresh this page to see the latest status', failStreak)
						});
						return;
					}

					onTick({
						message: failureMessage(jqXHR, failStreak),
						progress: 0
					});
					setTimeout(step, 1000);
				});
		}
		step();
	}

	/**
	 * Read the find & replace pairs + advanced options from the export page
	 * into a plain object ready to merge into startExport()'s `extra`. Returns
	 * null (after alerting) if the encrypt-password fields don't validate.
	 */
	function collectExportOptions() {
		var replaceOld = [];
		var replaceNew = [];
		$('.isx-fr-row').each(function () {
			var oldVal = $.trim($(this).find('.isx-fr-old').val() || '');
			var newVal = $(this).find('.isx-fr-new').val() || '';
			if (oldVal !== '') {
				replaceOld.push(oldVal);
				replaceNew.push(newVal);
			}
		});

		var extra = { replace_old: replaceOld, replace_new: replaceNew };

		$('.isx-adv-checkbox').each(function () {
			var key = $(this).data('option');
			// Keys starting with "_" are picker toggles, not real export options.
			if (key && key.charAt(0) !== '_') {
				extra[key] = $(this).is(':checked') ? 1 : '';
			}
		});

		extra.compression = $('.isx-opt-compression:checked').val() || 'none';

		var selectedTables = [];
		$('#isx-tables-picker-list input[type="checkbox"]:checked').each(function () {
			selectedTables.push($(this).val());
		});
		extra.exclude_selected_tables = selectedTables;

		var filesText = $('#isx-files-picker-textarea').val() || '';
		extra.exclude_selected_files = filesText
			.split('\n')
			.map(function (line) {
				return $.trim(line);
			})
			.filter(function (line) {
				return line !== '';
			});

		if (extra.encrypt) {
			var pw = $('#isx-encrypt-password').val() || '';
			var pw2 = $('#isx-encrypt-password-confirm').val() || '';
			if (pw === '') {
				window.alert(t('Please enter an encryption password'));
				return null;
			}
			if (pw !== pw2) {
				window.alert(t('Passwords do not match'));
				return null;
			}
			extra.encrypt_password = pw;
		}

		return extra;
	}

	$(document).on('click', '#isx-fr-add', function () {
		var $row = $('.isx-fr-row').first().clone();
		$row.find('input').val('');
		// Only rows added after the first one are removable.
		if (!$row.find('.isx-fr-remove').length) {
			$row.append(
				$('<button type="button" class="isx-fr-remove" title="' + t('Remove this row') + '">&times;</button>')
			);
		}
		$('.isx-findreplace').append($row);
	});

	$(document).on('click', '.isx-fr-remove', function () {
		$(this).closest('.isx-fr-row').remove();
	});

	$(document).on('change', '#isx-opt-encrypt', function () {
		$('#isx-encrypt-fields').toggle($(this).is(':checked'));
	});

	/* ---------------- Advanced options: table / file pickers ---------------- */

	var tablesLoaded = false;

	function updateTablesButtonLabel() {
		var count = $('#isx-tables-picker-list input[type="checkbox"]:checked').length;
		$('#isx-tables-picker-btn').text(count > 0 ? t('%s tables selected', count) : t('No tables selected'));
	}

	function loadTablesPicker() {
		if (tablesLoaded) {
			return;
		}
		tablesLoaded = true;
		post('isx_list_tables').done(function (res) {
			if (!res || !res.success) {
				$('#isx-tables-picker-list').html('<p class="isx-fetch-status is-error">' + t('Could not load the table list') + '</p>');
				return;
			}
			var tables = res.data.tables || [];
			var $list = $('#isx-tables-picker-list').empty();
			// Built as elements (.val/.text), not concatenated HTML: a table
			// name is just data and must never be parsed as markup.
			tables.forEach(function (table) {
				$list.append(
					$('<label class="isx-checkbox-row">').append(
						$('<input type="checkbox">').val(table.name),
						$('<span>').text(table.name + ' ').append(
							$('<span class="isx-muted">').text('(' + t('%s rows', table.rows) + ')')
						)
					)
				);
			});
			if (!tables.length) {
				$list.html('<p class="isx-fetch-status">' + t('No tables found') + '</p>');
			}
		});
	}

	$(document).on('click', '#isx-tables-picker-btn', function () {
		$('#isx-opt-select-tables').prop('checked', true);
		$('#isx-tables-picker').toggle();
		loadTablesPicker();
	});
	$(document).on('change', '#isx-opt-select-tables', function () {
		var checked = $(this).is(':checked');
		$('#isx-tables-picker').toggle(checked);
		if (checked) {
			loadTablesPicker();
		}
	});
	$(document).on('change', '#isx-tables-picker-list input[type="checkbox"]', updateTablesButtonLabel);

	function updateFilesButtonLabel() {
		var count = $('#isx-files-picker-textarea').val()
			.split('\n')
			.map(function (line) { return $.trim(line); })
			.filter(function (line) { return line !== ''; }).length;
		$('#isx-files-picker-btn').text(count > 0 ? t('%s items selected', count) : t('No files selected'));
	}

	$(document).on('click', '#isx-files-picker-btn', function () {
		$('#isx-opt-select-files').prop('checked', true);
		$('#isx-files-picker').toggle();
	});
	$(document).on('change', '#isx-opt-select-files', function () {
		$('#isx-files-picker').toggle($(this).is(':checked'));
	});
	$(document).on('input', '#isx-files-picker-textarea', updateFilesButtonLabel);

	/**
	 * Create a job (export start / import create) with automatic retry.
	 * Job creation can fail transiently — e.g. right after the site starts,
	 * before MySQL/disk are fully ready — and previously a single failed
	 * attempt dead-ended straight to an error with no retry at all. Network
	 * failures (.fail()) are retried the same as a server-side success:false,
	 * since both are typically the same kind of "not ready yet" blip.
	 *
	 * @param {string}   action  isx_export_start | isx_import_create
	 * @param {object}   extra   Extra POST fields.
	 * @param {function} onRetry (message, attempt, maxAttempts) — called before each retry.
	 * @param {function} onReady (job, secret) — called once creation succeeds.
	 * @param {function} onGiveUp (message) — called once all attempts are exhausted.
	 */
	var JOB_CREATE_MAX_ATTEMPTS = 3;
	function createJob(action, extra, onRetry, onReady, onGiveUp) {
		var attempt = 0;

		function attemptCreate() {
			attempt++;
			post(action, extra || {})
				.done(function (res) {
					if (res.success) {
						onReady(res.data.job, res.data.secret);
						return;
					}
					fail((res.data && res.data.message) || t('Could not start'));
				})
				.fail(function () {
					fail(t('Could not connect to the server'));
				});
		}

		function fail(message) {
			if (attempt < JOB_CREATE_MAX_ATTEMPTS) {
				onRetry(message, attempt, JOB_CREATE_MAX_ATTEMPTS);
				setTimeout(attemptCreate, 1000 * attempt);
				return;
			}
			onGiveUp(message);
		}

		attemptCreate();
	}

	/**
	 * Start a brand-new export job. `extra` may include { to_storage: slug }.
	 */
	function startExport(extra, onTick, onDone) {
		createJob(
			'isx_export_start',
			extra,
			function (message, attempt, maxAttempts) {
				onTick({ message: t('%1$s — retrying (%2$s/%3$s)...', message, attempt, maxAttempts), progress: 0 });
			},
			function (job, secret) {
				poll(job, secret, onTick, onDone);
			},
			function (message) {
				onDone({ error: true, done: true, message: message });
			}
		);
	}

	/**
	 * Upload a File object as chunks to an already-created import job.
	 * A chunk that fails (network hiccup or a transient server error) is
	 * retried a few times with a short backoff before giving up for good —
	 * previously any single failed chunk silently stalled the upload forever.
	 */
	function uploadChunks(job, file, onProgress, done, onFail) {
		// wp_localize_script() casts scalars to strings, so force numeric here —
		// otherwise `start + size` concatenates and the 2nd chunk slices to EOF.
		var size = parseInt(isx.chunk_size, 10) || 4 * 1024 * 1024;
		var total = Math.max(1, Math.ceil(file.size / size));
		var index = 0;
		var attempt = 0;
		var MAX_ATTEMPTS = 5;

		function send() {
			var start = index * size;
			var blob = file.slice(start, Math.min(start + size, file.size));
			var fd = new FormData();
			fd.append('action', 'isx_import_chunk');
			fd.append('nonce', isx.nonce);
			fd.append('job', job);
			// Where this chunk belongs, so a retry after a lost response
			// overwrites its first attempt instead of appending a second copy.
			fd.append('offset', start);
			fd.append('chunk', blob, file.name);

			$.ajax({
				url: isx.ajax_url,
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false
			})
				.done(function (res) {
					if (!res.success) {
						retryOrFail((res.data && res.data.message) || t('Upload failed'));
						return;
					}
					attempt = 0;
					index++;
					var pct = Math.round((index / total) * 100);
					onProgress({ message: t('Uploading...'), percent: pct });
					if (index < total) {
						send();
					} else {
						done();
					}
				})
				.fail(function () {
					retryOrFail(t('Upload failed'));
				});
		}

		function retryOrFail(message) {
			attempt++;
			if (attempt < MAX_ATTEMPTS) {
				onProgress({ message: t('%1$s — retrying (%2$s/%3$s)...', message, attempt, MAX_ATTEMPTS) });
				setTimeout(send, 1000 * attempt);
				return;
			}
			onProgress({ message: message });
			if (typeof onFail === 'function') {
				onFail(message);
			}
		}

		send();
	}

	function downloadUrl(backupName) {
		// Direct link straight to the file, served by the web server — no
		// PHP-FPM timeout, no memory limit, Range/resume for free. Falls back
		// to streaming through admin-ajax.php only when isx.backups_url is
		// null (custom storage path outside the web root — see
		// ISX_Backups::base_url()).
		if (isx.backups_url) {
			return isx.backups_url + encodeURIComponent(backupName);
		}
		return isx.ajax_url + '?action=isx_download&backup=' + encodeURIComponent(backupName) + '&nonce=' + encodeURIComponent(isx.nonce);
	}

	/* ---------------- Step-by-step progress UI ---------------- */

	// Labels for the raw `phase` keys the server (class-isx-admin.php
	// phase_range()) and the client-side chunk upload report. "pack_meta"
	// and "files" are two server-side steps but shown as one row here since
	// to the user they're both just "packing the files".
	var STEP_LABELS = {
		export: {
			init: t('Preparing'),
			database: t('Exporting database'),
			pack: t('Packing files'),
			finalize: t('File created'),
			upload: t('Uploading to Storage')
		},
		import: {
			upload: t('Uploading file'),
			init: t('Preparing'),
			verify: t('Verifying package'),
			clean: t('Clearing existing files'),
			extract: t('Restoring files'),
			database: t('Importing database'),
			finalize: t('Finalizing')
		}
	};

	function exportStepKeys(hasStorage) {
		var keys = ['init', 'database', 'pack', 'finalize'];
		if (hasStorage) {
			keys.push('upload');
		}
		return keys;
	}

	function importStepKeys(hasUpload) {
		var keys = [];
		if (hasUpload) {
			keys.push('upload');
		}
		return keys.concat(['init', 'verify', 'clean', 'extract', 'database', 'finalize']);
	}

	/**
	 * Build the single step row inside a .isx-progress-box — call once,
	 * right before a job starts, before the first updateProgress(). Only one
	 * step is ever shown at a time: its bar fills 0→100%, then the label and
	 * bar reset for the next step (see updateSteps).
	 */
	function renderSteps($box, type, keys) {
		var html = '<div class="isx-steps">' +
			'<div class="isx-step is-active">' +
			'<div class="isx-step-head">' +
			'<span class="isx-step-label"></span>' +
			'<span class="isx-step-pct">0.00%</span>' +
			'</div>' +
			'<div class="isx-step-bar"><div class="isx-step-bar-fill"></div></div>' +
			'</div>' +
			'</div>';
		// Any tween still running for the previous job would keep writing into
		// the row this is about to replace — and, holding a stale shown value
		// and a stale update-gap measurement, would fight the new job's first
		// update.
		resetTweens($box);
		$box.find('.isx-steps').remove();
		var $warning = $box.find('.isx-progress-warning');
		if ($warning.length) {
			$warning.after(html);
		} else {
			$box.prepend(html);
		}
		$box.data('steps', keys);
		$box.data('labels', STEP_LABELS[type]);
	}

	// The server reports "pack_meta" and "files" as separate steps; both map
	// to the single "Packing files" row.
	function stepKeyFromPhase(phase) {
		return (phase === 'pack_meta' || phase === 'files') ? 'pack' : phase;
	}

	var prefersReducedMotion = window.matchMedia
		? window.matchMedia('(prefers-reduced-motion: reduce)').matches
		: false;

	/* ---------------- Progress tweening ---------------- */

	/*
	 * Why any of this exists.
	 *
	 * A step runs server-side for a whole time budget before it answers
	 * (~10s, ISX_Export::STEP_TIME_BUDGET), so the real figures — the phase
	 * percentage and the "429900/688696 items" counter in the status line —
	 * only change about once every ten seconds. Every poll in between echoes
	 * the previous numbers back verbatim.
	 *
	 * Printing that raw is what the eye reads as a stall: ten seconds frozen,
	 * then a leap of several percent and a couple hundred thousand items at
	 * once. The fix is to spread each leap across the gap it arrived over, so
	 * the digits move on every single frame and land on the true figure just
	 * as the next one turns up.
	 *
	 * That means the animation is paced by MEASURED arrival times, not by a
	 * fixed rate. An earlier version eased at a constant 12% of the remaining
	 * gap per frame, which covered the distance in about half a second and
	 * then sat still for the other nine and a half — the same stutter, just
	 * with a flourish at the start of it. Timing each run to the last observed
	 * gap is the whole difference between "jumps" and "counts up".
	 *
	 * Interpolation is linear on purpose. An ease-out sprints then crawls,
	 * and a counter crawling at the end is indistinguishable from one that has
	 * stopped — precisely the impression this is here to avoid. Constant speed
	 * is what reads as "working".
	 */

	// Bounds on how long one tween may take. The lower bound keeps a fast
	// sequence of updates from turning into a blur; the upper stops a long
	// stall (a slow disk, a paused job) from committing us to a minute-long
	// crawl toward a figure that may never be confirmed.
	var TWEEN_MIN_MS = 400;
	var TWEEN_MAX_MS = 15000;
	// Used for the very first update, before there are two arrivals to measure
	// a gap between.
	var TWEEN_DEFAULT_MS = 800;
	// Aim to land slightly AFTER the next update is due rather than slightly
	// before. Arriving early leaves dead air, which is the problem; arriving
	// late is invisible, because the next update re-aims from wherever the
	// value currently sits — the shortfall is corrected, never accumulated.
	var TWEEN_OVERSHOOT = 1.15;

	var prefersReducedMotion = window.matchMedia
		? window.matchMedia('(prefers-reduced-motion: reduce)').matches
		: false;

	var animationsDisabled = prefersReducedMotion || !window.requestAnimationFrame;

	/**
	 * The tween bag for a progress container, created on first use. Each entry
	 * is one animated number, keyed by name ('pct', 'count', ...).
	 */
	function tweensOf($host) {
		var tweens = $host.data('isxTweens');
		if (!tweens) {
			tweens = {};
			$host.data('isxTweens', tweens);
		}
		return tweens;
	}

	/**
	 * How long the next tween on this container should run for: the gap
	 * between the last two distinct server updates, clamped. noteUpdate()
	 * maintains the measurement.
	 */
	function tweenDuration($host) {
		var gap = parseFloat($host.data('isxUpdateGap'));
		if (!gap) {
			gap = TWEEN_DEFAULT_MS;
		}
		return Math.max(TWEEN_MIN_MS, Math.min(TWEEN_MAX_MS, gap * TWEEN_OVERSHOOT));
	}

	/**
	 * Record that a genuinely new set of figures just arrived, and measure how
	 * long it took to get here.
	 *
	 * Only distinct updates count. The poll backs off and re-sends while the
	 * server repeats itself (see poll()), and letting those echoes reset the
	 * clock would drag the measured gap down towards the poll interval — which
	 * would pace the tweens to finish in a fraction of a second again.
	 *
	 * @return {boolean} Whether this update was distinct from the last.
	 */
	function noteUpdate($host, signature) {
		if ($host.data('isxLastSig') === signature) {
			return false;
		}
		var now = Date.now();
		var last = parseFloat($host.data('isxLastUpdateAt'));
		if (last) {
			$host.data('isxUpdateGap', now - last);
		}
		$host.data('isxLastUpdateAt', now);
		$host.data('isxLastSig', signature);
		return true;
	}

	/**
	 * Advance every tween on a container by one frame, write whatever changed,
	 * and re-arm only while something is still moving.
	 */
	function tweenFrame($host) {
		var tweens = $host.data('isxTweens');
		if (!tweens) {
			$host.data('pctRaf', null);
			return;
		}

		var now = Date.now();
		var moving = false;

		for (var key in tweens) {
			if (!Object.prototype.hasOwnProperty.call(tweens, key)) {
				continue;
			}
			var t = tweens[key];
			var progress = t.duration > 0 ? (now - t.startedAt) / t.duration : 1;
			if (progress >= 1) {
				progress = 1;
			} else {
				moving = true;
			}
			t.shown = t.from + (t.to - t.from) * progress;
			writeTween($host, t);
		}

		if (!moving) {
			$host.data('pctRaf', null);
			return;
		}
		$host.data('pctRaf', window.requestAnimationFrame(function () {
			tweenFrame($host);
		}));
	}

	/**
	 * Render one tween's current value, skipping the DOM entirely when the
	 * text it would produce is what is already on screen. Most frames of a
	 * slow-moving percentage produce no change at all, and a settled tween
	 * produces none ever — this is what keeps a continuously running frame
	 * loop cheap.
	 */
	function writeTween($host, t) {
		var text = t.render(t.shown);
		if (text === t.lastText) {
			return;
		}
		t.lastText = text;
		t.apply($host, t.shown, text);
	}

	/**
	 * Point one tween at a new value.
	 *
	 * @param {jQuery}   $host   Container holding the tween state and the RAF handle.
	 * @param {string}   key     Tween name.
	 * @param {number}   to      Target value.
	 * @param {object}   spec    { render(value) -> string, apply($host, value, text) }
	 * @param {boolean}  snap    Skip the animation and jump straight there.
	 */
	function setTween($host, key, to, spec, snap) {
		var tweens = tweensOf($host);
		var t = tweens[key];

		if (!t) {
			t = tweens[key] = { shown: to, from: to, to: to, startedAt: 0, duration: 0, lastText: null };
			snap = true;
		}
		t.render = spec.render;
		t.apply = spec.apply;

		if (snap || animationsDisabled) {
			t.from = t.to = t.shown = to;
			t.startedAt = 0;
			t.duration = 0;
			writeTween($host, t);
			return;
		}

		if (to === t.to) {
			return; // Same target — let the run already in flight finish it.
		}

		t.from = t.shown;
		t.to = to;
		t.startedAt = Date.now();
		t.duration = tweenDuration($host);

		if (!$host.data('pctRaf')) {
			$host.data('pctRaf', window.requestAnimationFrame(function () {
				tweenFrame($host);
			}));
		}
	}

	/**
	 * Drop a container's tween state — used when the markup those tweens write
	 * into is about to be replaced.
	 */
	function resetTweens($host) {
		if ($host.data('pctRaf')) {
			window.cancelAnimationFrame($host.data('pctRaf'));
			$host.data('pctRaf', null);
		}
		$host.removeData('isxTweens');
		$host.removeData('isxLastSig');
		$host.removeData('isxLastUpdateAt');
		$host.removeData('isxUpdateGap');
		$host.removeData('isxStatusParts');
	}

	var pctTweenSpec = {
		render: function (value) {
			return value.toFixed(2) + '%';
		},
		apply: function ($host, value, text) {
			$host.find('.isx-step-bar-fill').css('width', value + '%');
			$host.find('.isx-step-pct').text(text);
		}
	};

	/**
	 * Point the step row at a new percentage. Pass reset:true when the phase
	 * changed, to start the new phase's bar from empty rather than sliding
	 * down from the previous one's leftover width.
	 */
	function setStepPct($box, pct, reset) {
		var tweens = tweensOf($box);
		if (reset) {
			// Land on 0 instantly, then let the incoming figure animate up from
			// there — the new phase's bar visibly fills from empty.
			setTween($box, 'pct', 0, pctTweenSpec, true);
		} else if (tweens.pct && pct < tweens.pct.to) {
			return; // Stale echo — never rewind a bar the user already saw.
		}
		setTween($box, 'pct', pct, pctTweenSpec, false);
	}

	/**
	 * Put the bar on a percentage immediately, no animation. For the end of a
	 * run: easing up to a final 100% over a ten-second budget that has already
	 * finished would just be the user waiting on a decoration.
	 */
	function snapStepPct($host, pct) {
		setTween($host, 'pct', pct, pctTweenSpec, true);
	}

	/**
	 * Drive the single rendered step row (see renderSteps) from an isx_run
	 * result: swap in the current phase's label and animate its bar to
	 * phase_progress%. When the phase changes, the bar resets to 0% and
	 * fills for the new phase instead of stacking a list of steps.
	 */
	function updateSteps($box, result) {
		var steps = $box.data('steps');
		var labels = $box.data('labels');
		if (!steps || !labels || !result.phase) {
			return;
		}
		var key = stepKeyFromPhase(result.phase);
		// Not rounded to a whole number: the server sends two decimals
		// (ISX_Admin::run_step) precisely so this counter can keep moving
		// inside a phase that sits on the same integer percent for minutes.
		var pct = Math.max(0, Math.min(100, parseFloat(result.phase_progress) || 0));

		var $step = $box.find('.isx-step');
		// Two different notions of "new" here, and conflating them stalls the
		// bar. The label only changes when the *display* key does, but the
		// percentage restarts from 0 on every *server* phase — and 'pack_meta'
		// and 'files' are two server phases sharing one row, so the row's bar
		// legitimately drops back to 0 once without its label changing. Without
		// this distinction setStepPct's anti-rewind guard reads that drop as a
		// stale echo and pins the bar at 100% for the whole of 'files'.
		var isNewLabel = $step.data('key') !== key;
		var isNewPhase = $step.data('rawPhase') !== result.phase;
		if (isNewPhase) {
			// setStepPct's reset branch drops the bar to 0% so it visibly
			// re-fills from empty rather than sliding on from the previous
			// phase's leftover width. Nothing is written here directly: the
			// tween owns this DOM and tracks what it last rendered, so a write
			// behind its back would leave it thinking the screen already says
			// something it doesn't.
			$step.data('rawPhase', result.phase);
		}
		if (isNewLabel) {
			$step.data('key', key);
			$step.find('.isx-step-label').text(labels[key] || key);
		}
		setStepPct($box, pct, isNewPhase);
	}

	window.ISX = {
		t: t,
		post: post,
		poll: poll,
		cancelJob: cancelJob,
		startExport: startExport,
		uploadChunks: uploadChunks,
		downloadUrl: downloadUrl,
		collectExportOptions: collectExportOptions,
		formatDuration: formatDuration,
		updateProgress: updateProgress,
		renderSteps: renderSteps,
		updateSteps: updateSteps,
		setStepPct: setStepPct,
		snapStepPct: snapStepPct,
		resetTweens: resetTweens,
		noteUpdate: noteUpdate,
		exportStepKeys: exportStepKeys,
		importStepKeys: importStepKeys
	};

	/* ---------------- Export page wiring ---------------- */

	/**
	 * Format a duration in seconds as a short localized string, e.g. "2 min 15 sec".
	 */
	function formatDuration(seconds) {
		seconds = Math.max(0, Math.round(seconds || 0));
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		if (h > 0) {
			return t('%1$s hr %2$s min', h, m);
		}
		return m > 0 ? t('%1$s min %2$s sec', m, s) : t('%s sec', s);
	}

	/**
	 * Split a status message around the counter inside it, so the number can
	 * be animated while the words around it stay put. Returns null when there
	 * is nothing countable to animate.
	 *
	 * Picking the right number takes a little care, because table names carry
	 * digits of their own on multisite ("Exporting table wp_2_posts (1200/50000 rows)").
	 * An "x/y" pair is unambiguous, so that wins; failing that the last run of
	 * digits is the counter in every message the server produces
	 * ("Verifying package — 12345 entries", "Importing database (900 rows)...").
	 */
	function splitCounter(message) {
		var match = /(\d+)(\s*\/\s*\d+)/.exec(message);
		if (!match) {
			match = /(\d+)(?![\s\S]*\d)/.exec(message);
		}
		if (!match) {
			return null;
		}
		return {
			value: parseInt(match[1], 10),
			pre: message.slice(0, match.index),
			post: message.slice(match.index + match[1].length)
		};
	}

	var statusTweenSpec = {
		render: function (value) {
			return String(Math.floor(value));
		},
		apply: function ($host, value, text) {
			var parts = $host.data('isxStatusParts');
			$host.find('.isx-status').text(parts.pre + text + parts.post);
		}
	};

	/**
	 * Write the status line, animating the counter in it when the rest of the
	 * sentence is unchanged.
	 *
	 * The surrounding text is the guard against animating across things that
	 * aren't the same quantity. The export message names the category it is
	 * working through (ISX_Export::current_category_label) and the database
	 * one names the table, and both carry the total after the slash — so the
	 * moment the run moves from media to other files, or from one table to the
	 * next, or the total changes, the text around the number differs and the
	 * counter snaps instead of sweeping across two unrelated figures.
	 */
	function setStatusMessage($box, message) {
		var parsed = splitCounter(message);
		if (!parsed) {
			// Nothing to animate — and the tween must go, or the next message
			// that does have a counter would animate up from a stale value.
			var tweens = $box.data('isxTweens');
			if (tweens) {
				delete tweens.count;
			}
			$box.removeData('isxStatusParts');
			$box.find('.isx-status').text(message);
			return;
		}

		var previous = $box.data('isxStatusParts');
		var counted = $box.data('isxTweens');
		counted = counted && counted.count;
		// Sweep only when this is the same sentence counting further up:
		// identical words on both sides of the number, and a number going up.
		var snap = !counted ||
			!previous ||
			previous.pre !== parsed.pre ||
			previous.post !== parsed.post ||
			parsed.value < counted.to;

		$box.data('isxStatusParts', { pre: parsed.pre, post: parsed.post });
		setTween($box, 'count', parsed.value, statusTweenSpec, snap);
	}

	/**
	 * Update a .isx-progress-box (percent, bar fill, elapsed, status message)
	 * from an isx_run result. Shared by every export/import/restore progress
	 * box across all four admin pages.
	 */
	function updateProgress($box, result) {
		// Before anything is re-aimed: time this arrival, so the tweens below
		// are paced to the interval these updates are actually landing at.
		noteUpdate($box, result.phase + '|' + result.phase_progress + '|' + (result.message || ''));

		updateSteps($box, result);
		setStatusMessage($box, result.message || '');

		if (typeof result.elapsed === 'number') {
			$box.find('.isx-elapsed').text(t('Elapsed: %s', formatDuration(result.elapsed)));
		}
	}

	function runExport(baseExtra) {
		var options = collectExportOptions();
		if (options === null) {
			return;
		}
		var extra = $.extend({}, baseExtra || {}, options);

		$('#isx-export-idle').hide();
		$('#isx-export-progress').show();
		var $box = $('#isx-export-progress');
		renderSteps($box, 'export', exportStepKeys(!!extra.to_storage));

		startExport(
			extra,
			function (res) {
				updateProgress($box, res);
			},
			function (res) {
				$('#isx-export-progress').hide();
				var $msg = $('#isx-export-done-msg');
				$msg.text(res.message + (res.size ? ' (' + res.size + ')' : ''));
				$msg.toggleClass('isx-ok', !res.error).toggleClass('isx-error-msg', !!res.error);
				$('#isx-export-download').toggle(!res.error);
				if (!res.error && res.backup) {
					$('#isx-export-download').attr('href', downloadUrl(res.backup));
				}
				$('#isx-export-done').show();
			}
		);
	}

	$(document).on('click', '#isx-export-start', function () {
		runExport({});
	});
	$(document).on('click', '#isx-export-start-file', function () {
		runExport({});
	});

	// Cancel applies to whichever export is running — plain file or upload to
	// Storage — because both are driven by the same poll loop.
	$(document).on('click', '#isx-export-cancel', function (event) {
		event.preventDefault();
		var current = activeJob;
		if (!current) {
			// A dead button with no explanation is the worst possible outcome
			// here — the user is watching a job they can't stop and has nothing
			// to go on. Say so, and record it, since reaching this at all means
			// the page lost track of a job that is still running server-side.
			$('#isx-export-progress').find('.isx-status').text(
				t('No running job found on this page — please refresh to see the latest status')
			);
			logToServer('error', t('Cancel clicked but there is no activeJob'), {});
			return;
		}
		if (!window.confirm(t('Cancel the export?'))) {
			return;
		}
		var $btn = $(this).prop('disabled', true);

		cancelJob(current.job, current.secret, function (res) {
			$btn.prop('disabled', false);
			$('#isx-export-progress').hide();
			var $msg = $('#isx-export-done-msg');
			$msg.text(res.message || t('Cancelled'));
			$msg.toggleClass('isx-ok', !res.error).toggleClass('isx-error-msg', !!res.error);
			$('#isx-export-download').hide();
			$('#isx-export-done').show();
		});
	});

	/* ---------------- Import page wiring (file upload) ---------------- */

	function runImportUpload(file) {
		if (!file || !/\.wpress$/i.test(file.name)) {
			window.alert(t('Please choose a .wpress file'));
			return;
		}
		if (!window.confirm(t('Importing will overwrite the entire current site. Continue?'))) {
			return;
		}
		$('#isx-import-idle').hide();
		$('#isx-import-progress').show();
		var $box = $('#isx-import-progress');
		renderSteps($box, 'import', importStepKeys(true));
		var status = $box.find('.isx-status');

		createJob(
			'isx_import_create',
			{},
			function (message, attempt, maxAttempts) {
				status.text(t('%1$s — retrying (%2$s/%3$s)...', message, attempt, maxAttempts));
			},
			function (job, secret) {
				uploadChunks(
					job,
					file,
					function (p) {
						if (typeof p.percent === 'number') {
							// Server doesn't drive this phase (it's a plain chunk upload) —
							// fake an isx_run-shaped result so it drives the "Uploading file"
							// step row the same way every other phase does.
							updateSteps($box, { phase: 'upload', phase_progress: p.percent });
						}
						status.text(p.message || '');
					},
					function () {
						poll(
							job,
							secret,
							function (res2) {
								updateProgress($box, res2);
							},
							function (res2) {
								$('#isx-import-progress').hide();
								var $msg = $('#isx-import-done-msg');
								$msg.text(res2.message || (res2.error ? t('An error occurred') : t('Done')));
								$msg.toggleClass('isx-ok', !res2.error).toggleClass('isx-error-msg', !!res2.error);
								$('#isx-import-done').show();
							}
						);
					},
					function (message) {
						$('#isx-import-progress').hide();
						window.alert(message || t('Upload failed, please try again'));
						$('#isx-import-idle').show();
					}
				);
			},
			function (message) {
				$('#isx-import-progress').hide();
				window.alert(message || t('Could not start'));
				$('#isx-import-idle').show();
			}
		);
	}

	$(document).on('change', '#isx-import-file', function () {
		if (this.files && this.files[0]) {
			runImportUpload(this.files[0]);
		}
	});

	var drop = document.getElementById('isx-import-idle');
	if (drop) {
		['dragenter', 'dragover'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) {
				e.preventDefault();
				drop.classList.add('isx-drag');
			});
		});
		['dragleave', 'drop'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) {
				e.preventDefault();
				drop.classList.remove('isx-drag');
			});
		});
		drop.addEventListener('drop', function (e) {
			if (e.dataTransfer && e.dataTransfer.files[0]) {
				runImportUpload(e.dataTransfer.files[0]);
			}
		});
	}
})(jQuery);
