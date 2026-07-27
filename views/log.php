<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Error log screen — one entry per failed export/import/backup step, so an
 * admin can see what went wrong without needing server/PHP error log access.
 * With verbose logging on it also carries the full diagnostic trace (step
 * timings, S3 request details, loopback delivery, browser-side failures).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_cursor  = ISX_Logger::total_count();
$isx_entries = ISX_Logger::entries();
$isx_verbose = ISX_Logger::is_verbose();

// Entries written before levels existed have no 'level' — they were all errors.
$isx_filter = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
if ( $isx_filter !== '' ) {
	$isx_entries = array_values(
		array_filter(
			$isx_entries,
			function ( $entry ) use ( $isx_filter ) {
				return ( isset( $entry['level'] ) ? $entry['level'] : 'error' ) === $isx_filter;
			}
		)
	);
}
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Log ข้อผิดพลาด', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'บันทึกทุกครั้งที่ ส่งออก/นำเข้า/ข้อมูลสำรอง ล้มเหลว พร้อมรายละเอียด job/ขั้นตอนที่เกิดปัญหา', 'insightx-backup' ); ?></p>

		<?php if ( isset( $_GET['cleared'] ) ) : ?>
			<div class="isx-progress-warning" style="background:#e3f9f0;border-color:#a7ecd2;color:#0f766e;">
				<?php esc_html_e( 'ล้าง log แล้ว', 'insightx-backup' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( isset( $_GET['verbose_saved'] ) ) : ?>
			<div class="isx-progress-warning" style="background:#e3f9f0;border-color:#a7ecd2;color:#0f766e;">
				<?php esc_html_e( 'บันทึกการตั้งค่า log แล้ว', 'insightx-backup' ); ?>
			</div>
		<?php endif; ?>

		<form method="post" style="margin:16px 0;padding:14px 16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
			<?php wp_nonce_field( 'isx_log_verbose' ); ?>
			<label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;">
				<input type="checkbox" name="isx_verbose" value="1" <?php checked( $isx_verbose ); ?> style="margin-top:3px;">
				<span>
					<strong><?php esc_html_e( 'เปิดโหมด log ละเอียด (verbose)', 'insightx-backup' ); ?></strong><br>
					<span class="isx-muted" style="font-size:12px;">
						<?php esc_html_e( 'บันทึกทุกขั้นตอนพร้อมเวลาที่ใช้ รายละเอียดการเชื่อมต่อ Storage (cURL errno / timing / response header) และข้อผิดพลาดฝั่งเบราว์เซอร์ — เปิดเฉพาะตอนไล่ปัญหา เพราะ log จะยาวมาก', 'insightx-backup' ); ?>
					</span>
				</span>
			</label>
			<button type="submit" name="isx_log_verbose_save" value="1" class="button isx-btn isx-btn-secondary" style="margin-top:10px;">
				<?php esc_html_e( 'บันทึก', 'insightx-backup' ); ?>
			</button>
		</form>

		<p style="margin-bottom:8px;">
			<?php esc_html_e( 'กรองตามระดับ:', 'insightx-backup' ); ?>
			<?php
			$isx_levels = array(
				''      => __( 'ทั้งหมด', 'insightx-backup' ),
				'error' => __( 'ข้อผิดพลาด', 'insightx-backup' ),
				'warn'  => __( 'คำเตือน', 'insightx-backup' ),
				'info'  => __( 'ข้อมูล', 'insightx-backup' ),
				'debug' => __( 'รายละเอียด', 'insightx-backup' ),
			);
			foreach ( $isx_levels as $isx_key => $isx_label ) :
				$isx_url = add_query_arg(
					array(
						'page'  => 'isx_log',
						'level' => $isx_key,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $isx_url ); ?>" style="margin-right:10px;<?php echo $isx_filter === $isx_key ? 'font-weight:600;text-decoration:none;' : ''; ?>"><?php echo esc_html( $isx_label ); ?></a>
			<?php endforeach; ?>
		</p>

		<div class="isx-terminal">
			<div class="isx-terminal-head">
				<span class="isx-terminal-dot" style="background:#ff5f56;"></span>
				<span class="isx-terminal-dot" style="background:#ffbd2e;"></span>
				<span class="isx-terminal-dot" style="background:#27c93f;"></span>
				<span class="isx-terminal-title">isx-error.log</span>
				<span style="margin-left:auto;display:flex;align-items:center;gap:6px;font-size:11px;color:#4ade80;">
					<span style="width:7px;height:7px;border-radius:50%;background:#4ade80;display:inline-block;animation:isx-log-pulse 1.5s ease-in-out infinite;"></span>
					<?php esc_html_e( 'real-time', 'insightx-backup' ); ?>
				</span>
			</div>
			<pre id="isx-log-body" class="isx-terminal-body" data-cursor="<?php echo (int) $isx_cursor; ?>" data-level="<?php echo esc_attr( $isx_filter ); ?>"><?php
			if ( empty( $isx_entries ) ) {
				?><span id="isx-log-empty" class="isx-muted"><?php esc_html_e( 'ยังไม่มี log', 'insightx-backup' ); ?></span><?php
			} else {
				foreach ( $isx_entries as $isx_entry ) {
					echo ISX_Logger::render_line_html( $isx_entry ); // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped
				}
			}
			?></pre>
		</div>
		<style>@keyframes isx-log-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }</style>
		<script>
		(function ($) {
			var $body  = $('#isx-log-body');
			var cursor = parseInt($body.data('cursor'), 10) || 0;
			var level  = $body.data('level') || '';

			function poll() {
				$.post(isx.ajax_url, { action: 'isx_log_poll', nonce: isx.nonce, since: cursor, level: level })
					.done(function (res) {
						if (!res || !res.success) { return; }
						cursor = res.data.cursor;
						if (res.data.html) {
							var atBottom = ($body[0].scrollHeight - $body.scrollTop() - $body.innerHeight()) < 40;
							$('#isx-log-empty').remove();
							$body.append(res.data.html);
							if (atBottom) {
								$body.scrollTop($body[0].scrollHeight);
							}
						}
					})
					.always(function () {
						window.setTimeout(poll, 2000);
					});
			}
			window.setTimeout(poll, 2000);
		})(jQuery);
		</script>

		<div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
			<form method="post">
				<?php wp_nonce_field( 'isx_log_download' ); ?>
				<button type="submit" name="isx_log_download" value="1" class="button isx-btn isx-btn-secondary">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'ดาวน์โหลด Log', 'insightx-backup' ); ?>
				</button>
			</form>
			<form method="post" onsubmit="return window.confirm('<?php echo esc_js( __( 'ล้าง log ทั้งหมด?', 'insightx-backup' ) ); ?>');">
				<?php wp_nonce_field( 'isx_log_clear' ); ?>
				<button type="submit" name="isx_log_clear" value="1" class="button isx-btn isx-btn-secondary"><?php esc_html_e( 'ล้าง Log', 'insightx-backup' ); ?></button>
			</form>
		</div>
	</div>

	<div class="isx-card" style="margin-top:20px;">
		<h2 class="isx-title" style="font-size:16px;"><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'ข้อมูลระบบ', 'insightx-backup' ); ?></h2>
		<p class="isx-muted"><?php esc_html_e( 'แนบข้อมูลนี้ไปด้วยเวลาส่ง log ให้ทีมงานดู (ไฟล์ที่ดาวน์โหลดมีข้อมูลนี้อยู่แล้ว)', 'insightx-backup' ); ?></p>
		<pre class="isx-terminal-body" style="white-space:pre-wrap;"><?php echo esc_html( ISX_Admin::log_system_info() ); ?></pre>
	</div>
</div>
