<?php

/**
 * Saved generators list.
 *
 * Expects $bpd_generators_model, $bpd_generators_list and $bpd_generators_notice.
 *
 * @package BulkPageDuplicator
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap bpd-generators-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e('Page Generators', 'bulk-page-duplicator'); ?></h1>
	<a href="<?php echo esc_url(Bulk_Page_Duplicator_Generators_Admin::page_url(array('action' => 'new'))); ?>" class="page-title-action"><?php esc_html_e('Add New Generator', 'bulk-page-duplicator'); ?></a>
	<a href="<?php echo esc_url(admin_url('tools.php?page=bulk-page-duplicator')); ?>" class="page-title-action"><?php esc_html_e('One-off Bulk Duplication', 'bulk-page-duplicator'); ?></a>
	<hr class="wp-header-end">

	<?php if (!empty($bpd_generators_notice)) : ?>
		<div class="notice notice-<?php echo esc_attr($bpd_generators_notice['type']); ?> is-dismissible"><p><?php echo esc_html($bpd_generators_notice['message']); ?></p></div>
	<?php endif; ?>

	<p class="description"><?php esc_html_e('A generator remembers a template, its placeholders and a list of rows. Run it again at any time: missing items are created, edited rows are re-rendered and removed rows are moved to the trash.', 'bulk-page-duplicator'); ?></p>

	<table class="widefat striped bpd-generators-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e('Name', 'bulk-page-duplicator'); ?></th>
				<th scope="col"><?php esc_html_e('Template', 'bulk-page-duplicator'); ?></th>
				<th scope="col"><?php esc_html_e('Post Type', 'bulk-page-duplicator'); ?></th>
				<th scope="col"><?php esc_html_e('Generated', 'bulk-page-duplicator'); ?></th>
				<th scope="col"><?php esc_html_e('Last Run', 'bulk-page-duplicator'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if (empty($bpd_generators_list)) : ?>
				<tr>
					<td colspan="5"><?php esc_html_e('No generators yet. Create one, or use "Save as Generator" on the bulk duplication screen.', 'bulk-page-duplicator'); ?></td>
				</tr>
			<?php endif; ?>
			<?php foreach ($bpd_generators_list as $bpd_generator_item) : ?>
				<?php
				$bpd_edit_url = Bulk_Page_Duplicator_Generators_Admin::page_url(array('action' => 'edit', 'generator' => $bpd_generator_item['id']));
				$bpd_delete_url = wp_nonce_url(
					add_query_arg(array('action' => 'bpd_delete_generator', 'generator' => $bpd_generator_item['id']), admin_url('admin-post.php')),
					'bpd_delete_generator_' . $bpd_generator_item['id']
				);
				$bpd_template = get_post($bpd_generator_item['template_id']);
				$bpd_post_type_obj = get_post_type_object($bpd_generator_item['post_type']);
				$bpd_live_count = $bpd_generators_model->count_live_items($bpd_generator_item);
				$bpd_row_count = count($bpd_generator_item['rows']);
				?>
				<tr>
					<td>
						<strong><a class="row-title" href="<?php echo esc_url($bpd_edit_url); ?>"><?php echo esc_html($bpd_generator_item['name']); ?></a></strong>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url($bpd_edit_url); ?>"><?php esc_html_e('Edit', 'bulk-page-duplicator'); ?></a> | </span>
							<span class="trash"><a href="<?php echo esc_url($bpd_delete_url); ?>" class="submitdelete bpd-delete-generator" data-confirm="<?php esc_attr_e('Delete this generator? Its generated items will be kept.', 'bulk-page-duplicator'); ?>"><?php esc_html_e('Delete', 'bulk-page-duplicator'); ?></a></span>
						</div>
					</td>
					<td>
						<?php if ($bpd_template) : ?>
							<?php $bpd_template_edit = get_edit_post_link($bpd_template->ID); ?>
							<?php if ($bpd_template_edit) : ?>
								<a href="<?php echo esc_url($bpd_template_edit); ?>"><?php echo esc_html($bpd_template->post_title); ?></a>
							<?php else : ?>
								<?php echo esc_html($bpd_template->post_title); ?>
							<?php endif; ?>
						<?php else : ?>
							<em><?php esc_html_e('Template missing', 'bulk-page-duplicator'); ?></em>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html($bpd_post_type_obj ? $bpd_post_type_obj->labels->singular_name : $bpd_generator_item['post_type']); ?></td>
					<td>
						<?php
						/* translators: %1$d: number of generated items, %2$d: number of rows. */
						echo esc_html(sprintf(__('%1$d of %2$d rows', 'bulk-page-duplicator'), $bpd_live_count, $bpd_row_count));
						?>
					</td>
					<td>
						<?php if ($bpd_generator_item['last_run']) : ?>
							<?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $bpd_generator_item['last_run']), get_option('date_format') . ' ' . get_option('time_format'))); ?>
						<?php else : ?>
							<?php esc_html_e('Never', 'bulk-page-duplicator'); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
