<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Reset Hub — destructive site-reset tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_backups = ISX_Backups::all();

$isx_reset_tools = array(
	'plugins'  => array(
		'icon'  => 'admin-plugins',
		'title' => __( 'Remove Plugins', 'insightx-backup' ),
		'desc'  => __( 'Deactivate and delete every plugin on the site except InsightX Backup. Useful for fixing plugin conflicts or starting fresh', 'insightx-backup' ),
		'btn'   => __( 'Remove all plugins', 'insightx-backup' ),
	),
	'theme'    => array(
		'icon'  => 'admin-appearance',
		'title' => __( 'Reset Themes', 'insightx-backup' ),
		'desc'  => __( 'Delete every theme and switch back to the default WordPress theme. Useful for fixing theme issues or starting clean', 'insightx-backup' ),
		'btn'   => __( 'Reset Themes', 'insightx-backup' ),
	),
	'media'    => array(
		'icon'  => 'admin-media',
		'title' => __( 'Clear Media Library', 'insightx-backup' ),
		'desc'  => __( 'Delete every file in the site\'s media library. Useful for clearing out old or unneeded files', 'insightx-backup' ),
		'btn'   => __( 'Clear Media Library', 'insightx-backup' ),
	),
	'database' => array(
		'icon'  => 'database',
		'title' => __( 'Reset Database', 'insightx-backup' ),
		'desc'  => __( 'Permanently delete everything in the database and return the site to its initial state, including posts, pages, comments, settings and users (except InsightX Backup\'s own settings)', 'insightx-backup' ),
		'btn'   => __( 'Reset Database', 'insightx-backup' ),
	),
	'full'     => array(
		'icon'  => 'image-rotate',
		'title' => __( 'Reset Entire Site', 'insightx-backup' ),
		'desc'  => __( 'Reset the whole site back to a fresh WordPress install. Useful for starting over completely or a full clean-up', 'insightx-backup' ),
		'btn'   => __( 'Reset Entire Site', 'insightx-backup' ),
	),
);
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Reset Hub', 'insightx-backup' ); ?></h1>
		<p class="isx-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'The tools on this page permanently destroy data and cannot be undone. Always create a backup before using them', 'insightx-backup' ); ?></p>

		<div class="isx-reset-grid">
			<?php foreach ( $isx_reset_tools as $isx_tool_key => $isx_tool ) : ?>
				<div class="isx-reset-tool" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>">
					<h2 class="isx-reset-tool-title"><span class="dashicons dashicons-<?php echo esc_attr( $isx_tool['icon'] ); ?>"></span> <?php echo esc_html( $isx_tool['title'] ); ?></h2>
					<p class="isx-reset-tool-desc"><?php echo esc_html( $isx_tool['desc'] ); ?></p>
					<div class="isx-actions">
						<button type="button" class="button isx-btn isx-btn-danger isx-reset-start" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>"><?php echo esc_html( $isx_tool['btn'] ); ?></button>
						<button type="button" class="button isx-btn isx-btn-secondary isx-reset-backup" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>"><?php esc_html_e( 'Create a backup first', 'insightx-backup' ); ?></button>
					</div>
					<div class="isx-progress-box isx-reset-progress" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>" style="display:none;">
						<p class="isx-status"></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'Backups', 'insightx-backup' ); ?></h1>

		<div id="isx-backups-list">
			<?php if ( empty( $isx_backups ) ) : ?>
				<p class="isx-muted"><?php esc_html_e( 'No backups yet', 'insightx-backup' ); ?></p>
			<?php else : ?>
			<table class="isx-backups-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'insightx-backup' ); ?></th>
						<th><?php esc_html_e( 'Date created', 'insightx-backup' ); ?></th>
						<th><?php esc_html_e( 'Time', 'insightx-backup' ); ?></th>
						<th><?php esc_html_e( 'Size', 'insightx-backup' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $isx_backups as $isx_backup ) : ?>
						<tr data-name="<?php echo esc_attr( $isx_backup['name'] ); ?>">
							<td class="isx-b-name"><span class="dashicons dashicons-media-archive"></span> <?php echo esc_html( $isx_backup['name'] ); ?></td>
							<td class="isx-b-date"><?php echo esc_html( ISX_Backups::format_date( $isx_backup['mtime'] ) ); ?></td>
							<td class="isx-b-time"><?php echo esc_html( wp_date( 'H:i', $isx_backup['mtime'] ) ); ?></td>
							<td class="isx-b-size"><?php echo esc_html( $isx_backup['size_human'] ); ?></td>
							<td class="isx-b-actions">
								<div class="isx-backup-dots-wrap">
									<a href="#" role="button" aria-haspopup="true" class="isx-backup-dots" title="<?php esc_attr_e( 'More', 'insightx-backup' ); ?>"><span class="dashicons dashicons-ellipsis"></span></a>
									<div class="isx-backup-dots-menu">
										<ul role="menu">
											<li>
												<a tabindex="-1" href="#" role="menuitem" class="isx-backup-restore">
													<span class="dashicons dashicons-cloud-upload"></span>
													<span><?php esc_html_e( 'Restore', 'insightx-backup' ); ?></span>
												</a>
											</li>
											<li>
												<?php
												$isx_dl_url = $isx_backup['url']
													? $isx_backup['url']
													: admin_url( 'admin-ajax.php?action=isx_download&backup=' . rawurlencode( $isx_backup['name'] ) . '&nonce=' . wp_create_nonce( ISX_Admin::NONCE ) );
												?>
												<a tabindex="-1" href="<?php echo esc_url( $isx_dl_url ); ?>" role="menuitem" download>
													<span class="dashicons dashicons-download"></span>
													<span><?php esc_html_e( 'Download', 'insightx-backup' ); ?></span>
												</a>
											</li>
											<li>
												<a tabindex="-1" href="#" role="menuitem" class="isx-backup-list-content">
													<span class="dashicons dashicons-list-view"></span>
													<span><?php esc_html_e( 'View contents', 'insightx-backup' ); ?></span>
												</a>
											</li>
											<li class="isx-divider"></li>
											<li>
												<a tabindex="-1" href="#" role="menuitem" class="isx-backup-delete">
													<span class="dashicons dashicons-no-alt"></span>
													<span><?php esc_html_e( 'Delete', 'insightx-backup' ); ?></span>
												</a>
											</li>
										</ul>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<div class="isx-actions" style="margin-top:20px;">
			<button type="button" class="button button-primary isx-btn" id="isx-backups-create"><?php esc_html_e( 'Create backup', 'insightx-backup' ); ?></button>
		</div>

		<div id="isx-backups-progress" class="isx-progress-box" style="display:none;">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Backing up. Please do not close this page or navigate away until it finishes', 'insightx-backup' ); ?></p>
			<p class="isx-status"></p>
		</div>

		<div id="isx-backups-restore-progress" class="isx-progress-box" style="display:none;">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Restoring. Please do not close this page or navigate away until it finishes', 'insightx-backup' ); ?></p>
			<p class="isx-status"></p>
		</div>

		<div id="isx-backups-restore-done" class="isx-done-box" style="display:none;">
			<p class="isx-ok" id="isx-backups-restore-done-msg"></p>
			<a href="<?php echo esc_url( wp_login_url() ); ?>" class="button button-primary isx-btn"><?php esc_html_e( 'Go to login page', 'insightx-backup' ); ?></a>
		</div>
	</div>
