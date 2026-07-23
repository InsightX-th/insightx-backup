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
					if (res.error) {
						$('#isx-export-idle').show();
						window.alert(res.message || 'เกิดข้อผิดพลาด');
						return;
					}
					$('#isx-export-done-msg').text(res.message || 'เสร็จสิ้น');
					if (res.backup) {
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
					if (res.error) {
						window.alert(res.message || 'เกิดข้อผิดพลาด');
						$('#isx-import-idle').show();
						return;
					}
					$('#isx-import-done-msg').text(res.message || 'เสร็จสิ้น');
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

		// Icon dropdown for "ความถี่" / "ส่งขึ้น Storage" — reuses the same
		// open/close + menu markup pattern as the import page's "นำเข้าจาก"
		// picker instead of a plain <select> (native <option> can't render
		// an icon).
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
							if (r.error) {
								window.alert(r.message || 'เกิดข้อผิดพลาด');
								return;
							}
							$('#isx-backups-restore-done-msg').text(r.message || 'เสร็จสิ้น');
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

		// Flat "full/path/to/file.ext" + size entries from the server → a
		// folder/file tree so the modal reads like a file explorer instead of
		// a wall of repeated path prefixes. Each dir node accumulates the sum
		// of every file size beneath it, so the tree can show a folder total
		// instead of leaving folders blank.
		function buildContentTree(entries) {
			var root = { type: 'dir', size: 0, children: {} };
			entries.forEach(function (entry) {
				var parts = entry.path.split('/').filter(function (p) {
					return p !== '';
				});
				var node = root;
				root.size += entry.size;
				parts.forEach(function (part, i) {
					var isFile = i === parts.length - 1;
					if (!node.children[part]) {
						node.children[part] = isFile
							? { type: 'file', size: entry.size }
							: { type: 'dir', size: 0, children: {} };
					}
					node = node.children[part];
					if (!isFile) {
						node.size += entry.size;
					}
				});
			});
			return root;
		}

		function renderContentTree(node) {
			var names = Object.keys(node.children).sort(function (a, b) {
				var da = node.children[a].type === 'dir';
				var db = node.children[b].type === 'dir';
				if (da !== db) {
					return da ? -1 : 1;
				}
				return a.localeCompare(b);
			});

			var html = '';
			names.forEach(function (name) {
				var child = node.children[name];
				if (child.type === 'dir') {
					html +=
						'<li class="isx-tree-dir"><details open><summary><span class="dashicons dashicons-category"></span>' +
						escapeHtmlLocal(name) +
						'<span class="isx-content-size">' + formatBytes(child.size) + '</span>' +
						'</summary><ul class="isx-tree-list">' +
						renderContentTree(child) +
						'</ul></details></li>';
				} else {
					html +=
						'<li class="isx-tree-file"><span class="dashicons dashicons-media-default"></span><span class="isx-content-path">' +
						escapeHtmlLocal(name) +
						'</span><span class="isx-content-size">' +
						formatBytes(child.size) +
						'</span></li>';
				}
			});
			return html;
		}

		function openContentModal() {
			$('#isx-content-overlay').css('display', 'flex');
		}
		function closeContentModal() {
			$('#isx-content-overlay').hide();
		}

		$(document).on('click', '.isx-backup-list-content', function (event) {
			event.preventDefault();
			closeAllDots();
			var name = $(this).closest('tr').data('name');

			openContentModal();
			$('#isx-content-body').html('<p class="isx-fetch-status">กำลังโหลด...</p>');

			ISX.post('isx_backups_list_content', { name: name })
				.done(function (res) {
					if (!res || !res.success) {
						$('#isx-content-body').html('<p class="isx-fetch-status is-error">' + escapeHtmlLocal((res && res.data && res.data.message) || 'โหลดรายการไม่สำเร็จ') + '</p>');
						return;
					}
					var entries = res.data.entries || [];
					if (entries.length === 0) {
						$('#isx-content-body').html('<p class="isx-fetch-status">ไม่พบไฟล์ในแพ็กเกจนี้</p>');
						return;
					}
					var tree = buildContentTree(entries);
					var html =
						'<p class="isx-content-total">' + escapeHtmlLocal('รวมทั้งหมด: ' + formatBytes(tree.size)) + '</p>' +
						'<ul class="isx-tree-list isx-tree-root">' + renderContentTree(tree) + '</ul>';
					$('#isx-content-body').html(html);
				})
				.fail(function () {
					$('#isx-content-body').html('<p class="isx-fetch-status is-error">โหลดรายการไม่สำเร็จ</p>');
				});
		});

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
