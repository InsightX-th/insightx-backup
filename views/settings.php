<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Storage settings screen — local backups dir + scheduled backup. Provider
 * credentials moved out to their own "Connections" menu (views/connections.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_providers = ISX_Destinations::providers();
$isx_schedule  = wp_parse_args(
	get_option( 'isx_schedule', array() ),
	array(
		'enabled'    => false,
		'interval'   => 'weekly',
		'to_storage' => '',
		'retain'     => 5,
	)
);
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'Storage Settings', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'Local backup storage and automatic schedule — set up each provider\'s credentials in the "Connections" menu', 'insightx-backup' ); ?></p>

		<div class="isx-provider-block" id="isx-storage-dir-block">
			<div class="isx-provider-head">
				<span class="isx-provider-head-main">
					<span class="dashicons dashicons-category"></span>
					<span class="isx-provider-title"><?php esc_html_e( 'Local backup folder', 'insightx-backup' ); ?></span>
				</span>
			</div>
			<p class="isx-muted"><?php esc_html_e( 'Where export/import jobs run and exported .wpress files are kept locally — leave empty to use the default (inside the plugin folder)', 'insightx-backup' ); ?></p>
			<div class="isx-field isx-field-wide">
				<label><?php esc_html_e( 'Path (absolute)', 'insightx-backup' ); ?></label>
				<input type="text" id="isx-storage-dir-input" value="<?php echo esc_attr( ISX_STORAGE_PATH ); ?>" placeholder="/home/user/isx-backups" />
				<p class="isx-field-hint"><?php esc_html_e( 'Must be a full path on the server, and the parent folder must already exist and be writable — changing it does not move old files automatically', 'insightx-backup' ); ?></p>
			</div>
			<div class="isx-actions">
				<button type="button" class="button button-primary isx-btn" id="isx-storage-dir-save"><?php esc_html_e( 'Save', 'insightx-backup' ); ?></button>
				<span class="isx-save-status" id="isx-storage-dir-status" aria-live="polite"></span>
			</div>
		</div>

		<div class="isx-provider-block" id="isx-schedule-block">
			<div class="isx-provider-head">
				<span class="isx-provider-head-main">
					<span class="dashicons dashicons-clock"></span>
					<span class="isx-provider-title"><?php esc_html_e( 'Automatic Backup', 'insightx-backup' ); ?></span>
				</span>
			</div>
			<p class="isx-muted"><?php esc_html_e( 'Schedule automatic exports (driven by WP-Cron — like all of WordPress, it needs site visits/traffic to trigger)', 'insightx-backup' ); ?></p>

			<label class="isx-field-checkbox">
				<input type="checkbox" id="isx-schedule-enabled" <?php checked( ! empty( $isx_schedule['enabled'] ) ); ?> />
				<span><?php esc_html_e( 'Enable automatic backup', 'insightx-backup' ); ?></span>
			</label>

			<?php
			$isx_intervals = array(
				'daily'   => array(
					'label' => __( 'Daily', 'insightx-backup' ),
					'icon'  => 'dashicons-clock',
				),
				'weekly'  => array(
					'label' => __( 'Weekly', 'insightx-backup' ),
					'icon'  => 'dashicons-calendar-alt',
				),
				'monthly' => array(
					'label' => __( 'Monthly', 'insightx-backup' ),
					'icon'  => 'dashicons-calendar',
				),
			);
			$isx_interval_current = isset( $isx_intervals[ $isx_schedule['interval'] ] ) ? $isx_schedule['interval'] : 'weekly';
			?>
			<div class="isx-grid isx-schedule-grid">
				<div class="isx-field">
					<label><?php esc_html_e( 'Frequency', 'insightx-backup' ); ?></label>
					<div class="isx-import-from" id="isx-schedule-interval-picker">
						<button type="button" class="isx-import-from-toggle" id="isx-schedule-interval-toggle">
							<span class="isx-select-icon-current">
								<span class="isx-card-icon" id="isx-schedule-interval-icon"><span class="dashicons <?php echo esc_attr( $isx_intervals[ $isx_interval_current ]['icon'] ); ?>"></span></span>
								<span id="isx-schedule-interval-label"><?php echo esc_html( $isx_intervals[ $isx_interval_current ]['label'] ); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</button>
						<input type="hidden" id="isx-schedule-interval" value="<?php echo esc_attr( $isx_interval_current ); ?>" />
						<ul class="isx-import-from-menu" id="isx-schedule-interval-menu">
							<?php foreach ( $isx_intervals as $isx_interval_slug => $isx_interval_meta ) : ?>
								<li>
									<a href="#" data-value="<?php echo esc_attr( $isx_interval_slug ); ?>" data-label="<?php echo esc_attr( $isx_interval_meta['label'] ); ?>">
										<span class="isx-card-icon"><span class="dashicons <?php echo esc_attr( $isx_interval_meta['icon'] ); ?>"></span></span>
										<?php echo esc_html( $isx_interval_meta['label'] ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
				<div class="isx-field">
					<label><?php esc_html_e( 'Send to Storage', 'insightx-backup' ); ?></label>
					<?php
					$isx_schedule_label = __( 'Keep locally only', 'insightx-backup' );
					$isx_schedule_icon  = '<span class="dashicons dashicons-database"></span>';
					if ( $isx_schedule['to_storage'] !== '' && isset( $isx_providers[ $isx_schedule['to_storage'] ] ) ) {
						$isx_schedule_label = $isx_providers[ $isx_schedule['to_storage'] ]['label'];
						$isx_schedule_icon  = ISX_Destinations::icon( $isx_schedule['to_storage'] );
					}
					?>
					<div class="isx-import-from" id="isx-schedule-to-storage-picker">
						<button type="button" class="isx-import-from-toggle" id="isx-schedule-to-storage-toggle">
							<span class="isx-select-icon-current">
								<span class="isx-card-icon" id="isx-schedule-to-storage-icon"><?php echo $isx_schedule_icon; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span id="isx-schedule-to-storage-label"><?php echo esc_html( $isx_schedule_label ); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</button>
						<input type="hidden" id="isx-schedule-to-storage" value="<?php echo esc_attr( $isx_schedule['to_storage'] ); ?>" />
						<ul class="isx-import-from-menu" id="isx-schedule-to-storage-menu">
							<li>
								<a href="#" data-value="" data-label="<?php esc_attr_e( 'Keep locally only', 'insightx-backup' ); ?>" data-icon="dashicons">
									<span class="isx-card-icon"><span class="dashicons dashicons-database"></span></span>
									<?php esc_html_e( 'Keep locally only', 'insightx-backup' ); ?>
								</a>
							</li>
							<?php foreach ( $isx_providers as $isx_slug => $isx_meta ) : ?>
								<?php if ( ISX_Destinations::is_configured( $isx_slug ) ) : ?>
									<li>
										<a href="#" data-value="<?php echo esc_attr( $isx_slug ); ?>" data-label="<?php echo esc_attr( $isx_meta['label'] ); ?>">
											<span class="isx-card-icon"><?php echo ISX_Destinations::icon( $isx_slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
											<?php echo esc_html( $isx_meta['label'] ); ?>
										</a>
									</li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
				<div class="isx-field">
					<label><?php esc_html_e( 'Keep at most (files)', 'insightx-backup' ); ?></label>
					<input type="number" id="isx-schedule-retain" min="1" step="1" value="<?php echo esc_attr( (int) $isx_schedule['retain'] ); ?>" />
					<p class="isx-field-hint"><?php esc_html_e( 'Beyond this number, the oldest files are deleted automatically after a new backup succeeds, both locally and on Storage (this site\'s files only)', 'insightx-backup' ); ?></p>
				</div>
			</div>

			<div class="isx-actions">
				<button type="button" class="button button-primary isx-btn" id="isx-schedule-save"><?php esc_html_e( 'Save', 'insightx-backup' ); ?></button>
				<span class="isx-save-status" id="isx-schedule-status" aria-live="polite"></span>
			</div>
		</div>

		<div class="isx-provider-block" id="isx-cleanup-block">
			<div class="isx-provider-head">
				<span class="isx-provider-head-main">
					<span class="dashicons dashicons-trash"></span>
					<span class="isx-provider-title"><?php esc_html_e( 'Clean up stale uploads on Storage', 'insightx-backup' ); ?></span>
				</span>
			</div>
			<p class="isx-muted">
				<?php esc_html_e( 'Large uploads that never finish (connection dropped, server died midway, cancelled) leave parts behind in the bucket that never become a real file — they are invisible in the file list and cannot be deleted from the provider\'s web console, yet still incur storage costs. The plugin normally cleans them up by itself; this button does it right now without waiting for the next run', 'insightx-backup' ); ?>
			</p>

			<p class="isx-field-hint" style="margin-top:16px;"><?php esc_html_e( 'Choose which providers to check (none selected = check every configured provider)', 'insightx-backup' ); ?></p>
			<div class="isx-dest-cards" id="isx-cleanup-providers">
				<?php foreach ( $isx_providers as $isx_slug => $isx_meta ) : ?>
					<?php $isx_configured = ISX_Destinations::is_configured( $isx_slug ); ?>
					<button type="button" class="isx-dest-card <?php echo $isx_configured ? '' : 'is-unconfigured'; ?>" data-provider="<?php echo esc_attr( $isx_slug ); ?>" data-configured="<?php echo $isx_configured ? '1' : '0'; ?>">
						<span class="isx-card-icon"><?php echo ISX_Destinations::icon( $isx_slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="isx-dest-card-label"><?php echo esc_html( $isx_meta['label'] ); ?></span>
						<span class="isx-dest-card-check" aria-hidden="true"></span>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="isx-actions">
				<button type="button" class="button isx-btn isx-btn-outline" id="isx-cleanup-uploads"><?php esc_html_e( 'Check and clean up now', 'insightx-backup' ); ?></button>
				<span class="isx-save-status" id="isx-cleanup-status" aria-live="polite"></span>
			</div>
		</div>

	</div>
</div>
