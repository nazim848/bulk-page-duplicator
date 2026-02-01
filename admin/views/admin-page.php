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
// SEO plugins and page builders detection
if (!class_exists('Bulk_Page_Duplicator_Core')) {
	require_once dirname(dirname(__FILE__)) . '/../includes/class-bulk-page-duplicator.php';
}
$core = new Bulk_Page_Duplicator_Core();
$seo_plugins = $core->detect_seo_plugins();
$page_builders = $core->detect_page_builders();
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
			<select id="template-page" class="widefat">
				<option value=""><?php esc_html_e('Select a template', 'bulk-page-duplicator'); ?></option>
				<?php foreach ($pages as $page) : ?>
					<option value="<?php echo esc_attr($page->ID); ?>">
						<?php echo esc_html($page->post_title); ?> (ID: <?php echo esc_html($page->ID); ?>)
					</option>
				<?php endforeach; ?>
			</select>
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
			<p id="replacement-help"><?php esc_html_e('Enter one value per line. Each line creates a new item:', 'bulk-page-duplicator'); ?></p>
			<p class="description" id="replacement-multi-help" style="display: none;">
				<?php esc_html_e('For multiple placeholders, separate values with commas (e.g., "New York, USA")', 'bulk-page-duplicator'); ?>
			</p>
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
			<h2><?php esc_html_e('Where to Replace Text', 'bulk-page-duplicator'); ?></h2>
			<p><?php esc_html_e('Select where the text should be replaced:', 'bulk-page-duplicator'); ?></p>
			<div class="bulk-page-dup-checkbox-group">
				<label><input type="checkbox" id="replace-title" checked> <?php esc_html_e('Page Title', 'bulk-page-duplicator'); ?></label>
				<label><input type="checkbox" id="replace-slug" checked> <?php esc_html_e('Page Slug', 'bulk-page-duplicator'); ?></label>
				<label><input type="checkbox" id="replace-content" checked> <?php esc_html_e('Page Content', 'bulk-page-duplicator'); ?></label>
				<?php foreach ($page_builders as $builder) : ?>
					<label><input type="checkbox" id="replace-<?php echo esc_attr($builder['id']); ?>" checked> <?php echo esc_html($builder['name']); ?> <?php esc_html_e('Data (if exists)', 'bulk-page-duplicator'); ?></label>
				<?php endforeach; ?>
				<?php if (!empty($seo_plugins)) : ?>
					<label><input type="checkbox" id="replace-seo" checked> <?php esc_html_e('SEO Meta Data', 'bulk-page-duplicator'); ?></label>
				<?php endif; ?>
			</div>
			<div class="bulk-page-dup-progress-container" style="display: none;">
				<h3><?php esc_html_e('Progress', 'bulk-page-duplicator'); ?></h3>
				<div class="bulk-page-dup-progress-bar">
					<div class="bulk-page-dup-progress-bar-inner"></div>
				</div>
				<p class="bulk-page-dup-progress-text">0%</p>
				<p class="bulk-page-dup-status-text"></p>
			</div>
			<p class="submit">
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
			<h2><?php esc_html_e('How It Works', 'bulk-page-duplicator'); ?></h2>
			<ol>
				<li><?php esc_html_e('Create a template page with placeholder text (e.g., "London" for a city-based service page).', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Select your template and enter the placeholder text to find and replace.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Enter replacement values (one per line) - each creates a new page.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Choose which areas to apply replacements (title, content, page builder data, etc.).', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Click "Start Duplication" to create all pages automatically.', 'bulk-page-duplicator'); ?></li>
			</ol>

			<h3><?php esc_html_e('Multiple Placeholders', 'bulk-page-duplicator'); ?></h3>
			<p class="description"><?php esc_html_e('You can use multiple placeholders separated by commas. For example:', 'bulk-page-duplicator'); ?></p>
			<ul>
				<li><?php esc_html_e('Placeholders: "London, UK" (two placeholders)', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Values: "New York, USA" and "Paris, France" (one per line)', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Result: "London" → "New York" and "UK" → "USA" in the first page', 'bulk-page-duplicator'); ?></li>
			</ul>

			<h3><?php esc_html_e('Page Builder Support', 'bulk-page-duplicator'); ?></h3>
			<p class="description"><?php esc_html_e('This plugin supports placeholder replacement in:', 'bulk-page-duplicator'); ?></p>
			<ul>
				<li><strong><?php esc_html_e('Elementor', 'bulk-page-duplicator'); ?></strong> - <?php esc_html_e('All text widgets, headings, and content areas', 'bulk-page-duplicator'); ?></li>
				<li><strong><?php esc_html_e('Beaver Builder', 'bulk-page-duplicator'); ?></strong> - <?php esc_html_e('Module settings and text content', 'bulk-page-duplicator'); ?></li>
				<li><strong><?php esc_html_e('Bricks Builder', 'bulk-page-duplicator'); ?></strong> - <?php esc_html_e('Element settings and nested content', 'bulk-page-duplicator'); ?></li>
			</ul>
			<p class="description"><?php esc_html_e('Keep the page builder options checked even if you\'re unsure - the plugin will only process data if it exists.', 'bulk-page-duplicator'); ?></p>

			<h3><?php esc_html_e('Tips for Best Results', 'bulk-page-duplicator'); ?></h3>
			<ul>
				<li><?php esc_html_e('Use unique placeholder text that won\'t accidentally match other content (e.g., "CITYNAME" instead of common words).', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('The replacement is case-preserving: "London" → "Paris", "LONDON" → "PARIS", "london" → "paris".', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Pages with duplicate slugs will be skipped - check the results log for details.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Use "Draft" status first to review pages before publishing.', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Large batches are processed automatically - don\'t close the browser tab during processing.', 'bulk-page-duplicator'); ?></li>
			</ul>

			<h3><?php esc_html_e('Common Use Cases', 'bulk-page-duplicator'); ?></h3>
			<ul>
				<li><?php esc_html_e('Location-based service pages (e.g., "Plumber in London" → multiple cities)', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Product variations (e.g., "Blue Widget" → multiple colors)', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Team member profiles with consistent layouts', 'bulk-page-duplicator'); ?></li>
				<li><?php esc_html_e('Landing pages for different campaigns or audiences', 'bulk-page-duplicator'); ?></li>
			</ul>
			<div class="bulk-page-dup-log-container" style="display: none;">
				<h3><?php esc_html_e('Results Log', 'bulk-page-duplicator'); ?></h3>
				<div class="bulk-page-dup-log"></div>
			</div>
		</div>
	</div>
</div>