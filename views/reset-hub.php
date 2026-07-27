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
		'title' => 'ล้างปลั๊กอิน',
		'desc'  => 'ปิดใช้งานและลบปลั๊กอินทั้งหมดออกจากเว็บไซต์ ยกเว้น InsightX Backup เหมาะสำหรับแก้ปัญหาปลั๊กอินขัดแย้งกัน หรือเริ่มติดตั้งใหม่',
		'btn'   => 'ล้างปลั๊กอินทั้งหมด',
	),
	'theme'    => array(
		'icon'  => 'admin-appearance',
		'title' => 'รีเซ็ตธีม',
		'desc'  => 'ลบธีมทั้งหมดและสลับกลับไปใช้ธีมเริ่มต้นของ WordPress เหมาะสำหรับแก้ปัญหาที่เกี่ยวกับธีม หรือเริ่มต้นใหม่แบบสะอาด',
		'btn'   => 'รีเซ็ตธีม',
	),
	'media'    => array(
		'icon'  => 'admin-media',
		'title' => 'ล้างคลังสื่อ',
		'desc'  => 'ลบไฟล์สื่อทั้งหมดในคลังสื่อของเว็บไซต์ เหมาะสำหรับล้างไฟล์เก่าหรือไม่จำเป็นเพื่อจัดระเบียบเว็บไซต์',
		'btn'   => 'ล้างคลังสื่อ',
	),
	'database' => array(
		'icon'  => 'database',
		'title' => 'รีเซ็ตฐานข้อมูล',
		'desc'  => 'ลบข้อมูลทั้งหมดในฐานข้อมูลอย่างถาวรและคืนค่าเว็บไซต์กลับสู่สถานะเริ่มต้น รวมถึงโพสต์ เพจ ความคิดเห็น การตั้งค่า และผู้ใช้งาน (ยกเว้นการตั้งค่าของ InsightX Backup เอง)',
		'btn'   => 'รีเซ็ตฐานข้อมูล',
	),
	'full'     => array(
		'icon'  => 'image-rotate',
		'title' => 'รีเซ็ตทั้งเว็บไซต์',
		'desc'  => 'รีเซ็ตเว็บไซต์ทั้งหมดกลับสู่สถานะเริ่มต้นของการติดตั้ง WordPress ใหม่ เหมาะสำหรับเริ่มต้นใหม่ทั้งหมดหรือทำความสะอาดเว็บไซต์แบบเต็มรูปแบบ',
		'btn'   => 'รีเซ็ตทั้งเว็บไซต์',
	),
);
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'ศูนย์รีเซ็ต', 'insightx-backup' ); ?></h1>
		<p class="isx-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'เครื่องมือในหน้านี้ทำลายข้อมูลอย่างถาวรและย้อนกลับไม่ได้ กรุณาสร้างข้อมูลสำรองก่อนใช้งานทุกครั้ง', 'insightx-backup' ); ?></p>

		<div class="isx-reset-grid">
			<?php foreach ( $isx_reset_tools as $isx_tool_key => $isx_tool ) : ?>
				<div class="isx-reset-tool" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>">
					<h2 class="isx-reset-tool-title"><span class="dashicons dashicons-<?php echo esc_attr( $isx_tool['icon'] ); ?>"></span> <?php echo esc_html( $isx_tool['title'] ); ?></h2>
					<p class="isx-reset-tool-desc"><?php echo esc_html( $isx_tool['desc'] ); ?></p>
					<div class="isx-actions">
						<button type="button" class="button isx-btn isx-btn-danger isx-reset-start" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>"><?php echo esc_html( $isx_tool['btn'] ); ?></button>
						<button type="button" class="button isx-btn isx-btn-secondary isx-reset-backup" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>"><?php esc_html_e( 'สร้างข้อมูลสำรองก่อน', 'insightx-backup' ); ?></button>
					</div>
					<div class="isx-progress-box isx-reset-progress" data-tool="<?php echo esc_attr( $isx_tool_key ); ?>" style="display:none;">
						<p class="isx-status"></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'ข้อมูลสำรอง', 'insightx-backup' ); ?></h1>

		<div id="isx-backups-list">
			<?php if ( empty( $isx_backups ) ) : ?>
				<p class="isx-muted"><?php esc_html_e( 'ยังไม่มีข้อมูลสำรอง', 'insightx-backup' ); ?></p>
			<?php else : ?>
			<table class="isx-backups-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ชื่อ', 'insightx-backup' ); ?></th>
						<th><?php esc_html_e( 'วันที่สร้าง', 'insightx-backup' ); ?></th>
						<th><?php esc_html_e( 'เวลา', 'insightx-backup' ); ?></th>
						<th><?php esc_html_e( 'ขนาด', 'insightx-backup' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $isx_backups as $isx_backup ) : ?>
						<tr data-name="<?php echo esc_attr( $isx_backup['name'] ); ?>">
							<td class="isx-b-name"><span class="dashicons dashicons-media-archive"></span> <?php echo esc_html( $isx_backup['name'] ); ?></td>
							<td class="isx-b-date"><?php echo esc_html( ISX_Backups::format_thai_date( $isx_backup['mtime'] ) ); ?></td>
							<td class="isx-b-time"><?php echo esc_html( wp_date( 'H:i', $isx_backup['mtime'] ) ); ?></td>
							<td class="isx-b-size"><?php echo esc_html( $isx_backup['size_human'] ); ?></td>
							<td class="isx-b-actions">
								<div class="isx-backup-dots-wrap">
									<a href="#" role="button" aria-haspopup="true" class="isx-backup-dots" title="<?php esc_attr_e( 'เพิ่มเติม', 'insightx-backup' ); ?>"><span class="dashicons dashicons-ellipsis"></span></a>
									<div class="isx-backup-dots-menu">
										<ul role="menu">
											<li>
												<a tabindex="-1" href="#" role="menuitem" class="isx-backup-restore">
													<span class="dashicons dashicons-cloud-upload"></span>
													<span><?php esc_html_e( 'กู้คืน', 'insightx-backup' ); ?></span>
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
													<span><?php esc_html_e( 'ดาวน์โหลด', 'insightx-backup' ); ?></span>
												</a>
											</li>
											<li>
												<a tabindex="-1" href="#" role="menuitem" class="isx-backup-list-content">
													<span class="dashicons dashicons-list-view"></span>
													<span><?php esc_html_e( 'ดูรายการ', 'insightx-backup' ); ?></span>
												</a>
											</li>
											<li class="isx-divider"></li>
											<li>
												<a tabindex="-1" href="#" role="menuitem" class="isx-backup-delete">
													<span class="dashicons dashicons-no-alt"></span>
													<span><?php esc_html_e( 'ลบ', 'insightx-backup' ); ?></span>
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
			<button type="button" class="button button-primary isx-btn" id="isx-backups-create"><?php esc_html_e( 'สร้างข้อมูลสำรอง', 'insightx-backup' ); ?></button>
		</div>

		<div id="isx-backups-progress" class="isx-progress-box" style="display:none;">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'ระบบกำลังสำรองข้อมูล กรุณาอย่าปิดหน้านี้หรือย้ายไปหน้าอื่นจนกว่าจะเสร็จสิ้น', 'insightx-backup' ); ?></p>
			<div class="isx-progress-meta">
				<span class="isx-eta"></span>
			</div>
			<p class="isx-status"></p>
		</div>

		<div id="isx-backups-restore-progress" class="isx-progress-box" style="display:none;">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'ระบบกำลังกู้คืนข้อมูล กรุณาอย่าปิดหน้านี้หรือย้ายไปหน้าอื่นจนกว่าจะเสร็จสิ้น', 'insightx-backup' ); ?></p>
			<div class="isx-progress-meta">
				<span class="isx-eta"></span>
			</div>
			<p class="isx-status"></p>
		</div>

		<div id="isx-backups-restore-done" class="isx-done-box" style="display:none;">
			<p class="isx-ok" id="isx-backups-restore-done-msg"></p>
			<a href="<?php echo esc_url( wp_login_url() ); ?>" class="button button-primary isx-btn"><?php esc_html_e( 'ไปหน้าล็อกอิน', 'insightx-backup' ); ?></a>
		</div>
	</div>
