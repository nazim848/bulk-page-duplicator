<?php

/**
 * Admin page view for Bulk Page Duplicator
 *
 * @package BulkPageDuplicator
 */
if (!defined('ABSPATH')) exit;

// Get public post types
$post_types = get_post_types(array('public' => true), 'objects');
// Exclude attachments
unset($post_types['attachment']);

// Get all pages for the initial dropdown
$pages = get_pages(array(
	'sort_column' => 'post_title',
	'sort_order' => 'ASC',
));
// SEO plugins detection
if (!class_exists('Bulk_Page_Duplicator_Core')) {
	require_once dirname(dirname(__FILE__)) . '/../includes/class-bulk-page-duplicator.php';
}
$core = new Bulk_Page_Duplicator_Core();
$seo_plugins = $core->detect_seo_plugins();
?>
<div class="wrap">
	<h1><?php esc_html_e('Bulk Page Duplicator', 'bulk-page-duplicator'); ?></h1>
	<div class="bulk-page-dup-container">
		<div class="bulk-page-dup-panel">
			<h2><?php esc_html_e('Post Type', 'bulk-page-duplicator'); ?></h2>
			<p><?php esc_html_e('Select the type of content you want to duplicate:', 'bulk-page-duplicator'); ?></p>
			<select id="post-type" class="widefat">
				<?php foreach ($post_types as $post_type) : ?>
					<option value="<?php echo esc_attr($post_type->name); ?>" <?php selected($post_type->name, 'page'); ?>>
						<?php echo esc_html($post_type->labels->singular_name); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<h2><?php esc_html_e('Select Template', 'bulk-page-duplicator'); ?></h2>
			<p><?php esc_html_e('Choose the item you want to use as a template for duplication:', 'bulk-page-duplicator'); ?></p>
			<div class="template-selector-wrapper">
				<input type="text" id="template-search" class="widefat" placeholder="<?php esc_attr_e('Type to search templates...', 'bulk-page-duplicator'); ?>" autocomplete="off">
				<input type="hidden" id="template-page" value="">
				<div id="template-dropdown" class="template-dropdown" style="display: none;"></div>
			</div>
			<div id="selected-template-info" class="selected-template-info" style="display: none;">
				<div class="template-thumbnail">
					<img id="template-thumb" src="" alt="">
					<span id="template-no-thumb" class="no-thumbnail"><?php esc_html_e('No image', 'bulk-page-duplicator'); ?></span>
				</div>
				<div class="template-details">
					<strong id="template-title"></strong>
					<span class="template-meta">
						<span id="template-status" class="template-status"></span>
						<span id="template-modified"></span>
					</span>
				</div>
				<button type="button" id="clear-template" class="button button-small">&times;</button>
			</div>
			<p class="description" id="template-loading" style="display: none;">
				<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
				<?php esc_html_e('Loading templates...', 'bulk-page-duplicator'); ?>
			</p>
			<h2><?php esc_html_e('Placeholders', 'bulk-page-duplicator'); ?></h2>
			<p><?php esc_html_e('Enter placeholder text(s) to replace. Use comma to separate multiple placeholders:', 'bulk-page-duplicator'); ?></p>
			<input type="text" id="placeholder-text" class="widefat" placeholder="London" aria-describedby="placeholder-help">
			<p class="description" id="placeholder-help">
				<?php esc_html_e('Examples: "London" (single) or "London, UK" (multiple, comma-separated)', 'bulk-page-duplicator'); ?>
			</p>
			<h2><?php esc_html_e('Replacement Values', 'bulk-page-duplicator'); ?></h2>
			<p id="replacement-help"><?php esc_html_e('Enter one value per line, or import from a CSV file:', 'bulk-page-duplicator'); ?></p>
			<p class="description" id="replacement-multi-help" style="display: none;">
				<?php esc_html_e('For multiple placeholders, separate values with commas (e.g., "New York, USA")', 'bulk-page-duplicator'); ?>
			</p>
			<div class="csv-import-section">
				<div class="csv-upload-area" id="csv-drop-zone">
					<span class="dashicons dashicons-upload"></span>
					<p><?php esc_html_e('Drag & drop a CSV file here, or', 'bulk-page-duplicator'); ?></p>
					<label class="button button-secondary">
						<?php esc_html_e('Browse Files', 'bulk-page-duplicator'); ?>
						<input type="file" id="csv-file-input" accept=".csv,.txt" style="display: none;">
					</label>
					<p class="description"><?php esc_html_e('Supported: .csv and .txt files', 'bulk-page-duplicator'); ?></p>
				</div>
				<div id="csv-preview" style="display: none;">
					<p class="csv-file-info">
						<span class="dashicons dashicons-media-spreadsheet"></span>
						<span id="csv-file-name"></span>
						<button type="button" class="button-link" id="csv-clear"><?php esc_html_e('Remove', 'bulk-page-duplicator'); ?></button>
					</p>
					<p id="csv-row-count"></p>
				</div>
			</div>
			<textarea id="replacement-values" class="widefat" rows="10" placeholder="New York&#10;Los Angeles&#10;Chicago"></textarea>
			<div id="parent-page-section">
				<h2><?php esc_html_e('Parent Page', 'bulk-page-duplicator'); ?></h2>
				<p><?php esc_html_e('Optionally assign a parent for all created items:', 'bulk-page-duplicator'); ?></p>
				<select id="parent-page" class="widefat">
					<option value="0"><?php esc_html_e('No parent (top level)', 'bulk-page-duplicator'); ?></option>
					<option value="template"><?php esc_html_e('Same as template', 'bulk-page-duplicator'); ?></option>
					<?php foreach ($pages as $page) : ?>
						<option value="<?php echo esc_attr($page->ID); ?>">
							<?php echo esc_html($page->post_title); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<h2><?php esc_html_e('Status', 'bulk-page-duplicator'); ?></h2>
			<p><?php esc_html_e('Select status for the created items:', 'bulk-page-duplicator'); ?></p>
			<select id="page-status" class="widefat">
				<option value="publish"><?php esc_html_e('Published', 'bulk-page-duplicator'); ?></option>
				<option value="draft"><?php esc_html_e('Draft', 'bulk-page-duplicator'); ?></option>
			</select>
			<div id="taxonomy-section" style="display: none;">
				<h2><?php esc_html_e('Categories & Tags', 'bulk-page-duplicator'); ?></h2>
				<p><?php esc_html_e('Assign taxonomy terms to all created items:', 'bulk-page-duplicator'); ?></p>
				<div id="taxonomy-loading" style="display: none;">
					<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
					<?php esc_html_e('Loading taxonomies...', 'bulk-page-duplicator'); ?>
				</div>
				<div id="taxonomy-list"></div>
				<p class="description" id="taxonomy-help">
					<?php esc_html_e('Select terms to assign to all duplicated items.', 'bulk-page-duplicator'); ?>
				</p>
			</div>
			<h2><?php esc_html_e('Where to Replace Text', 'bulk-page-duplicator'); ?></h2>
			<p><?php esc_html_e('Select where the text should be replaced:', 'bulk-page-duplicator'); ?></p>
			<div class="bulk-page-dup-checkbox-group">
				<label><input type="checkbox" id="replace-title" checked> <?php esc_html_e('Page Title', 'bulk-page-duplicator'); ?></label>
				<label><input type="checkbox" id="replace-slug" checked> <?php esc_html_e('Page Slug', 'bulk-page-duplicator'); ?></label>
				<label><input type="checkbox" id="replace-content" checked> <?php esc_html_e('Page Content', 'bulk-page-duplicator'); ?></label>
				<label><input type="checkbox" id="replace-elementor" checked> <?php esc_html_e('Elementor Data (if exists)', 'bulk-page-duplicator'); ?></label>
				<?php if (!empty($seo_plugins)) : ?>
					<label><input type="checkbox" id="replace-seo" checked> <?php esc_html_e('SEO Meta Data', 'bulk-page-duplicator'); ?></label>
				<?php endif; ?>
			</div>
			<h2><?php esc_html_e('Additional Options', 'bulk-page-duplicator'); ?></h2>
			<div class="bulk-page-dup-checkbox-group">
				<label><input type="checkbox" id="copy-featured-image" checked> <?php esc_html_e('Copy Featured Image', 'bulk-page-duplicator'); ?></label>
				<p class="description" style="margin-left: 24px;"><?php esc_html_e('Copy the template\'s featured image to all duplicated items.', 'bulk-page-duplicator'); ?></p>
			</div>
			<div class="bulk-page-dup-progress-container" style="display: none;">
				<h3><?php esc_html_e('Progress', 'bulk-page-duplicator'); ?></h3>
				<div class="bulk-page-dup-progress-bar">
					<div class="bulk-page-dup-progress-bar-inner"></div>
				</div>
				<p class="bulk-page-dup-progress-text">0%</p>
				<p class="bulk-page-dup-progress-details">
					<span id="progress-current-item"></span>
					<span id="progress-eta"></span>
				</p>
				<p class="bulk-page-dup-status-text"></p>
			</div>
			<p class="submit">
				<button id="dry-run" class="button button-secondary button-large"><?php esc_html_e('Dry Run (Preview)', 'bulk-page-duplicator'); ?></button>
				<button id="start-duplication" class="button button-primary button-large"><?php esc_html_e('Start Duplication', 'bulk-page-duplicator'); ?></button>
				<button id="cancel-duplication" class="button button-secondary button-large" style="display: none;"><?php esc_html_e('Cancel', 'bulk-page-duplicator'); ?></button>
			</p>
		</div>
		<div class="bulk-page-dup-panel">
			<div id="preview-panel" style="display: none;">
				<h2><?php esc_html_e('Preview', 'bulk-page-duplicator'); ?></h2>
				<p class="description"><?php esc_html_e('Preview of the first item that will be created:', 'bulk-page-duplicator'); ?></p>
				<div class="bulk-page-dup-preview">
					<div class="preview-field">
						<label><?php esc_html_e('Title:', 'bulk-page-duplicator'); ?></label>
						<span id="preview-title">-</span>
					</div>
					<div class="preview-field">
						<label><?php esc_html_e('Slug:', 'bulk-page-duplicator'); ?></label>
						<span id="preview-slug">-</span>
					</div>
					<div class="preview-field">
						<label><?php esc_html_e('Items to create:', 'bulk-page-duplicator'); ?></label>
						<span id="preview-count">0</span>
					</div>
				</div>
			</div>
			<h2><?php esc_html_e('Instructions', 'bulk-page-duplicator'); ?></h2>
			<ol>
				<li><?php esc_html_e('Select the page you want to duplicate.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Enter the placeholder text that exists in your template page.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Enter all the values you want to replace the placeholder with (one per line).', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Choose where the text should be replaced.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Click "Start Duplication" to begin the process.', 'bulk-page-duplicator'); ?></li>
			</ol>
			<h3><?php esc_html_e('Tips', 'bulk-page-duplicator'); ?></h3>
			<ul>
				<li><?php esc_html_e('Make sure your template page contains the placeholder text in all areas you want to replace.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Large numbers of pages will be processed in batches to avoid timeout issues.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Processing will continue in the background - don\'t close the browser tab.', 'bulk-page-duplicator'); ?></li>
			</ul>
			<div class="bulk-page-dup-log-container" style="display: none;">
				<h3><?php esc_html_e('Results Log', 'bulk-page-duplicator'); ?></h3>
				<div class="results-summary" id="results-summary" style="display: none;">
					<span class="summary-item summary-success"><span id="count-success">0</span> <?php esc_html_e('created', 'bulk-page-duplicator'); ?></span>
					<span class="summary-item summary-skipped"><span id="count-skipped">0</span> <?php esc_html_e('skipped', 'bulk-page-duplicator'); ?></span>
					<span class="summary-item summary-error"><span id="count-error">0</span> <?php esc_html_e('errors', 'bulk-page-duplicator'); ?></span>
				</div>
				<div class="log-controls">
					<div class="log-filters">
						<button type="button" class="button button-small log-filter active" data-filter="all"><?php esc_html_e('All', 'bulk-page-duplicator'); ?></button>
						<button type="button" class="button button-small log-filter" data-filter="success"><?php esc_html_e('Success', 'bulk-page-duplicator'); ?></button>
						<button type="button" class="button button-small log-filter" data-filter="skipped"><?php esc_html_e('Skipped', 'bulk-page-duplicator'); ?></button>
						<button type="button" class="button button-small log-filter" data-filter="error"><?php esc_html_e('Errors', 'bulk-page-duplicator'); ?></button>
					</div>
					<div class="log-actions">
						<button type="button" class="button button-small" id="export-results"><?php esc_html_e('Export CSV', 'bulk-page-duplicator'); ?></button>
					</div>
				</div>
				<div class="bulk-page-dup-log"></div>
			</div>
		</div>
	</div>
</div>

<!-- Dry Run Modal -->
<div id="dry-run-modal" class="bpd-modal" style="display: none;">
	<div class="bpd-modal-content">
		<div class="bpd-modal-header">
			<h2><?php esc_html_e('Dry Run Preview', 'bulk-page-duplicator'); ?></h2>
			<button class="bpd-modal-close" aria-label="<?php esc_attr_e('Close', 'bulk-page-duplicator'); ?>">&times;</button>
		</div>
		<div class="bpd-modal-body">
			<div id="dry-run-loading" style="text-align: center; padding: 40px;">
				<span class="spinner is-active" style="float: none;"></span>
				<p><?php esc_html_e('Generating preview...', 'bulk-page-duplicator'); ?></p>
			</div>
			<div id="dry-run-results" style="display: none;">
				<div class="bpd-dry-run-summary">
					<div class="bpd-summary-item bpd-summary-total">
						<span class="bpd-summary-count" id="dry-run-total">0</span>
						<span class="bpd-summary-label"><?php esc_html_e('Total', 'bulk-page-duplicator'); ?></span>
					</div>
					<div class="bpd-summary-item bpd-summary-create">
						<span class="bpd-summary-count" id="dry-run-create">0</span>
						<span class="bpd-summary-label"><?php esc_html_e('Will Create', 'bulk-page-duplicator'); ?></span>
					</div>
					<div class="bpd-summary-item bpd-summary-skip">
						<span class="bpd-summary-count" id="dry-run-skip">0</span>
						<span class="bpd-summary-label"><?php esc_html_e('Will Skip', 'bulk-page-duplicator'); ?></span>
					</div>
				</div>
				<div class="bpd-dry-run-list-wrapper">
					<table class="bpd-dry-run-table widefat">
						<thead>
							<tr>
								<th><?php esc_html_e('Status', 'bulk-page-duplicator'); ?></th>
								<th><?php esc_html_e('Value', 'bulk-page-duplicator'); ?></th>
								<th><?php esc_html_e('Title', 'bulk-page-duplicator'); ?></th>
								<th><?php esc_html_e('Slug', 'bulk-page-duplicator'); ?></th>
							</tr>
						</thead>
						<tbody id="dry-run-table-body">
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<div class="bpd-modal-footer">
			<button class="button button-secondary bpd-modal-close-btn"><?php esc_html_e('Close', 'bulk-page-duplicator'); ?></button>
			<button id="dry-run-proceed" class="button button-primary"><?php esc_html_e('Proceed with Duplication', 'bulk-page-duplicator'); ?></button>
		</div>
	</div>
</div>
