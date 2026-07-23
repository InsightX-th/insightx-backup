<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Import screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_providers = ISX_Destinations::providers();
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'นำเข้าเว็บไซต์', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'เลือกไฟล์แพ็กเกจ .wpress ที่ export ไว้ — ระบบจะกู้คืนไฟล์และฐานข้อมูล พร้อมแทนที่ URL/path อัตโนมัติ', 'insightx-backup' ); ?></p>
		<div id="isx-import-idle" class="isx-dropzone">
			<p class="dashicons dashicons-upload"></p>
			<p><?php esc_html_e( 'ลากและปล่อยแบ็คอัพที่นี่เพื่อทำการนำเข้าข้อมูล', 'insightx-backup' ); ?></p>

			<input type="file" id="isx-import-file" accept=".wpress" style="display:none;" />

			<div class="isx-import-from" id="isx-import-from">
				<button type="button" class="isx-import-from-toggle" id="isx-import-from-toggle">
					<span><?php esc_html_e( 'นำเข้าจาก', 'insightx-backup' ); ?></span>
					<span class="dashicons dashicons-menu"></span>
				</button>
				<ul class="isx-import-from-menu" id="isx-import-from-menu">
					<li><a href="#" id="isx-import-from-file"><?php esc_html_e( 'ไฟล์', 'insightx-backup' ); ?></a></li>
					<?php foreach ( $isx_providers as $isx_slug => $isx_meta ) : ?>
						<?php $isx_configured = ISX_Destinations::is_configured( $isx_slug ); ?>
						<li>
							<a href="#" class="isx-import-from-provider <?php echo $isx_configured ? '' : 'is-unconfigured'; ?>" data-provider="<?php echo esc_attr( $isx_slug ); ?>">
								<?php echo esc_html( $isx_meta['label'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div id="isx-backup-list" class="isx-backup-list"></div>

			<details class="isx-manual" id="isx-manual" style="display:none;">
				<summary><?php esc_html_e( 'ไม่มีสิทธิ์ List? กรอกชื่อไฟล์เอง', 'insightx-backup' ); ?></summary>
				<div class="isx-manual-row">
					<input type="text" id="isx-import-key" placeholder="insightx-migrate/site.wpress" />
					<button type="button" class="button isx-btn isx-btn-secondary" id="isx-import-key-go"><?php esc_html_e( 'นำเข้าไฟล์นี้', 'insightx-backup' ); ?></button>
				</div>
			</details>
		</div>

		<div id="isx-import-progress" class="isx-progress-box" style="display:none;">
			<div class="isx-bar"><div class="isx-bar-fill"></div></div>
			<div class="isx-progress-meta">
				<span class="isx-percent">0%</span>
				<span class="isx-eta"></span>
			</div>
			<p class="isx-status"></p>
		</div>

		<div id="isx-import-done" class="isx-done-box" style="display:none;">
			<p class="isx-ok" id="isx-import-done-msg"></p>
			<a href="<?php echo esc_url( wp_login_url() ); ?>" class="button button-primary isx-btn"><?php esc_html_e( 'ไปหน้าล็อกอิน', 'insightx-backup' ); ?></a>
		</div>
	</div>
</div>
