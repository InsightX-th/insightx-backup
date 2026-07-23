<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Error log screen — one entry per failed export/import/backup step, so an
 * admin can see what went wrong without needing server/PHP error log access.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_entries = ISX_Logger::entries();

$isx_type_labels = array(
	'export' => __( 'ส่งออก', 'insightx-backup' ),
	'import' => __( 'นำเข้า', 'insightx-backup' ),
	'backup' => __( 'ข้อมูลสำรอง', 'insightx-backup' ),
	'system' => __( 'ระบบ', 'insightx-backup' ),
);
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Log ข้อผิดพลาด', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'บันทึกทุกครั้งที่ ส่งออก/นำเข้า/ข้อมูลสำรอง ล้มเหลว พร้อมรายละเอียด job/ขั้นตอนที่เกิดปัญหา เก็บไว้ล่าสุด 300 รายการ', 'insightx-backup' ); ?></p>

		<?php if ( isset( $_GET['cleared'] ) ) : ?>
			<div class="isx-progress-warning" style="background:#e3f9f0;border-color:#a7ecd2;color:#0f766e;">
				<?php esc_html_e( 'ล้าง log แล้ว', 'insightx-backup' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( empty( $isx_entries ) ) : ?>
			<p class="isx-muted"><?php esc_html_e( 'ยังไม่มี log ข้อผิดพลาด', 'insightx-backup' ); ?></p>
		<?php else : ?>
			<div class="isx-terminal">
				<div class="isx-terminal-head">
					<span class="isx-terminal-dot" style="background:#ff5f56;"></span>
					<span class="isx-terminal-dot" style="background:#ffbd2e;"></span>
					<span class="isx-terminal-dot" style="background:#27c93f;"></span>
					<span class="isx-terminal-title">isx-error.log</span>
				</div>
				<pre class="isx-terminal-body"><?php
				foreach ( $isx_entries as $isx_entry ) {
					$isx_type    = isset( $isx_entry['type'] ) ? $isx_entry['type'] : 'system';
					$isx_context = isset( $isx_entry['context'] ) && is_array( $isx_entry['context'] ) ? $isx_entry['context'] : array();
					$isx_detail  = array();
					foreach ( $isx_context as $isx_key => $isx_val ) {
						if ( $isx_val === '' || $isx_val === null ) {
							continue;
						}
						$isx_detail[] = $isx_key . '=' . ( is_scalar( $isx_val ) ? $isx_val : wp_json_encode( $isx_val ) );
					}

					$isx_time  = isset( $isx_entry['time'] ) ? date_i18n( 'Y-m-d H:i:s', (int) $isx_entry['time'] ) : '';
					$isx_label = isset( $isx_type_labels[ $isx_type ] ) ? $isx_type_labels[ $isx_type ] : $isx_type;
					$isx_line  = sprintf(
						'[%s] <span class="isx-terminal-tag">%s</span> %s%s',
						esc_html( $isx_time ),
						esc_html( strtoupper( $isx_type ) ),
						esc_html( '(' . $isx_label . ') ' . ( isset( $isx_entry['message'] ) ? $isx_entry['message'] : '' ) ),
						$isx_detail ? ' <span class="isx-terminal-ctx">' . esc_html( implode( ' ', $isx_detail ) ) . '</span>' : ''
					);
					echo $isx_line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- pieces already escaped above
				}
				?></pre>
			</div>

			<form method="post" style="margin-top:20px;" onsubmit="return window.confirm('<?php echo esc_js( __( 'ล้าง log ทั้งหมด?', 'insightx-backup' ) ); ?>');">
				<?php wp_nonce_field( 'isx_log_clear' ); ?>
				<button type="submit" name="isx_log_clear" value="1" class="button isx-btn isx-btn-secondary"><?php esc_html_e( 'ล้าง Log', 'insightx-backup' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
