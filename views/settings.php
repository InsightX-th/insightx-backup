<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Storage destinations settings screen — every provider stacked, own save button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_providers    = ISX_Destinations::providers();
$isx_destinations = ISX_Destinations::all();
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'ตั้งค่า Storage', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'ตั้งค่า credential ของแต่ละ provider แล้วกด "บันทึก" ทีละอัน — ใช้ตอนส่งออก/นำเข้าผ่าน Storage', 'insightx-backup' ); ?></p>

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
					<span class="isx-save-status <?php echo $isx_is_connected ? 'is-ok' : ''; ?>" aria-live="polite"><?php echo $isx_is_connected ? '✓ ' . esc_html__( 'เชื่อมต่อสำเร็จ', 'insightx-backup' ) : ''; ?></span>
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
							<p class="isx-field-hint"><?php esc_html_e( '🔐 เข้ารหัส AES-256-CBC ก่อนบันทึก — ค่าที่โชว์เป็นจุดคือตัวยึดตำแหน่ง ไม่ใช่ค่าจริง', 'insightx-backup' ); ?></p>
						</div>
					</div>
					<div class="isx-toggle-row">
						<label class="isx-field-checkbox">
							<input type="checkbox" data-field="path_style" value="1" <?php checked( ! empty( $isx_config['path_style'] ) ); ?> />
							<span><?php esc_html_e( 'ใช้ Path-style URL — จำเป็นสำหรับ Minio / Garage, ปิดสำหรับ AWS S3 (virtual-hosted)', 'insightx-backup' ); ?></span>
						</label>
					</div>
				</div>

				<div class="isx-actions">
					<button type="button" class="button button-primary isx-btn isx-save"><?php esc_html_e( 'บันทึก', 'insightx-backup' ); ?></button>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
