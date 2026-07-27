/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Reset Hub page: password-confirmation modal + AJAX dispatch for the 5
 * destructive tools, plus the "Create Backup" button reusing the plugin's
 * normal export flow (window.ISX from isx-admin.js).
 */
(function ($) {
	'use strict';

	var WARNINGS = {
		plugins: 'ระบบจะปิดใช้งานและลบปลั๊กอินทั้งหมด ยกเว้น InsightX Backup การกระทำนี้ย้อนกลับไม่ได้',
		theme: 'ระบบจะลบธีมทั้งหมดและสลับไปใช้ธีมเริ่มต้น การกระทำนี้ย้อนกลับไม่ได้',
		media: 'ระบบจะลบไฟล์สื่อทั้งหมดในคลังสื่อ การกระทำนี้ย้อนกลับไม่ได้',
		database: 'ระบบจะลบข้อมูลทั้งหมดในฐานข้อมูลอย่างถาวรและคืนค่าเว็บไซต์กลับสู่สถานะเริ่มต้น การกระทำนี้ย้อนกลับไม่ได้',
		full: 'ระบบจะรีเซ็ตเว็บไซต์ทั้งหมด (ปลั๊กอิน ธีม สื่อ และฐานข้อมูล) กลับสู่สถานะเริ่มต้น การกระทำนี้ย้อนกลับไม่ได้'
	};

	var $overlay = $('#isx-reset-confirm-overlay');
	var pendingTool = null;

	function openConfirm(tool) {
		pendingTool = tool;
		$('#isx-reset-confirm-warning').text(WARNINGS[tool] || '');
		$('#isx-reset-confirm-password').val('');
		$('#isx-reset-confirm-error').hide().text('');
		$overlay.css('display', 'flex');
		$('#isx-reset-confirm-password').trigger('focus');
	}

	function closeConfirm() {
		pendingTool = null;
		$overlay.hide();
	}

	$(document).on('click', '.isx-reset-start', function () {
		openConfirm($(this).data('tool'));
	});

	$(document).on('click', '#isx-reset-confirm-cancel, #isx-reset-confirm-close', function (event) {
		event.preventDefault();
		closeConfirm();
	});

	$(document).on('click', '#isx-reset-confirm-submit', function () {
		if (!pendingTool) {
			return;
		}
		var tool = pendingTool;
		var password = $('#isx-reset-confirm-password').val() || '';
		if (password === '') {
			$('#isx-reset-confirm-error').text('กรุณากรอกรหัสผ่าน').show();
			return;
		}

		var $btn = $(this).prop('disabled', true);

		ISX.post('isx_reset_run', { tool: tool, password: password })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) {
					$('#isx-reset-confirm-error').text((res && res.data && res.data.message) || 'เกิดข้อผิดพลาด').show();
					return;
				}
				closeConfirm();
				handleSuccess(tool, res.data);
			})
			.fail(function () {
				$btn.prop('disabled', false);
				$('#isx-reset-confirm-error').text('การเชื่อมต่อล้มเหลว').show();
			});
	});

	function handleSuccess(tool, data) {
		var $card = $('.isx-reset-tool[data-tool="' + tool + '"]');
		$card.find('.isx-reset-progress .isx-status').text(data.message || 'เสร็จสิ้น');
		$card.find('.isx-reset-progress').show();

		var stats = data.stats || {};
		if (stats.admin_password) {
			window.alert(
				'รีเซ็ตฐานข้อมูลสำเร็จ\n\n' +
				'บัญชีผู้ดูแล: ' + stats.admin_login + '\n' +
				'รหัสผ่านใหม่: ' + stats.admin_password + '\n\n' +
				'กรุณาบันทึกรหัสผ่านนี้ไว้ — ระบบจะแสดงเพียงครั้งเดียว หน้านี้กำลังจะโหลดใหม่'
			);
		}

		// The site's plugins/theme/media/database just changed under this
		// same page — reload so every other admin screen (menus, nonces,
		// enqueued assets) reflects the new state instead of stale JS state.
		window.location.reload();
	}

	/* ---------------- Create Backup buttons ---------------- */

	$(document).on('click', '.isx-reset-backup', function () {
		var $btn = $(this).prop('disabled', true);
		var tool = $btn.data('tool');
		var $box = $('.isx-reset-tool[data-tool="' + tool + '"] .isx-reset-progress');
		$box.show().find('.isx-status').text('กำลังสร้างข้อมูลสำรอง...');

		ISX.startExport(
			{},
			function (res) {
				$box.find('.isx-status').text(res.message || 'กำลังสร้างข้อมูลสำรอง...');
			},
			function (res) {
				$btn.prop('disabled', false);
				if (res.error) {
					$box.find('.isx-status').text(res.message || 'สร้างข้อมูลสำรองไม่สำเร็จ');
					return;
				}
				$box.find('.isx-status').text('สร้างข้อมูลสำรองสำเร็จ' + (res.size ? ' (' + res.size + ')' : ''));
				// The list at the bottom of this page is rendered server-side
				// (ISX_Backups::all() on page load) — reload so the new file
				// actually shows up there instead of only in this card's status line.
				window.setTimeout(function () {
					window.location.reload();
				}, 800);
			}
		);
	});
})(jQuery);