</div>

<div id="isx-content-overlay" class="isx-modal-overlay" style="display:none;">
	<div class="isx-modal">
		<div class="isx-modal-head">
			<span><?php esc_html_e( 'Backup contents', 'insightx-backup' ); ?></span>
			<a href="#" id="isx-content-close" class="isx-modal-close">&times;</a>
		</div>
		<div id="isx-content-body" class="isx-modal-body">
			<p class="isx-fetch-status"><?php esc_html_e( 'Loading...', 'insightx-backup' ); ?></p>
		</div>
	</div>
</div>

<div id="isx-reset-confirm-overlay" class="isx-modal-overlay" style="display:none;">
	<div class="isx-modal isx-reset-modal">
		<div class="isx-modal-head">
			<span id="isx-reset-confirm-title"><?php esc_html_e( 'Confirm action', 'insightx-backup' ); ?></span>
			<a href="#" id="isx-reset-confirm-close" class="isx-modal-close">&times;</a>
		</div>
		<div class="isx-modal-body">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <span id="isx-reset-confirm-warning"></span></p>
			<p>
				<label for="isx-reset-confirm-password"><?php esc_html_e( 'Enter your account password to confirm', 'insightx-backup' ); ?></label>
				<input type="password" id="isx-reset-confirm-password" class="regular-text" autocomplete="current-password" style="width:100%;" />
			</p>
			<p class="isx-fetch-status is-error" id="isx-reset-confirm-error" style="display:none;"></p>
			<div class="isx-actions">
				<button type="button" class="button isx-btn isx-btn-danger" id="isx-reset-confirm-submit"><?php esc_html_e( 'Confirm, run this action', 'insightx-backup' ); ?></button>
				<button type="button" class="button isx-btn isx-btn-secondary" id="isx-reset-confirm-cancel"><?php esc_html_e( 'Cancel', 'insightx-backup' ); ?></button>
			</div>
		</div>
	</div>
</div>
