<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Local backups screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_backups = ISX_Backups::all();
?>
<div class="wrap isx-wrap">
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
												// A direct link (served by the web server, no PHP involved) when the
												// backup sits under the web root; only falls back to streaming it
												// through admin-ajax.php when a custom storage path moved it outside.
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
											<li>
												<a tabindex="-1" href="#" role="menuitem" class="isx-backup-verify">
													<span class="dashicons dashicons-yes-alt"></span>
													<span><?php esc_html_e( 'Verify file', 'insightx-backup' ); ?></span>
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
