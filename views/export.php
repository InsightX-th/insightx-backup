<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 * Export screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isx_providers = ISX_Destinations::providers();
$isx_default   = 'amazon_s3';
?>
<div class="wrap isx-wrap">
	<div class="isx-card">
		<h1 class="isx-title"><span class="dashicons dashicons-database-export"></span> <?php esc_html_e( 'ส่งออกเว็บไซต์', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'แพ็กฐานข้อมูลไฟล์เป็นแพ็กเกจ .wpress เดียว ดาวน์โหลดเป็นไฟล์ หรือส่งขึ้น Storage โดยตรง', 'insightx-backup' ); ?></p>

		<div id="isx-export-idle">
			<input type="hidden" id="isx-to-storage" value="" />
			<input type="hidden" id="isx-provider" value="<?php echo esc_attr( $isx_default ); ?>" />

			<div class="isx-findreplace">
				<div class="isx-fr-row">
					<span class="isx-fr-label"><?php esc_html_e( 'ค้นหาคำว่า', 'insightx-backup' ); ?></span>
					<input type="text" class="isx-fr-old" placeholder="&lt;ข้อความ&gt;" />
					<span class="isx-fr-label"><?php esc_html_e( 'แทนที่ด้วย', 'insightx-backup' ); ?></span>
					<input type="text" class="isx-fr-new" placeholder="&lt;ข้อความอื่น&gt;" />
					<span class="isx-fr-label"><?php esc_html_e( 'ในฐานข้อมูล', 'insightx-backup' ); ?></span>
				</div>
			</div>
			<button type="button" class="button isx-btn isx-btn-secondary" id="isx-fr-add">+ <?php esc_html_e( 'เพิ่ม', 'insightx-backup' ); ?></button>

			<details class="isx-advanced">
				<summary><?php esc_html_e( 'ตัวเลือกขั้นสูง', 'insightx-backup' ); ?> <span class="isx-muted">(<?php esc_html_e( 'คลิกเพื่อขยาย', 'insightx-backup' ); ?>)</span></summary>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'ตัวเลือกความปลอดภัย', 'insightx-backup' ); ?></h4>
					<label class="isx-checkbox-row">
						<input type="checkbox" class="isx-adv-checkbox" id="isx-opt-encrypt" data-option="encrypt" />
						<span><?php esc_html_e( 'เข้ารหัสข้อมูลสำรองนี้ด้วยรหัสผ่าน', 'insightx-backup' ); ?></span>
					</label>
					<div id="isx-encrypt-fields" class="isx-encrypt-fields" style="display:none;">
						<input type="password" id="isx-encrypt-password" placeholder="<?php esc_attr_e( 'กรอกรหัสผ่าน', 'insightx-backup' ); ?>" />
						<input type="password" id="isx-encrypt-password-confirm" placeholder="<?php esc_attr_e( 'กรอกรหัสผ่านอีกครั้ง', 'insightx-backup' ); ?>" />
						<p class="isx-field-hint"><?php esc_html_e( 'ลืมรหัสผ่านแล้วจะกู้คืนไฟล์ไม่ได้ — โปรดเก็บรหัสผ่านไว้ให้ดี', 'insightx-backup' ); ?></p>
					</div>
				</div>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'ตัวเลือกการบีบอัด', 'insightx-backup' ); ?></h4>
					<label class="isx-radio-row">
						<input type="radio" name="isx-compression" class="isx-opt-compression" value="none" checked="checked" />
						<span><?php esc_html_e( 'ไม่บีบอัด (เร็วสุด ไฟล์ใหญ่สุด)', 'insightx-backup' ); ?></span>
					</label>
					<label class="isx-radio-row">
						<input type="radio" name="isx-compression" class="isx-opt-compression" value="gzip" />
						<span><?php esc_html_e( 'GZip (เร็ว บีบอัดได้ดี)', 'insightx-backup' ); ?></span>
					</label>
				</div>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'ตัวเลือกฐานข้อมูล', 'insightx-backup' ); ?></h4>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_spam_comments" /> <span><?php esc_html_e( 'ไม่รวมความคิดเห็นสแปม', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_post_revisions" /> <span><?php esc_html_e( 'ไม่รวมประวัติการแก้ไขโพสต์', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_database" /> <span><?php esc_html_e( 'ไม่รวมฐานข้อมูล', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="no_replace_email_domain" /> <span><?php esc_html_e( 'ไม่ต้องแทนที่โดเมนอีเมล', 'insightx-backup' ); ?></span></label>
					<div class="isx-checkbox-row isx-picker-row">
						<label><input type="checkbox" class="isx-adv-checkbox" id="isx-opt-select-tables" data-option="_select_tables" /> <span><?php esc_html_e( 'ไม่รวมตารางที่เลือก', 'insightx-backup' ); ?></span></label>
						<button type="button" class="button isx-btn isx-btn-secondary isx-picker-btn" id="isx-tables-picker-btn"><?php esc_html_e( 'ยังไม่ได้เลือกตาราง', 'insightx-backup' ); ?></button>
					</div>
					<div id="isx-tables-picker" class="isx-picker-panel" style="display:none;">
						<p class="isx-hint"><?php esc_html_e( 'เลือกตารางที่จะไม่รวมในการส่งออก', 'insightx-backup' ); ?></p>
						<div id="isx-tables-picker-list" class="isx-picker-list">
							<p class="isx-fetch-status"><?php esc_html_e( 'กำลังโหลด...', 'insightx-backup' ); ?></p>
						</div>
					</div>
				</div>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'ตัวเลือกไฟล์', 'insightx-backup' ); ?></h4>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_media" /> <span><?php esc_html_e( 'ไม่รวมคลังสื่อ', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_themes" /> <span><?php esc_html_e( 'ไม่รวมธีม', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_inactive_themes" /> <span><?php esc_html_e( 'ไม่รวมธีมที่ไม่ได้ใช้งาน', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_mu_plugins" /> <span><?php esc_html_e( 'ไม่รวมปลั๊กอิน must-use', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_plugins" /> <span><?php esc_html_e( 'ไม่รวมปลั๊กอิน', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_inactive_plugins" /> <span><?php esc_html_e( 'ไม่รวมปลั๊กอินที่ไม่ได้เปิดใช้งาน', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_cache_files" /> <span><?php esc_html_e( 'ไม่รวมไฟล์แคช', 'insightx-backup' ); ?></span></label>
					<div class="isx-checkbox-row isx-picker-row">
						<label><input type="checkbox" class="isx-adv-checkbox" id="isx-opt-select-files" data-option="_select_files" /> <span><?php esc_html_e( 'ไม่รวมไฟล์ที่เลือก', 'insightx-backup' ); ?></span></label>
						<button type="button" class="button isx-btn isx-btn-secondary isx-picker-btn" id="isx-files-picker-btn"><?php esc_html_e( 'ยังไม่ได้เลือกไฟล์', 'insightx-backup' ); ?></button>
					</div>
					<div id="isx-files-picker" class="isx-picker-panel" style="display:none;">
						<p class="isx-hint"><?php esc_html_e( 'พิมพ์ path ที่สัมพัทธ์กับ wp-content/ บรรทัดละ 1 รายการ เช่น uploads/2019 หรือ plugins/old-plugin', 'insightx-backup' ); ?></p>
						<textarea id="isx-files-picker-textarea" rows="4" placeholder="uploads/2019&#10;plugins/old-plugin"></textarea>
					</div>
				</div>
			</details>

			<p class="isx-section-title"><?php esc_html_e( 'ส่งออกไปยัง Storage — เลือกปลายทาง', 'insightx-backup' ); ?></p>

			<div class="isx-dest-cards">
				<?php foreach ( $isx_providers as $isx_slug => $isx_meta ) : ?>
					<?php $isx_configured = ISX_Destinations::is_configured( $isx_slug ); ?>
					<button type="button" class="isx-dest-card <?php echo $isx_slug === $isx_default ? 'is-selected' : ''; ?> <?php echo $isx_configured ? '' : 'is-unconfigured'; ?>" data-provider="<?php echo esc_attr( $isx_slug ); ?>" data-configured="<?php echo $isx_configured ? '1' : '0'; ?>">
						<span class="isx-card-icon"><?php echo ISX_Destinations::icon( $isx_slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="isx-dest-card-label"><?php echo esc_html( $isx_meta['label'] ); ?></span>
						<span class="isx-dest-card-check" aria-hidden="true"></span>
					</button>
				<?php endforeach; ?>
			</div>

			<p class="isx-hint">
				<?php esc_html_e( 'ยังไม่ได้ตั้งค่า? ไปที่เมนู "การเชื่อมต่อ" เพื่อกรอก credential ของแต่ละ provider ก่อน', 'insightx-backup' ); ?>
			</p>

			<div class="isx-actions">
				<button type="button" class="button isx-btn isx-btn-secondary" id="isx-export-start-file"><?php esc_html_e( 'ส่งออกเป็นไฟล์', 'insightx-backup' ); ?></button>
				<button type="button" class="button button-primary isx-btn" id="isx-export-start-storage"><?php esc_html_e( 'ส่งออกไปยัง Storage', 'insightx-backup' ); ?></button>
			</div>
		</div>

		<div id="isx-export-progress" class="isx-progress-box" style="display:none;">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'ระบบกำลังส่งออกข้อมูล กรุณาอย่าปิดหน้านี้หรือย้ายไปหน้าอื่นจนกว่าจะเสร็จสิ้น', 'insightx-backup' ); ?></p>
			<p class="isx-status"></p>
		</div>

		<div id="isx-export-done" class="isx-done-box" style="display:none;">
			<p class="isx-ok" id="isx-export-done-msg"></p>
			<a href="#" class="button button-primary isx-btn" id="isx-export-download"><?php esc_html_e( 'ดาวน์โหลด .wpress', 'insightx-backup' ); ?></a>
		</div>
	</div>
</div>
