/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Core export / import pipeline driver. Exposes window.ISX so isx-storage.js
 * (provider cards, Storage settings, backups list) can reuse the same poll /
 * upload logic instead of duplicating it.
 */
(function ($) {
	'use strict';

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
	// old code polled forever with the same "อาจมี firewall/proxy บล็อกอยู่"
	// line, which sent people chasing a firewall while the real answer — a
	// server-side 502/504 or an expired nonce — was sitting in jqXHR.status.
	var POLL_FAIL_LIMIT = 30;

	/**
	 * Turn a failed poll into something that names the actual cause.
	 */
	function failureMessage(jqXHR, streak) {
		var status = jqXHR && typeof jqXHR.status !== 'undefined' ? jqXHR.status : 0;

		if (status === 502 || status === 503 || status === 504) {
			return 'เซิร์ฟเวอร์ตัดการเชื่อมต่อกลางทาง (HTTP ' + status + ') — งานหนักเกินเวลาที่เซิร์ฟเวอร์ยอมให้ (ดูหน้า Log)';
		}
		if (status === 403) {
			return 'เซสชันหมดอายุ (HTTP 403) — กรุณารีเฟรชหน้านี้แล้วเริ่มใหม่';
		}
		if (status === 500) {
			return 'เซิร์ฟเวอร์เกิดข้อผิดพลาด (HTTP 500) — ดูรายละเอียดที่หน้า Log';
		}
		if (status === 0) {
			return 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ — เน็ตหลุดหรือเซิร์ฟเวอร์ไม่ตอบ';
		}
		if (streak >= 3) {
			return 'การเชื่อมต่อล้มเหลว ' + streak + ' ครั้งติดกัน (HTTP ' + status + ') — ดูหน้า Log';
		}
		return 'การเชื่อมต่อล้มเหลว';
	}

	// Jobs the user cancelled, by id. Kept for the life of the page: the flag has
	// to outlive the in-flight poll request it is silencing.
	var cancelledJobs = {};

	// The job poll() is currently driving, so a "ยกเลิก" button can act on it
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
						data.message = 'ยกเลิกไม่สำเร็จ';
					}
				} else if (data.message !== 'ยกเลิกโดยผู้ใช้') {
					data.error = false;
				} else {
					data.error = true;
				}
				onDone(data);
			})
			.fail(function () {
				onDone({ done: true, error: true, message: 'ยกเลิกไม่สำเร็จ' });
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
						var password = window.prompt(res.data.message || 'กรุณากรอกรหัสผ่าน');
						if (password === null || password === '') {
							onDone({ error: true, done: true, message: 'ยกเลิกการนำเข้า' });
							return;
						}
						post('isx_import_decrypt', { job: job, secret: secret, password: password })
							.done(function (res2) {
								if (res2 && res2.success) {
									step();
								} else {
									onDone({ error: true, done: true, message: (res2 && res2.data && res2.data.message) || 'รหัสผ่านไม่ถูกต้อง' });
								}
							})
							.fail(function () {
								onDone({ error: true, done: true, message: 'เกิดข้อผิดพลาด' });
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
						logToServer('debug', 'poll สำเร็จ', {
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
						logToServer('error', 'poll ล้มเหลว (การเชื่อมต่อล้มเหลว)', info);
					}

					// Isolated blips recover on the next poll; a sustained run
					// means the site is not going to answer, so stop instead of
					// spinning up a four-figure failure count.
					if (failStreak >= POLL_FAIL_LIMIT) {
						logToServer('error', 'หยุด poll: ล้มเหลวติดกันเกินกำหนด', info);
						onDone({
							error: true,
							done: true,
							message:
								failureMessage(jqXHR, failStreak) +
								' — หยุดรอแล้วหลังจากลองซ้ำ ' + failStreak + ' ครั้ง งานยังค้างอยู่บนเซิร์ฟเวอร์ ' +
								'รีเฟรชหน้านี้เพื่อดูสถานะล่าสุด'
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
				window.alert('กรุณากรอกรหัสผ่านสำหรับเข้ารหัส');
				return null;
			}
			if (pw !== pw2) {
				window.alert('รหัสผ่านไม่ตรงกัน');
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
				$('<button type="button" class="isx-fr-remove" title="ลบแถวนี้">&times;</button>')
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
		$('#isx-tables-picker-btn').text(count > 0 ? count + ' ตารางถูกเลือก' : 'ยังไม่ได้เลือกตาราง');
	}

	function loadTablesPicker() {
		if (tablesLoaded) {
			return;
		}
		tablesLoaded = true;
		post('isx_list_tables').done(function (res) {
			if (!res || !res.success) {
				$('#isx-tables-picker-list').html('<p class="isx-fetch-status is-error">โหลดรายการตารางไม่สำเร็จ</p>');
				return;
			}
			var tables = res.data.tables || [];
			var html = '';
			tables.forEach(function (t) {
				html += '<label class="isx-checkbox-row">' +
					'<input type="checkbox" value="' + t.name.replace(/"/g, '&quot;') + '" />' +
					'<span>' + t.name + ' <span class="isx-muted">(' + t.rows + ' แถว)</span></span>' +
					'</label>';
			});
			$('#isx-tables-picker-list').html(html || '<p class="isx-fetch-status">ไม่พบตาราง</p>');
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
		$('#isx-files-picker-btn').text(count > 0 ? count + ' รายการถูกเลือก' : 'ยังไม่ได้เลือกไฟล์');
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
					fail((res.data && res.data.message) || 'เริ่มไม่สำเร็จ');
				})
				.fail(function () {
					fail('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ');
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
				onTick({ message: message + ' — ลองใหม่ (' + attempt + '/' + maxAttempts + ')...', progress: 0 });
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
						retryOrFail((res.data && res.data.message) || 'อัปโหลดล้มเหลว');
						return;
					}
					attempt = 0;
					index++;
					var pct = Math.round((index / total) * 100);
					onProgress({ message: 'กำลังอัปโหลด...', percent: pct });
					if (index < total) {
						send();
					} else {
						done();
					}
				})
				.fail(function () {
					retryOrFail('อัปโหลดล้มเหลว');
				});
		}

		function retryOrFail(message) {
			attempt++;
			if (attempt < MAX_ATTEMPTS) {
				onProgress({ message: message + ' — ลองใหม่ (' + attempt + '/' + MAX_ATTEMPTS + ')...' });
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
			init: 'เตรียมข้อมูล',
			database: 'ส่งออกฐานข้อมูล',
			pack: 'แพ็กไฟล์',
			finalize: 'สร้างไฟล์สำเร็จ',
			upload: 'อัปโหลดไปยัง Storage'
		},
		import: {
			upload: 'อัปโหลดไฟล์',
			init: 'ตรวจสอบแพ็กเกจ',
			clean: 'ล้างไฟล์เดิม',
			extract: 'กู้คืนไฟล์',
			database: 'นำเข้าฐานข้อมูล',
			finalize: 'ปิดงาน'
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
		return keys.concat(['init', 'clean', 'extract', 'database', 'finalize']);
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
			'<span class="isx-step-pct">0%</span>' +
			'</div>' +
			'<div class="isx-step-bar"><div class="isx-step-bar-fill"></div></div>' +
			'</div>' +
			'</div>';
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
	// to the single "แพ็กไฟล์" row.
	function stepKeyFromPhase(phase) {
		return (phase === 'pack_meta' || phase === 'files') ? 'pack' : phase;
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
		var pct = Math.max(0, Math.min(100, Math.round(result.phase_progress || 0)));

		var $step = $box.find('.isx-step');
		if ($step.data('key') !== key) {
			// New phase — reset the bar to 0% first so it visibly re-fills
			// from empty instead of jumping from the old phase's leftover width.
			$step.data('key', key);
			$step.find('.isx-step-bar-fill').css('width', '0%');
			$step.find('.isx-step-pct').text('0%');
			$step.find('.isx-step-label').text(labels[key] || key);
			// Force a reflow so the width:0 above is committed before the new
			// width is set, otherwise the browser coalesces both into a single
			// jump and the fill-from-empty animation never shows.
			void $step.find('.isx-step-bar-fill')[0].offsetWidth;
		}
		$step.find('.isx-step-bar-fill').css('width', pct + '%');
		$step.find('.isx-step-pct').text(pct + '%');
	}

	window.ISX = {
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
		exportStepKeys: exportStepKeys,
		importStepKeys: importStepKeys
	};

	/* ---------------- Export page wiring ---------------- */

	/**
	 * Format a duration in seconds as a short Thai string, e.g. "2 นาที 15 วินาที".
	 */
	function formatDuration(seconds) {
		seconds = Math.max(0, Math.round(seconds || 0));
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		if (h > 0) {
			return h + ' ชั่วโมง ' + m + ' นาที';
		}
		return m > 0 ? m + ' นาที ' + s + ' วินาที' : s + ' วินาที';
	}

	/**
	 * Update a .isx-progress-box (percent, bar fill, elapsed, status message)
	 * from an isx_run result. Shared by every export/import/restore progress
	 * box across all four admin pages.
	 */
	function updateProgress($box, result) {
		updateSteps($box, result);
		$box.find('.isx-status').text(result.message || '');

		if (typeof result.elapsed === 'number') {
			$box.find('.isx-elapsed').text('ใช้เวลาไปแล้ว ' + formatDuration(result.elapsed));
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
				'ไม่พบงานที่กำลังทำงานในหน้านี้ — กรุณารีเฟรชหน้าเพื่อดูสถานะล่าสุด'
			);
			logToServer('error', 'กดยกเลิกแต่ไม่มี activeJob', {});
			return;
		}
		if (!window.confirm('ยกเลิกการส่งออก?')) {
			return;
		}
		var $btn = $(this).prop('disabled', true);

		cancelJob(current.job, current.secret, function (res) {
			$btn.prop('disabled', false);
			$('#isx-export-progress').hide();
			var $msg = $('#isx-export-done-msg');
			$msg.text(res.message || 'ยกเลิกแล้ว');
			$msg.toggleClass('isx-ok', !res.error).toggleClass('isx-error-msg', !!res.error);
			$('#isx-export-download').hide();
			$('#isx-export-done').show();
		});
	});

	/* ---------------- Import page wiring (file upload) ---------------- */

	function runImportUpload(file) {
		if (!file || !/\.wpress$/i.test(file.name)) {
			window.alert('กรุณาเลือกไฟล์ .wpress');
			return;
		}
		if (!window.confirm('การนำเข้าจะเขียนทับเว็บปัจจุบันทั้งหมด ยืนยันหรือไม่?')) {
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
				status.text(message + ' — ลองใหม่ (' + attempt + '/' + maxAttempts + ')...');
			},
			function (job, secret) {
				uploadChunks(
					job,
					file,
					function (p) {
						if (typeof p.percent === 'number') {
							// Server doesn't drive this phase (it's a plain chunk upload) —
							// fake an isx_run-shaped result so it drives the "อัปโหลดไฟล์"
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
								$msg.text(res2.message || (res2.error ? 'เกิดข้อผิดพลาด' : 'เสร็จสิ้น'));
								$msg.toggleClass('isx-ok', !res2.error).toggleClass('isx-error-msg', !!res2.error);
								$('#isx-import-done').show();
							}
						);
					},
					function (message) {
						$('#isx-import-progress').hide();
						window.alert(message || 'อัปโหลดล้มเหลว กรุณาลองใหม่');
						$('#isx-import-idle').show();
					}
				);
			},
			function (message) {
				$('#isx-import-progress').hide();
				window.alert(message || 'เริ่มไม่สำเร็จ');
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
