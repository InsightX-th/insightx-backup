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
	 * Poll isx_run until done. Calls onTick(result) every step and
	 * onDone(result) once finished (result.done === true).
	 */
	function poll(job, secret, onTick, onDone) {
		function step() {
			post('isx_run', { job: job, secret: secret })
				.done(function (res) {
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

					onTick(res.data);
					if (res.data.done) {
						onDone(res.data);
					} else {
						setTimeout(step, 200);
					}
				})
				.fail(function () {
					onTick({ message: 'การเชื่อมต่อล้มเหลว', progress: 0 });
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
	 * Start a brand-new export job. `extra` may include { to_storage: slug }.
	 */
	function startExport(extra, onTick, onDone) {
		return post('isx_export_start', extra || {}).done(function (res) {
			if (res.success) {
				poll(res.data.job, res.data.secret, onTick, onDone);
			} else {
				onTick({ message: (res.data && res.data.message) || 'เริ่มไม่สำเร็จ', progress: 0 });
			}
		});
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
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		return m > 0 ? m + ' นาที ' + s + ' วินาที' : s + ' วินาที';
	}

	/**
	 * Update a .isx-progress-box (percent, bar fill, elapsed, ETA, status
	 * message) from an isx_run result. Shared by every export/import/restore
	 * progress box across all four admin pages.
	 */
	function updateProgress($box, result) {
		updateSteps($box, result);
		$box.find('.isx-status').text(result.message || '');

		if (typeof result.elapsed === 'number') {
			$box.find('.isx-elapsed').text('ใช้เวลาไปแล้ว ' + formatDuration(result.elapsed));
		}
		if (typeof result.eta === 'number' && result.eta !== null) {
			$box.find('.isx-eta').text('เหลืออีกประมาณ ' + formatDuration(result.eta));
		} else {
			$box.find('.isx-eta').text('');
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
				if (res.error) {
					$('#isx-export-idle').show();
					window.alert(res.message || 'เกิดข้อผิดพลาด');
					return;
				}
				$('#isx-export-done-msg').text(res.message + (res.size ? ' (' + res.size + ')' : ''));
				if (res.backup) {
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
		$box.find('.isx-eta').text('');
		var status = $box.find('.isx-status');

		post('isx_import_create').done(function (res) {
			if (!res.success) {
				status.text('เริ่มไม่สำเร็จ');
				return;
			}
			var job = res.data.job;
			var secret = res.data.secret;
			var uploadStart = Date.now();
			uploadChunks(
				job,
				file,
				function (p) {
					if (typeof p.percent === 'number') {
						// Server doesn't drive this phase (it's a plain chunk upload) —
						// fake an isx_run-shaped result so it drives the "อัปโหลดไฟล์"
						// step row the same way every other phase does.
						updateSteps($box, { phase: 'upload', phase_progress: p.percent });
						// ...and estimate remaining time client-side from the pace so far.
						if (p.percent > 0) {
							var elapsed = (Date.now() - uploadStart) / 1000;
							var eta = elapsed * (100 - p.percent) / p.percent;
							$box.find('.isx-eta').text('เหลืออีกประมาณ ' + formatDuration(eta));
						}
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
							if (res2.error) {
								window.alert(res2.message || 'เกิดข้อผิดพลาด');
								$('#isx-import-idle').show();
								return;
							}
							$('#isx-import-done-msg').text(res2.message || 'เสร็จสิ้น');
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
		});
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
