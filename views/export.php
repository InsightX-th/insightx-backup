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
		<h1 class="isx-title"><span class="dashicons dashicons-database-export"></span> <?php esc_html_e( 'Export Site', 'insightx-backup' ); ?></h1>
		<p class="isx-muted"><?php esc_html_e( 'Pack the database and files into a single .wpress package, then download it or send it straight to Storage', 'insightx-backup' ); ?></p>

		<div id="isx-export-idle">
			<input type="hidden" id="isx-to-storage" value="" />
			<input type="hidden" id="isx-provider" value="<?php echo esc_attr( $isx_default ); ?>" />

			<div class="isx-findreplace">
				<div class="isx-fr-row">
					<span class="isx-fr-label"><?php esc_html_e( 'Find', 'insightx-backup' ); ?></span>
					<input type="text" class="isx-fr-old" placeholder="<?php esc_attr_e( '<text>', 'insightx-backup' ); ?>" />
					<span class="isx-fr-label"><?php esc_html_e( 'Replace with', 'insightx-backup' ); ?></span>
					<input type="text" class="isx-fr-new" placeholder="<?php esc_attr_e( '<other text>', 'insightx-backup' ); ?>" />
					<span class="isx-fr-label"><?php esc_html_e( 'in the database', 'insightx-backup' ); ?></span>
				</div>
			</div>
			<button type="button" class="button isx-btn isx-btn-secondary" id="isx-fr-add">+ <?php esc_html_e( 'Add', 'insightx-backup' ); ?></button>

			<details class="isx-advanced">
				<summary><?php esc_html_e( 'Advanced options', 'insightx-backup' ); ?> <span class="isx-muted">(<?php esc_html_e( 'click to expand', 'insightx-backup' ); ?>)</span></summary>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'Security options', 'insightx-backup' ); ?></h4>
					<label class="isx-checkbox-row">
						<input type="checkbox" class="isx-adv-checkbox" id="isx-opt-encrypt" data-option="encrypt" />
						<span><?php esc_html_e( 'Encrypt this backup with a password', 'insightx-backup' ); ?></span>
					</label>
					<div id="isx-encrypt-fields" class="isx-encrypt-fields" style="display:none;">
						<input type="password" id="isx-encrypt-password" placeholder="<?php esc_attr_e( 'Enter a password', 'insightx-backup' ); ?>" />
						<input type="password" id="isx-encrypt-password-confirm" placeholder="<?php esc_attr_e( 'Repeat the password', 'insightx-backup' ); ?>" />
						<p class="isx-field-hint"><?php esc_html_e( 'If you lose the password the backup cannot be restored — keep it safe', 'insightx-backup' ); ?></p>
					</div>
				</div>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'Compression options', 'insightx-backup' ); ?></h4>
					<label class="isx-radio-row">
						<input type="radio" name="isx-compression" class="isx-opt-compression" value="none" checked="checked" />
						<span><?php esc_html_e( 'No compression (fastest, largest file)', 'insightx-backup' ); ?></span>
					</label>
					<label class="isx-radio-row">
						<input type="radio" name="isx-compression" class="isx-opt-compression" value="gzip" />
						<span><?php esc_html_e( 'GZip (fast, good compression)', 'insightx-backup' ); ?></span>
					</label>
				</div>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'Database options', 'insightx-backup' ); ?></h4>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_spam_comments" /> <span><?php esc_html_e( 'Do not export spam comments', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_post_revisions" /> <span><?php esc_html_e( 'Do not export post revisions', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_database" /> <span><?php esc_html_e( 'Do not export the database', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="no_replace_email_domain" /> <span><?php esc_html_e( 'Do not replace email domain', 'insightx-backup' ); ?></span></label>
					<div class="isx-checkbox-row isx-picker-row">
						<label><input type="checkbox" class="isx-adv-checkbox" id="isx-opt-select-tables" data-option="_select_tables" /> <span><?php esc_html_e( 'Do not export selected tables', 'insightx-backup' ); ?></span></label>
						<button type="button" class="button isx-btn isx-btn-secondary isx-picker-btn" id="isx-tables-picker-btn"><?php esc_html_e( 'No tables selected', 'insightx-backup' ); ?></button>
					</div>
					<div id="isx-tables-picker" class="isx-picker-panel" style="display:none;">
						<p class="isx-hint"><?php esc_html_e( 'Select tables to exclude from the export', 'insightx-backup' ); ?></p>
						<div id="isx-tables-picker-list" class="isx-picker-list">
							<p class="isx-fetch-status"><?php esc_html_e( 'Loading...', 'insightx-backup' ); ?></p>
						</div>
					</div>
				</div>

				<div class="isx-adv-group">
					<h4><?php esc_html_e( 'File options', 'insightx-backup' ); ?></h4>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_media" /> <span><?php esc_html_e( 'Do not export the media library', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_themes" /> <span><?php esc_html_e( 'Do not export themes', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_inactive_themes" /> <span><?php esc_html_e( 'Do not export inactive themes', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_mu_plugins" /> <span><?php esc_html_e( 'Do not export must-use plugins', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_plugins" /> <span><?php esc_html_e( 'Do not export plugins', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_inactive_plugins" /> <span><?php esc_html_e( 'Do not export inactive plugins', 'insightx-backup' ); ?></span></label>
					<label class="isx-checkbox-row"><input type="checkbox" class="isx-adv-checkbox" data-option="exclude_cache_files" /> <span><?php esc_html_e( 'Do not export cache files', 'insightx-backup' ); ?></span></label>
					<div class="isx-checkbox-row isx-picker-row">
						<label><input type="checkbox" class="isx-adv-checkbox" id="isx-opt-select-files" data-option="_select_files" /> <span><?php esc_html_e( 'Do not export selected files', 'insightx-backup' ); ?></span></label>
						<button type="button" class="button isx-btn isx-btn-secondary isx-picker-btn" id="isx-files-picker-btn"><?php esc_html_e( 'No files selected', 'insightx-backup' ); ?></button>
					</div>
					<div id="isx-files-picker" class="isx-picker-panel" style="display:none;">
						<p class="isx-hint"><?php esc_html_e( 'Enter paths relative to wp-content/, one per line, e.g. uploads/2019 or plugins/old-plugin', 'insightx-backup' ); ?></p>
						<textarea id="isx-files-picker-textarea" rows="4" placeholder="uploads/2019&#10;plugins/old-plugin"></textarea>
					</div>
				</div>
			</details>

			<p class="isx-section-title"><?php esc_html_e( 'Export to Storage — choose a destination', 'insightx-backup' ); ?></p>

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
				<?php esc_html_e( 'Not set up yet? Go to the "Connections" menu to enter each provider\'s credentials first', 'insightx-backup' ); ?>
			</p>

			<div class="isx-actions">
				<button type="button" class="button isx-btn isx-btn-secondary" id="isx-export-start-file"><?php esc_html_e( 'Export to file', 'insightx-backup' ); ?></button>
				<button type="button" class="button button-primary isx-btn" id="isx-export-start-storage"><?php esc_html_e( 'Export to Storage', 'insightx-backup' ); ?></button>
			</div>
		</div>

		<div id="isx-export-progress" class="isx-progress-box" style="display:none;">
			<p class="isx-progress-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Exporting. Please do not close this page or navigate away until it finishes', 'insightx-backup' ); ?></p>
			<p class="isx-status"></p>
			<div class="isx-actions">
				<button type="button" class="button isx-btn isx-btn-outline" id="isx-export-cancel"><?php esc_html_e( 'Cancel', 'insightx-backup' ); ?></button>
			</div>
		</div>

		<div id="isx-export-done" class="isx-done-box" style="display:none;">
			<p class="isx-ok" id="isx-export-done-msg"></p>
			<a href="#" class="button button-primary isx-btn" id="isx-export-download"><?php esc_html_e( 'Download .wpress', 'insightx-backup' ); ?></a>
		</div>
	</div>
</div>