</div>

<div id="isx-content-overlay" class="isx-modal-overlay" style="display:none;">
	<div class="isx-modal">
		<div class="isx-modal-head">
			<span><?php esc_html_e( 'แสดงเนื้อหาของข้อมูลสำรอง', 'insightx-backup' ); ?></span>
			<a href="#" id="isx-content-close" class="isx-modal-close">&times;</a>
		</div>
		<div id="isx-content-body" class="isx-modal-body">
			<p class="isx-fetch-status"><?php esc_html_e( 'กำลังโหลด...', 'insightx-backup' ); ?></p>
		</div>
	</div>
</div>

<div id="isx-reset-confirm-overlay" class="isx-modal-overlay" style="display:none;">
	<div class="isx-modal isx-reset-modal">
		<div class="isx-modal-head">
			<span id="isx-reset-confirm-title"><?php esc_html_e( 'ยืนยันการทำรายการ', 'insightx-backup' ); ?></span>
			<a href="#" id="isx-reset-confirm-close" class="isx-modal-close">&times;</a>
		</div>
		<div class="isx-modal-body">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <span id="isx-reset-confirm-warning"></span></p>
			<p>
				<label for="isx-reset-confirm-password"><?php esc_html_e( 'กรอกรหัสผ่านบัญชีของคุณเพื่อยืนยัน', 'insightx-backup' ); ?></label>
				<input type="password" id="isx-reset-confirm-password" class="regular-text" autocomplete="current-password" style="width:100%;" />
			</p>
			<p class="isx-fetch-status is-error" id="isx-reset-confirm-error" style="display:none;"></p>
			<div class="isx-actions">
				<button type="button" class="button isx-btn isx-btn-danger" id="isx-reset-confirm-submit"><?php esc_html_e( 'ยืนยัน ทำรายการนี้', 'insightx-backup' ); ?></button>
				<button type="button" class="button isx-btn isx-btn-secondary" id="isx-reset-confirm-cancel"><?php esc_html_e( 'ยกเลิก', 'insightx-backup' ); ?></button>
			</div>
		</div>
	</div>
</div>
