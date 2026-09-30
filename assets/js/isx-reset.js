/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Reset Hub page: password-confirmation modal + AJAX dispatch for the 5
 * destructive tools, plus the "Create Backup" button reusing the plugin's
 * normal export flow (window.ISX from isx-admin.js).
 */
(function ($) {
	'use strict';

	var t = window.ISX.t;

	var WARNINGS = {
		plugins: t('All plugins except InsightX Backup will be deactivated and deleted. This cannot be undone.'),
		theme: t('All themes will be deleted and the default theme activated. This cannot be undone.'),
		media: t('Every file in the media library will be deleted. This cannot be undone.'),
		database: t('Everything in the database will be permanently deleted and the site returned to its initial state. This cannot be undone.'),
		full: t('The entire site (plugins, themes, media and database) will be reset to its initial state. This cannot be undone.')
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
			$('#isx-reset-confirm-error').text(t('Please enter the password')).show();
			return;
		}

		var $btn = $(this).prop('disabled', true);

		ISX.post('isx_reset_run', { tool: tool, password: password })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) {
					$('#isx-reset-confirm-error').text((res && res.data && res.data.message) || t('An error occurred')).show();
					return;
				}
				closeConfirm();
				handleSuccess(tool, res.data);
			})
			.fail(function () {
				$btn.prop('disabled', false);
				$('#isx-reset-confirm-error').text(t('Connection failed')).show();
			});
	});

	function handleSuccess(tool, data) {
		var $card = $('.isx-reset-tool[data-tool="' + tool + '"]');
		$card.find('.isx-reset-progress .isx-status').text(data.message || t('Done'));
		$card.find('.isx-reset-progress').show();

		var stats = data.stats || {};

		// The reset response carries a single-use token, not the password
		// itself — fetch the password once through the reveal endpoint, then
		// show it. (For the "full" reset the database stats are nested under
		// stats.database.)
		var token = stats.admin_password_token ||
			(stats.database && stats.database.admin_password_token);
		if (token) {
			ISX.post('isx_reset_password_reveal', { token: token })
				.done(function (res) {
					if (res && res.success && res.data && res.data.admin_password) {
						window.alert(
							t(
								'Database reset complete\n\nAdmin account: %1$s\nNew password: %2$s\n\nSave this password now — it is shown only once. This page will now reload',
								stats.admin_login || (stats.database && stats.database.admin_login),
								res.data.admin_password
							)
						);
					} else {
						window.alert(
							t(
								'Database reset complete, but the new password could not be retrieved (%s) — reset the password from the login screen (Lost your password) or WP-CLI',
								(res && res.data && res.data.message) || t('Token expired')
							)
						);
					}
					window.location.reload();
				})
				.fail(function () {
					window.alert(t('Database reset complete, but the new password could not be retrieved — reset the password from the login screen (Lost your password) or WP-CLI'));
					window.location.reload();
				});
			return;
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
		$box.show().find('.isx-status').text(t('Creating backup...'));

		ISX.startExport(
			{},
			function (res) {
				$box.find('.isx-status').text(res.message || t('Creating backup...'));
			},
			function (res) {
				$btn.prop('disabled', false);
				if (res.error) {
					$box.find('.isx-status').text(res.message || t('Could not create the backup'));
					return;
				}
				$box.find('.isx-status').text((res.size ? t('Backup created (%s)', res.size) : t('Backup created')));
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
