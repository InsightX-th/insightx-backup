<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Storage provider connections — credentials per provider, own save button.
 * Split out of the "Storage Settings" screen (which now only holds the local
 * backups dir + scheduled-backup options) so provider credentials get their
 * own dedicated menu item.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_providers    = ISX_Destinations::providers();
$isx_destinations = ISX_Destinations::all();
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Connections', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'Set up each provider\'s credentials and click "Save" one at a time — used when exporting/importing via Storage', 'insightx-backup' ); ?></p>

		<?php foreach ( $isx_providers as $isx_slug => $isx_meta ) : ?>
			<?php
			$isx_config       = isset( $isx_destinations[ $isx_slug ] ) ? $isx_destinations[ $isx_slug ] : array();
			$isx_is_connected = ISX_Destinations::is_configured( $isx_slug );
			?>
			<div class="isx-provider-block" data-provider="<?php echo esc_attr( $isx_slug ); ?>">
				<div class="isx-provider-head">
					<span class="isx-provider-head-main">
						<span class="isx-card-icon"><?php echo ISX_Destinations::icon( $isx_slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="isx-provider-title"><?php echo esc_html( $isx_meta['label'] ); ?></span>
					</span>
					<span class="isx-save-status <?php echo $isx_is_connected ? 'is-ok' : ''; ?>" aria-live="polite"><?php echo $isx_is_connected ? esc_html__( 'Connected successfully', 'insightx-backup' ) : ''; ?></span>
				</div>

				<div class="isx-connection">
					<div class="isx-grid">
						<div class="isx-field">
							<label><?php esc_html_e( 'Endpoint URL', 'insightx-backup' ); ?></label>
							<input type="text" data-field="endpoint" value="<?php echo esc_attr( $isx_config['endpoint'] ); ?>" placeholder="<?php echo esc_attr( $isx_meta['placeholders']['endpoint'] ); ?>" <?php disabled( ! empty( $isx_meta['endpoint_locked'] ) ); ?> <?php echo empty( $isx_meta['endpoint_locked'] ) ? 'required' : ''; ?> />
							<p class="isx-field-hint"><?php echo esc_html( $isx_meta['endpoint_hint'] ); ?></p>
						</div>
						<div class="isx-field">
							<label><?php esc_html_e( 'Region', 'insightx-backup' ); ?></label>
							<input type="text" data-field="region" value="<?php echo esc_attr( $isx_config['region'] ); ?>" placeholder="<?php echo esc_attr( $isx_meta['placeholders']['region'] ); ?>" required />
						</div>
						<div class="isx-field">
							<label><?php esc_html_e( 'Bucket', 'insightx-backup' ); ?></label>
							<input type="text" data-field="bucket" value="<?php echo esc_attr( $isx_config['bucket'] ); ?>" placeholder="<?php echo esc_attr( $isx_meta['placeholders']['bucket'] ); ?>" required />
						</div>
						<div class="isx-field">
							<label><?php esc_html_e( 'Access Key', 'insightx-backup' ); ?></label>
							<input type="text" data-field="access_key" value="<?php echo esc_attr( $isx_config['access_key'] ); ?>" autocomplete="off" placeholder="<?php echo esc_attr( $isx_meta['placeholders']['access_key'] ); ?>" required />
						</div>
						<div class="isx-field isx-field-wide">
							<label><?php esc_html_e( 'Secret Key', 'insightx-backup' ); ?></label>
							<input type="password" class="isx-secret" data-field="secret_key" autocomplete="new-password" data-has-secret="<?php echo $isx_config['secret_key'] !== '' ? '1' : '0'; ?>" value="<?php echo $isx_config['secret_key'] !== '' ? esc_attr( str_repeat( '•', 16 ) ) : ''; ?>" placeholder="<?php esc_attr_e( 'Secret Key', 'insightx-backup' ); ?>" required />
							<p class="isx-field-hint"><?php esc_html_e( '🔐 Encrypted with AES-256-CBC before saving', 'insightx-backup' ); ?></p>
						</div>
						<div class="isx-field isx-field-wide">
							<label><?php esc_html_e( 'Folder in bucket', 'insightx-backup' ); ?></label>
							<input type="text" data-field="prefix" value="<?php echo esc_attr( $isx_config['prefix'] ); ?>" placeholder="<?php echo esc_attr( ISX_Destinations::DEFAULT_PREFIX ); ?>" />
							<p class="isx-field-hint">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: default folder name */
										__( 'Leave empty to use %s — use / for nested folders, e.g. backups/production', 'insightx-backup' ),
										ISX_Destinations::DEFAULT_PREFIX
									)
								);
								?>
							</p>
						</div>
					</div>
					<div class="isx-toggle-row">
						<label class="isx-field-checkbox">
							<input type="checkbox" data-field="path_style" value="1" <?php checked( ! empty( $isx_config['path_style'] ) ); ?> />
							<span><?php esc_html_e( 'Use path-style URLs — required for Minio / Garage, off for AWS S3 (virtual-hosted)', 'insightx-backup' ); ?></span>
						</label>
					</div>
				</div>

				<div class="isx-actions">
					<button type="button" class="button button-primary isx-btn isx-save"><?php esc_html_e( 'Save', 'insightx-backup' ); ?></button>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
