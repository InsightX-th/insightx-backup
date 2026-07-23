<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Storage settings screen — local backups dir + scheduled backup. Provider
 * credentials moved out to their own "การเชื่อมต่อ" menu (views/connections.php).
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
		<h1 class="isx-title"><span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'ตั้งค่า Storage', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'ที่เก็บ backup ในเครื่องและตารางเวลาอัตโนมัติ — ตั้งค่า credential ของแต่ละ provider ได้ที่เมนู "การเชื่อมต่อ"', 'insightx-backup' ); ?></p>

		<div class="isx-provider-block" id="isx-storage-dir-block">
			<div class="isx-provider-head">
				<span class="isx-provider-head-main">
					<span class="dashicons dashicons-category"></span>
					<span class="isx-provider-title"><?php esc_html_e( 'โฟลเดอร์เก็บ Backup ในเครื่อง', 'insightx-backup' ); ?></span>
				</span>
			</div>
			<p class="isx-muted"><?php esc_html_e( 'ที่เก็บงาน export/import ระหว่างรัน และไฟล์ .wpress ที่ export ไว้ในเครื่อง — ปล่อยว่างไว้ = ใช้ค่าเริ่มต้น (ในโฟลเดอร์ปลั๊กอินเอง)', 'insightx-backup' ); ?></p>
			<div class="isx-field isx-field-wide">
				<label><?php esc_html_e( 'Path (absolute)', 'insightx-backup' ); ?></label>
				<input type="text" id="isx-storage-dir-input" value="<?php echo esc_attr( ISX_STORAGE_PATH ); ?>" placeholder="/home/user/isx-backups" />
				<p class="isx-field-hint"><?php esc_html_e( 'ต้องเป็น path เต็มบนเซิร์ฟเวอร์ และโฟลเดอร์แม่ต้องมีอยู่แล้วและเขียนได้ — เปลี่ยนแล้วไม่ย้ายไฟล์เก่าให้อัตโนมัติ', 'insightx-backup' ); ?></p>
			</div>
			<div class="isx-actions">
				<button type="button" class="button button-primary isx-btn" id="isx-storage-dir-save"><?php esc_html_e( 'บันทึก', 'insightx-backup' ); ?></button>
				<span class="isx-save-status" id="isx-storage-dir-status" aria-live="polite"></span>
			</div>
		</div>

		<div class="isx-provider-block" id="isx-schedule-block">
			<div class="isx-provider-head">
				<span class="isx-provider-head-main">
					<span class="dashicons dashicons-clock"></span>
					<span class="isx-provider-title"><?php esc_html_e( 'Backup อัตโนมัติ', 'insightx-backup' ); ?></span>
				</span>
			</div>
			<p class="isx-muted"><?php esc_html_e( 'ตั้งเวลาให้ export อัตโนมัติเป็นระยะ (ขับด้วย WP-Cron — ต้องมีคนเข้าเว็บ/มีทราฟฟิกเพื่อกระตุ้นตามปกติของ WordPress)', 'insightx-backup' ); ?></p>

			<label class="isx-field-checkbox">
				<input type="checkbox" id="isx-schedule-enabled" <?php checked( ! empty( $isx_schedule['enabled'] ) ); ?> />
				<span><?php esc_html_e( 'เปิดใช้งาน backup อัตโนมัติ', 'insightx-backup' ); ?></span>
			</label>

			<?php
			$isx_intervals = array(
				'daily'   => array(
					'label' => __( 'รายวัน', 'insightx-backup' ),
					'icon'  => 'dashicons-clock',
				),
				'weekly'  => array(
					'label' => __( 'รายสัปดาห์', 'insightx-backup' ),
					'icon'  => 'dashicons-calendar-alt',
				),
				'monthly' => array(
					'label' => __( 'รายเดือน', 'insightx-backup' ),
					'icon'  => 'dashicons-calendar',
				),
			);
			$isx_interval_current = isset( $isx_intervals[ $isx_schedule['interval'] ] ) ? $isx_schedule['interval'] : 'weekly';
			?>
			<div class="isx-grid isx-schedule-grid">
				<div class="isx-field">
					<label><?php esc_html_e( 'ความถี่', 'insightx-backup' ); ?></label>
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
					<label><?php esc_html_e( 'ส่งขึ้น Storage', 'insightx-backup' ); ?></label>
					<?php
					$isx_schedule_label = __( 'เก็บในเครื่องอย่างเดียว', 'insightx-backup' );
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
								<a href="#" data-value="" data-label="<?php esc_attr_e( 'เก็บในเครื่องอย่างเดียว', 'insightx-backup' ); ?>" data-icon="dashicons">
									<span class="isx-card-icon"><span class="dashicons dashicons-database"></span></span>
									<?php esc_html_e( 'เก็บในเครื่องอย่างเดียว', 'insightx-backup' ); ?>
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
					<label><?php esc_html_e( 'เก็บไว้สูงสุด (ไฟล์)', 'insightx-backup' ); ?></label>
					<input type="number" id="isx-schedule-retain" min="1" step="1" value="<?php echo esc_attr( (int) $isx_schedule['retain'] ); ?>" />
					<p class="isx-field-hint"><?php esc_html_e( 'เกินจำนวนนี้ ไฟล์เก่าสุดในเครื่องจะถูกลบอัตโนมัติหลัง backup ใหม่สำเร็จ', 'insightx-backup' ); ?></p>
				</div>
			</div>

			<div class="isx-actions">
				<button type="button" class="button button-primary isx-btn" id="isx-schedule-save"><?php esc_html_e( 'บันทึก', 'insightx-backup' ); ?></button>
				<span class="isx-save-status" id="isx-schedule-status" aria-live="polite"></span>
			</div>
		</div>

	</div>
</div>
