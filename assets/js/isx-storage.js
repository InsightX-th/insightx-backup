/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * S3 storage integration: provider card pickers (export/import pages), the
 * stacked Storage settings screen, and the local backups list actions.
 * Reuses window.ISX (from isx-admin.js) for the actual export/import poll.
 */
(function ($) {
	'use strict';

	if (typeof isx_storage === 'undefined') {
		return;
	}

	function isConfigured(slug) {
		return isx_storage.providers[slug] && isx_storage.providers[slug].configured;
	}

	function escapeHtml(text) {
		return $('<div>').text(text == null ? '' : String(text)).html();
	}

	// Icon dropdown used wherever a provider (or another icon-bearing choice)
	// is picked — reuses the same open/close + menu markup as the import page's
	// "นำเข้าจาก" picker instead of a plain <select>, since a native <option>
	// can't render an icon. Expects #<prefix>-picker / -toggle / -icon / -label
	// plus a hidden #<prefix> holding the value.
	function wireIconPicker(prefix) {
		var $picker = $('#' + prefix + '-picker');
		if (!$picker.length) {
			return;
		}
		var $toggle = $('#' + prefix + '-toggle');
		var $hidden = $('#' + prefix);
		var $icon = $('#' + prefix + '-icon');
		var $label = $('#' + prefix + '-label');

		$toggle.on('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			$picker.toggleClass('is-open');
		});
		$(document).on('click', function (event) {
			if (!$(event.target).closest($picker).length) {
				$picker.removeClass('is-open');
			}
		});
		$picker.on('click', '.isx-import-from-menu a', function (event) {
			event.preventDefault();
			var $item = $(this);
			$hidden.val($item.data('value') || '');
			$label.text($item.data('label') || '');
			$icon.html($item.find('.isx-card-icon').html());
			$picker.removeClass('is-open');
		});
	}

	/* ================= Export page: destination cards ================= */

	(function exportPage() {
		var $section = $('#isx-export-idle');
		if (!$section.length) {
			return;
		}
		var $provider = $('#isx-provider');
		var $flag = $('#isx-to-storage');

		$(document).on('click', '#isx-export-idle .isx-dest-card', function (event) {
			event.preventDefault();
			var slug = $(this).data('provider');
			$('#isx-export-idle .isx-dest-card').removeClass('is-selected');
			$(this).addClass('is-selected');
			$provider.val(slug);
		});

		$(document).on('click', '#isx-export-start-storage', function (event) {
			event.preventDefault();
			var slug = $provider.val();
			if (!isConfigured(slug)) {
				window.alert('ยังไม่ได้ตั้งค่า provider นี้ — ไปที่เมนู "การเชื่อมต่อ" ก่อน');
				return;
			}
			$flag.val(slug);

			// Reuse the same export runner as the File button (including its
			// find & replace / advanced options), just with a destination slug
			// attached to the job.
			var options = ISX.collectExportOptions();
			if (options === null) {
				return;
			}

			$('#isx-export-idle').hide();
			$('#isx-export-progress').show();
			var $box = $('#isx-export-progress');
			ISX.renderSteps($box, 'export', ISX.exportStepKeys(true));

			ISX.startExport(
				$.extend({ to_storage: slug }, options),
				function (res) {
					ISX.updateProgress($box, res);
				},
				function (res) {
					$('#isx-export-progress').hide();
					var $msg = $('#isx-export-done-msg');
					$msg.text(res.message || (res.error ? 'เกิดข้อผิดพลาด' : 'เสร็จสิ้น'));
					$msg.toggleClass('isx-ok', !res.error).toggleClass('isx-error-msg', !!res.error);
					$('#isx-export-download').toggle(!res.error);
					if (!res.error && res.backup) {
						$('#isx-export-download').attr('href', ISX.downloadUrl(res.backup));
					}
					$('#isx-export-done').show();
				}
			);
		});
	})();

	/* ================= Import page: import from Storage ================= */

	(function importPage() {
		var $menu = $('#isx-import-from');
		if (!$menu.length) {
			return;
		}
		var $list = $('#isx-backup-list');
		var $manual = $('#isx-manual');
		var currentProvider = '';

		function closeMenu() {
			$menu.removeClass('is-open');
		}

		function startImportJob(job, secret) {
			$('#isx-import-idle').hide();
			$('#isx-import-progress').show();
			var $box = $('#isx-import-progress');
			// No client-side upload step here — the file was already downloaded
			// to the server by isx_storage_import_prepare before this runs.
			ISX.renderSteps($box, 'import', ISX.importStepKeys(false));

			ISX.poll(
				job,
				secret,
				function (res) {
					ISX.updateProgress($box, res);
				},
				function (res) {
					$('#isx-import-progress').hide();
					var $msg = $('#isx-import-done-msg');
					$msg.text(res.message || (res.error ? 'เกิดข้อผิดพลาด' : 'เสร็จสิ้น'));
					$msg.toggleClass('isx-ok', !res.error).toggleClass('isx-error-msg', !!res.error);
					$('#isx-import-done').show();
				}
			);
		}

		function importKey(slug, key) {
			$list.append('<p class="isx-fetch-status">กำลังดาวน์โหลดจาก Storage...</p>');
			ISX.post('isx_storage_import_prepare', { provider: slug, key: key })
				.done(function (res) {
					$list.find('.isx-fetch-status').remove();
					if (res && res.success) {
						startImportJob(res.data.job, res.data.secret);
					} else {
						window.alert((res && res.data && res.data.message) || 'ดาวน์โหลดไม่สำเร็จ');
					}
				})
				.fail(function () {
					$list.find('.isx-fetch-status').remove();
					window.alert('ดาวน์โหลดไม่สำเร็จ');
				});
		}

		function loadBackups(slug) {
			currentProvider = slug;
			$manual.show();
			$list.html('<p class="isx-fetch-status">กำลังโหลดรายการ...</p>');
			ISX.post('isx_storage_import_list', { provider: slug })
				.done(function (res) {
					if (!res || !res.success) {
						$list.html('<p class="isx-fetch-status is-error">' + escapeHtml((res && res.data && res.data.message) || 'โหลดรายการไม่สำเร็จ') + '</p>');
						return;
					}
					var backups = res.data.backups || [];
					if (backups.length === 0) {
						$list.html('<p class="isx-fetch-status">ไม่พบไฟล์ .wpress ใน bucket นี้</p>');
						return;
					}
					var html = '<table class="isx-backups"><tbody>';
					backups.forEach(function (b) {
						html += '<tr>' +
							'<td class="isx-b-name">' + escapeHtml(b.name) + '</td>' +
							'<td class="isx-b-size">' + escapeHtml(b.size) + '</td>' +
							'<td class="isx-b-date">' + escapeHtml(b.last_modified) + '</td>' +
							'<td class="isx-b-action"><button type="button" class="button button-primary isx-btn isx-import-go" data-key="' + escapeHtml(b.key) + '">นำเข้า</button></td>' +
							'</tr>';
					});
					html += '</tbody></table>';
					$list.html(html);
				})
				.fail(function () {
					$list.html('<p class="isx-fetch-status is-error">โหลดรายการไม่สำเร็จ</p>');
				});
		}

		// Toggle the "นำเข้าจาก" dropdown.
		$(document).on('click', '#isx-import-from-toggle', function (event) {
			event.preventDefault();
			event.stopPropagation();
			$menu.toggleClass('is-open');
		});
		$(document).on('click', function (event) {
			if (!$(event.target).closest('#isx-import-from').length) {
				closeMenu();
			}
		});

		// "ไฟล์" opens the native file picker (upload flow handled in isx-admin.js).
		$(document).on('click', '#isx-import-from-file', function (event) {
			event.preventDefault();
			closeMenu();
			$('#isx-import-file').trigger('click');
		});

		// A provider entry loads its bucket's backup list.
		$(document).on('click', '.isx-import-from-provider', function (event) {
			event.preventDefault();
			closeMenu();
			var slug = $(this).data('provider');
			if (!isConfigured(slug)) {
				window.alert('ยังไม่ได้ตั้งค่า provider นี้ — ไปที่เมนู "การเชื่อมต่อ" ก่อน');
				return;
			}
			loadBackups(slug);
		});

		$(document).on('click', '.isx-import-go', function (event) {
			event.preventDefault();
			var key = $(this).data('key');
			if (!window.confirm('นำเข้าไฟล์นี้จะเขียนทับเว็บปัจจุบัน ยืนยันหรือไม่?')) {
				return;
			}
			importKey(currentProvider, key);
		});

		$(document).on('click', '#isx-import-key-go', function (event) {
			event.preventDefault();
			var key = $.trim($('#isx-import-key').val());
			if (!currentProvider || !isConfigured(currentProvider)) {
				window.alert('เลือก provider จากเมนู "นำเข้าจาก" ก่อน');
				return;
			}
			if (!key) {
				return;
			}
			if (!window.confirm('นำเข้าไฟล์นี้จะเขียนทับเว็บปัจจุบัน ยืนยันหรือไม่?')) {
				return;
			}
			importKey(currentProvider, key);
		});
	})();

	/* ================= Storage settings page ================= */

	(function settingsPage() {
		var $blocks = $('.isx-provider-block');
		if (!$blocks.length) {
			return;
		}

		var DOTS = '••••••••••••••••';

		// Every field is required except a locked endpoint (Amazon S3 — computed
		// from Region, see the `disabled` attribute on that input); keeps
		// "บันทึก" disabled until the provider is actually fully filled in,
		// instead of letting a half-filled save through.
		function fieldFilled($input) {
			return $input.is(':disabled') || $.trim($input.val()) !== '';
		}

		function refreshSaveState($block) {
			var $secret = $block.find('.isx-secret');
			var hasSecret = $secret.val() !== '' || $secret.attr('data-has-secret') === '1';
			var complete =
				fieldFilled($block.find('[data-field="endpoint"]')) &&
				fieldFilled($block.find('[data-field="region"]')) &&
				fieldFilled($block.find('[data-field="bucket"]')) &&
				fieldFilled($block.find('[data-field="access_key"]')) &&
				hasSecret;
			$block.find('.isx-save').prop('disabled', !complete);
		}

		$blocks.each(function () {
			refreshSaveState($(this));
		});

		$(document).on(
			'input',
			'.isx-provider-block [data-field="endpoint"], .isx-provider-block [data-field="region"], .isx-provider-block [data-field="bucket"], .isx-provider-block [data-field="access_key"], .isx-provider-block .isx-secret',
			function () {
				refreshSaveState($(this).closest('.isx-provider-block'));
			}
		);

		$(document).on('focus', '.isx-secret', function () {
			if ($(this).attr('data-has-secret') === '1' && $(this).val() === DOTS) {
				$(this).val('');
			}
		});
		$(document).on('blur', '.isx-secret', function () {
			if ($(this).attr('data-has-secret') === '1' && $(this).val() === '') {
				$(this).val(DOTS);
			}
			refreshSaveState($(this).closest('.isx-provider-block'));
		});

		$(document).on('click', '.isx-save', function (event) {
			event.preventDefault();

			var $button = $(this);
			var $block = $button.closest('.isx-provider-block');
			var slug = $block.data('provider');
			var $status = $block.find('.isx-save-status');
			var $secret = $block.find('.isx-secret');

			var secret = $secret.val();
			if ($secret.attr('data-has-secret') === '1' && secret === DOTS) {
				secret = '';
			}

			var config = {
				endpoint: $block.find('[data-field="endpoint"]').val(),
				region: $block.find('[data-field="region"]').val(),
				bucket: $block.find('[data-field="bucket"]').val(),
				// Optional — blank means the server falls back to its default folder.
				prefix: $block.find('[data-field="prefix"]').val(),
				access_key: $block.find('[data-field="access_key"]').val(),
				secret_key: secret,
				path_style: $block.find('[data-field="path_style"]').is(':checked') ? 1 : ''
			};

			$button.prop('disabled', true);
			$status.text('กำลังทดสอบการเชื่อมต่อ...').removeClass('is-ok is-error');

			ISX.post('isx_storage_save', { provider: slug, config: config })
				.done(function (res) {
					refreshSaveState($block);
					if (res && res.success) {
						if (secret !== '') {
							$secret.val(DOTS).attr('data-has-secret', '1');
						}
						var message = (res.data && res.data.message) || 'บันทึกแล้ว';
						if (res.data && res.data.connected) {
							$status.text(message).addClass('is-ok');
						} else {
							$status.text(message).addClass('is-error');
						}
					} else {
						$status.text((res && res.data && res.data.message) || 'บันทึกไม่สำเร็จ').addClass('is-error');
					}
				})
				.fail(function () {
					refreshSaveState($block);
					$status.text('บันทึกไม่สำเร็จ').addClass('is-error');
				});
		});
	})();

	/* ================= Storage settings page: local backups dir ================= */

	(function storageDirBlock() {
		var $button = $('#isx-storage-dir-save');
		if (!$button.length) {
			return;
		}

		$button.on('click', function (event) {
			event.preventDefault();

			var $input = $('#isx-storage-dir-input');
			var $status = $('#isx-storage-dir-status');
			var path = $.trim($input.val());

			$button.prop('disabled', true);
			$status.text('กำลังบันทึก...').removeClass('is-ok is-error');

			ISX.post('isx_storage_dir_save', { path: path })
				.done(function (res) {
					$button.prop('disabled', false);
					if (res && res.success) {
						$input.val(res.data.path);
						$status.text(res.data.message).removeClass('is-error').addClass('is-ok');
					} else {
						$status.text((res && res.data && res.data.message) || 'บันทึกไม่สำเร็จ').removeClass('is-ok').addClass('is-error');
					}
				})
				.fail(function () {
					$button.prop('disabled', false);
					$status.text('บันทึกไม่สำเร็จ').removeClass('is-ok').addClass('is-error');
				});
		});
	})();

	/* ================= Storage settings page: scheduled backup ================= */

	(function scheduleBlock() {
		var $button = $('#isx-schedule-save');
		if (!$button.length) {
			return;
		}

		wireIconPicker('isx-schedule-to-storage');
		wireIconPicker('isx-schedule-interval');

		$button.on('click', function (event) {
			event.preventDefault();

			var $status = $('#isx-schedule-status');
			var data = {
				enabled: $('#isx-schedule-enabled').is(':checked') ? 1 : 0,
				interval: $('#isx-schedule-interval').val(),
				to_storage: $('#isx-schedule-to-storage').val(),
				retain: $('#isx-schedule-retain').val()
			};

			$button.prop('disabled', true);
			$status.text('กำลังบันทึก...').removeClass('is-ok is-error');

			ISX.post('isx_schedule_save', data)
				.done(function (res) {
					$button.prop('disabled', false);
					if (res && res.success) {
						$status.text(res.data.message).removeClass('is-error').addClass('is-ok');
					} else {
						$status.text((res && res.data && res.data.message) || 'บันทึกไม่สำเร็จ').removeClass('is-ok').addClass('is-error');
					}
				})
				.fail(function () {
					$button.prop('disabled', false);
					$status.text('บันทึกไม่สำเร็จ').removeClass('is-ok').addClass('is-error');
				});
		});
	})();

	/* ================= Storage settings: stranded upload cleanup ================= */

	(function cleanupUploads() {
		var $button = $('#isx-cleanup-uploads');
		if (!$button.length) {
			return;
		}

		// Toggleable, not exclusive like the export destination picker — the
		// question here is "which of these to check", and none selected means
		// "all of them", not "none of them".
		$(document).on('click', '#isx-cleanup-providers .isx-dest-card', function (event) {
			event.preventDefault();
			$(this).toggleClass('is-selected');
		});

		$button.on('click', function (event) {
			event.preventDefault();
			var $status = $('#isx-cleanup-status');
			var providers = $('#isx-cleanup-providers .isx-dest-card.is-selected')
				.map(function () { return $(this).data('provider'); })
				.get();

			// One listing request per provider being checked, so this is slower
			// than it looks — say so rather than leaving a dead-looking button.
			$button.prop('disabled', true);
			$status.text('กำลังตรวจสอบ...').removeClass('is-ok is-error');

			ISX.post('isx_cleanup_uploads', { providers: providers })
				.done(function (res) {
					$button.prop('disabled', false);
					if (res && res.success) {
						$status.text(res.data.message).removeClass('is-error').addClass('is-ok');
					} else {
						$status
							.text((res && res.data && res.data.message) || 'ล้างไม่สำเร็จ')
							.removeClass('is-ok')
							.addClass('is-error');
					}
				})
				.fail(function () {
					$button.prop('disabled', false);
					$status.text('ล้างไม่สำเร็จ — ดูรายละเอียดที่หน้า Log').removeClass('is-ok').addClass('is-error');
				});
		});
	})();

	/* ================= Backups page ================= */

	(function backupsPage() {
		var $list = $('#isx-backups-list');
		if (!$list.length) {
			return;
		}

		$(document).on('click', '#isx-backups-create', function () {
			var $btn = $(this).attr('disabled', 'disabled');
			var $actions = $btn.closest('.isx-actions');
			$list.hide();
			$actions.hide();
			$('#isx-backups-progress').show();
			var $box = $('#isx-backups-progress');
			ISX.renderSteps($box, 'export', ISX.exportStepKeys(false));

			ISX.startExport(
				{},
				function (res) {
					ISX.updateProgress($box, res);
				},
				function (res) {
					$('#isx-backups-progress').hide();
					$btn.removeAttr('disabled');
					if (res.error) {
						$list.show();
						$actions.show();
						window.alert(res.message || 'เกิดข้อผิดพลาด');
						return;
					}
					window.location.reload();
				}
			);
		});

		$(document).on('click', '.isx-backup-delete', function (event) {
			event.preventDefault();
			if (!window.confirm('ลบข้อมูลสำรองนี้?')) {
				return;
			}
			var $row = $(this).closest('tr');
			var name = $row.data('name');
			ISX.post('isx_backups_delete', { name: name }).done(function (res) {
				if (res && res.success) {
					$row.remove();
					if ($('#isx-backups-list tbody tr').length === 0) {
						$('#isx-backups-list').html('<p class="isx-muted">ยังไม่มีข้อมูลสำรอง</p>');
					}
				} else {
					window.alert((res && res.data && res.data.message) || 'ลบไม่สำเร็จ');
				}
			});
		});

		$(document).on('click', '.isx-backup-restore', function (event) {
			event.preventDefault();
			if (!window.confirm('การนำเข้าจะเขียนทับเว็บปัจจุบันทั้งหมด ยืนยันหรือไม่?')) {
				return;
			}
			var name = $(this).closest('tr').data('name');

			$('#isx-backups-restore-progress').show();
			var $box = $('#isx-backups-restore-progress');
			ISX.renderSteps($box, 'import', ISX.importStepKeys(false));

			ISX.post('isx_backups_restore', { name: name })
				.done(function (res) {
					if (!res || !res.success) {
						$('#isx-backups-restore-progress').hide();
						window.alert((res && res.data && res.data.message) || 'เริ่มไม่สำเร็จ');
						return;
					}
					ISX.poll(
						res.data.job,
						res.data.secret,
						function (r) {
							ISX.updateProgress($box, r);
						},
						function (r) {
							$('#isx-backups-restore-progress').hide();
							var $msg = $('#isx-backups-restore-done-msg');
							$msg.text(r.message || (r.error ? 'เกิดข้อผิดพลาด' : 'เสร็จสิ้น'));
							$msg.toggleClass('isx-ok', !r.error).toggleClass('isx-error-msg', !!r.error);
							$('#isx-backups-restore-done').show();
						}
					);
				})
				.fail(function () {
					$('#isx-backups-restore-progress').hide();
					window.alert('เริ่มไม่สำเร็จ');
				});
		});

		function closeAllDots() {
			$('.isx-backup-dots-wrap').removeClass('is-open');
		}

		$(document).on('click', '.isx-backup-dots', function (event) {
			event.preventDefault();
			event.stopPropagation();
			var $wrap = $(this).closest('.isx-backup-dots-wrap');
			var wasOpen = $wrap.hasClass('is-open');
			closeAllDots();
			if (!wasOpen) {
				$wrap.addClass('is-open');
			}
		});
		$(document).on('click', function (event) {
			if (!$(event.target).closest('.isx-backup-dots-wrap').length) {
				closeAllDots();
			}
		});

		function escapeHtmlLocal(text) {
			return $('<div>').text(text == null ? '' : String(text)).html();
		}

		/**
		 * escapeHtmlLocal() for a value going inside a double-quoted attribute.
		 *
		 * .text().html() escapes &, < and > but leaves quotes alone, which is
		 * harmless in element content and is not harmless in an attribute: a
		 * file name may legitimately contain a double quote, and that would end
		 * the attribute early. Ampersands are already escaped by the time the
		 * replace below runs, so the entity it inserts cannot be double-escaped.
		 */
		function escapeAttrLocal(text) {
			return escapeHtmlLocal(text).replace(/"/g, '&quot;');
		}

		// Matches ISX_Admin::CONTENT_FILES_PER_BATCH. A single folder holding
		// more files than this gets a "showing N of M" note instead — nobody
		// reads a list that long, and rendering one is what used to lock the tab.
		var CONTENT_FILES_SHOWN = 500;

		// Server sends raw byte counts now (not pre-formatted "28.01 MB"
		// strings) specifically so folder totals below can be summed.
		function formatBytes(bytes) {
			bytes = Number(bytes) || 0;
			if (bytes < 1024) {
				return bytes + ' B';
			}
			var units = ['KB', 'MB', 'GB', 'TB'];
			var value = bytes;
			var i = -1;
			do {
				value /= 1024;
				i++;
			} while (value >= 1024 && i < units.length - 1);
			return value.toFixed(2) + ' ' + units[i];
		}

		// One level of the package, as the server summarised it: folders with a
		// file count and byte total, plus whatever files sit at this level.
		// Folders start collapsed and fetch their own children the first time they
		// are opened — a package can hold hundreds of thousands of files, and the
		// old listing asked for all of them at once and rendered every folder
		// expanded, which was too much for both ends at that size.
		function renderContentLevel(level, prefix) {
			var names = Object.keys(level.dirs).sort(function (a, b) {
				return a.localeCompare(b);
			});

			var html = '';
			names.forEach(function (name) {
				var dir = level.dirs[name];
				html +=
					'<li class="isx-tree-dir"><details data-prefix="' +
					escapeAttrLocal(prefix + name + '/') +
					'"><summary><span class="dashicons dashicons-category"></span>' +
					escapeHtmlLocal(name) +
					'<span class="isx-content-meta">' + dir.files.toLocaleString() + ' ไฟล์</span>' +
					'<span class="isx-content-size">' + formatBytes(dir.bytes) + '</span>' +
					'</summary><ul class="isx-tree-list"></ul></details></li>';
			});

			level.files.forEach(function (file) {
				html +=
					'<li class="isx-tree-file"><span class="dashicons dashicons-media-default"></span><span class="isx-content-path">' +
					escapeHtmlLocal(file.name) +
					'</span><span class="isx-content-size">' +
					formatBytes(file.size) +
					'</span></li>';
			});

			if (level.files.length < level.filesSeen) {
				html +=
					'<li class="isx-tree-more">' +
					escapeHtmlLocal(
						'แสดง ' + level.files.length.toLocaleString() +
						' จาก ' + level.filesSeen.toLocaleString() + ' ไฟล์ในโฟลเดอร์นี้'
					) +
					'</li>';
			}

			if (html === '') {
				html = '<li class="isx-tree-more">ไม่มีไฟล์ในโฟลเดอร์นี้</li>';
			}
			return html;
		}

		/**
		 * Walk one directory of a package, batch by batch.
		 *
		 * Each response covers a slice of the archive and reports only that
		 * slice's findings, so they are merged here until the server says it has
		 * reached the end. onProgress runs per batch so a long scan can show
		 * movement rather than an empty modal.
		 */
		function scanContentLevel(name, prefix, onProgress, onDone, onError) {
			var level = { dirs: {}, files: [], filesSeen: 0, bytesSeen: 0 };

			function batch(offset) {
				ISX.post('isx_backups_list_content', { name: name, prefix: prefix, offset: offset })
					.done(function (res) {
						if (!res || !res.success) {
							onError((res && res.data && res.data.message) || 'โหลดรายการไม่สำเร็จ');
							return;
						}
						var d = res.data;

						Object.keys(d.dirs || {}).forEach(function (dirName) {
							var incoming = d.dirs[dirName];
							if (!level.dirs[dirName]) {
								level.dirs[dirName] = { files: 0, bytes: 0 };
							}
							level.dirs[dirName].files += Number(incoming.files) || 0;
							level.dirs[dirName].bytes += Number(incoming.bytes) || 0;
						});

						(d.files || []).forEach(function (file) {
							if (level.files.length < CONTENT_FILES_SHOWN) {
								level.files.push(file);
							}
						});
						level.filesSeen += Number(d.files_seen) || 0;
						level.bytesSeen += Number(d.bytes_seen) || 0;

						onProgress(d.percent || 0);

						if (d.done) {
							level.files.sort(function (a, b) {
								return a.name.localeCompare(b.name);
							});
							onDone(level);
							return;
						}

						// A batch that neither finished nor moved forward would
						// have this ask for the same slice again, for as long as
						// the modal stays open. Stop instead of hammering.
						var next = Number(d.offset) || 0;
						if (next <= offset) {
							onError('อ่านรายการในแพ็กเกจไม่คืบหน้า — ไฟล์แพ็กเกจอาจเสียหาย');
							return;
						}
						batch(next);
					})
					.fail(function (jqXHR) {
						// Name the status. The old bare "could not load" is what made
						// the memory-exhaustion failure this replaces so hard to place:
						// a fatal answers with a 500 and no JSON, and the message gave
						// no hint that the server had died rather than refused.
						var status = jqXHR && jqXHR.status ? jqXHR.status : 0;
						onError(
							status
								? 'โหลดรายการไม่สำเร็จ (HTTP ' + status + ') — เซิร์ฟเวอร์อาจหน่วยความจำหรือเวลาไม่พอ'
								: 'โหลดรายการไม่สำเร็จ — เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'
						);
					});
			}

			batch(0);
		}

		function openContentModal() {
			$('#isx-content-overlay').css('display', 'flex');
		}
		function closeContentModal() {
			$('#isx-content-overlay').hide();
		}

		// Walk the package start to finish, a bounded slice per request, so a
		// multi-GB backup can be checked without any single request running long
		// enough for a proxy to cut it off. Same check the import pipeline runs
		// before it wipes anything — just available before you need it.
		$(document).on('click', '.isx-backup-verify', function (event) {
			event.preventDefault();
			closeAllDots();
			var name = $(this).closest('tr').data('name');

			openContentModal();

			// Same .isx-step-bar markup the export/import progress boxes use, so
			// this gets the shimmer-while-working animation for free instead of
			// looking frozen during the long stretch verifying a multi-GB file.
			function renderBar(label) {
				return (
					'<div class="isx-steps"><div class="isx-step is-active">' +
						'<div class="isx-step-head">' +
							'<span class="isx-step-label">' + escapeHtmlLocal(label) + '</span>' +
							'<span class="isx-step-pct">0.00%</span>' +
						'</div>' +
						'<div class="isx-step-bar"><div class="isx-step-bar-fill" style="width:0%"></div></div>' +
					'</div></div>'
				);
			}

			var $body = $('#isx-content-body');

			// Each verify request runs a full server-side time budget (~10s) before
			// answering, so the bar is driven by ISX's tweening exactly like the
			// export/import ones: rebuilding this markup per response would both
			// discard the easing state and restart the CSS shimmer mid-sweep, which
			// is what made the bar look like it was jumping and stuttering.
			function updateBar(pct) {
				ISX.noteUpdate($body, 'verify|' + pct);
				ISX.setStepPct($body, Math.max(0, Math.min(100, parseFloat(pct) || 0)), false);
			}

			function step(offset, entries) {
				ISX.post('isx_backups_verify', { name: name, offset: offset, entries: entries })
					.done(function (res) {
						if (!res || !res.success) {
							ISX.resetTweens($body);
							$body.html(
								'<p class="isx-fetch-status is-error">' +
									escapeHtmlLocal((res && res.data && res.data.message) || 'ตรวจสอบไม่สำเร็จ') +
									'</p>'
							);
							return;
						}
						var d = res.data;
						if (!d.done) {
							updateBar(d.percent || 0);
							step(d.offset, d.entries);
							return;
						}
						$body.find('.isx-step-label').text(d.ok ? 'ตรวจสอบผ่าน' : 'ตรวจสอบไม่ผ่าน');
						ISX.snapStepPct($body, 100);
						$body.append(
							'<p class="isx-fetch-status' + (d.ok ? ' is-ok' : ' is-error') + '">' +
								escapeHtmlLocal((d.ok ? '✓ ' : '✕ ') + (d.message || '')) +
							'</p>'
						);
					})
					.fail(function () {
						ISX.resetTweens($body);
						$body.html('<p class="isx-fetch-status is-error">เชื่อมต่อเซิร์ฟเวอร์ไม่ได้</p>');
					});
			}

			ISX.resetTweens($body);
			$body.html(renderBar('กำลังตรวจสอบ...'));
			step(0, 0);
		});

		$(document).on('click', '.isx-backup-list-content', function (event) {
			event.preventDefault();
			closeAllDots();
			var name = $(this).closest('tr').data('name');
			var $body = $('#isx-content-body');

			openContentModal();

			// Scanning a large package takes several requests, so show the same
			// progress bar the export/import screens use rather than a static
			// "loading" that gives no sign of life for however long it runs.
			ISX.resetTweens($body);
			$body.html(
				'<div class="isx-steps"><div class="isx-step is-active">' +
					'<div class="isx-step-head">' +
						'<span class="isx-step-label">กำลังอ่านรายการในแพ็กเกจ...</span>' +
						'<span class="isx-step-pct">0.00%</span>' +
					'</div>' +
					'<div class="isx-step-bar"><div class="isx-step-bar-fill" style="width:0%"></div></div>' +
				'</div></div>'
			);

			scanContentLevel(
				name,
				'',
				function (percent) {
					ISX.noteUpdate($body, 'content|' + percent);
					ISX.setStepPct($body, Math.max(0, Math.min(100, parseFloat(percent) || 0)), false);
				},
				function (level) {
					ISX.resetTweens($body);
					if (Object.keys(level.dirs).length === 0 && level.files.length === 0) {
						$body.html('<p class="isx-fetch-status">ไม่พบไฟล์ในแพ็กเกจนี้</p>');
						return;
					}
					$body
						.data('backup', name)
						.html(
							'<p class="isx-content-total">' +
								escapeHtmlLocal('รวมทั้งหมด: ' + formatBytes(level.bytesSeen + levelDirBytes(level))) +
							'</p>' +
							'<ul class="isx-tree-list isx-tree-root">' + renderContentLevel(level, '') + '</ul>'
						);
				},
				function (message) {
					ISX.resetTweens($body);
					$body.html('<p class="isx-fetch-status is-error">' + escapeHtmlLocal(message) + '</p>');
				}
			);
		});

		function levelDirBytes(level) {
			var total = 0;
			Object.keys(level.dirs).forEach(function (name) {
				total += level.dirs[name].bytes;
			});
			return total;
		}

		// Folders arrive collapsed and empty; the first time one is opened it
		// fetches its own children. Everything below a folder was summarised into
		// its count and size on the server, so nothing beneath it has been sent
		// yet — which is what keeps a package with hundreds of thousands of files
		// openable at all.
		// Bound natively in the capture phase, not with jQuery delegation: the
		// `toggle` event of <details> does not bubble, so a delegated handler on
		// document would never see it. Capture runs on the way down to the
		// target, which reaches non-bubbling events just fine.
		document.addEventListener('toggle', function (event) {
			var el = event.target;
			if (!el || el.tagName !== 'DETAILS' || !el.hasAttribute('data-prefix')) {
				return;
			}
			if (!$(el).closest('#isx-content-body').length) {
				return;
			}

			var $details = $(el);
			if (!el.open || $details.data('loaded')) {
				return;
			}
			$details.data('loaded', true);

			var prefix = $details.data('prefix');
			var name = $('#isx-content-body').data('backup');
			var $list = $details.children('ul');
			$list.html('<li class="isx-tree-more">กำลังอ่าน...</li>');

			scanContentLevel(
				name,
				prefix,
				function () {},
				function (level) {
					$list.html(renderContentLevel(level, prefix));
				},
				function (message) {
					// Let it be retried: a folder that failed to load should not
					// stay stuck on its error the next time it is opened.
					$details.data('loaded', false);
					$list.html('<li class="isx-tree-more is-error">' + escapeHtmlLocal(message) + '</li>');
				}
			);
		}, true);

		$(document).on('click', '#isx-content-close', function (event) {
			event.preventDefault();
			closeContentModal();
		});
		$(document).on('click', '#isx-content-overlay', function (event) {
			if (event.target === this) {
				closeContentModal();
			}
		});
	})();
})(jQuery);
