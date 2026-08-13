=== Bulk Page Duplicator ===
Contributors: nazim848
Donate link: https://buymeacoffee.com/nazim848
Tags: bulk duplicate, duplicate pages, page duplicator, custom post type, elementor
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.1.1
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create pages and posts in bulk from one template with smart placeholder replacement, dry run preview, CSV import, and rollback history.

== Description ==
Bulk Page Duplicator helps you publish template-based content faster without manual copy/paste. Select a template item, define placeholder text, add replacement values, and generate multiple items in one run.

= Who this is for =
* Agencies building location or service pages at scale.
* Site owners creating many similar pages, posts, or custom post types.
* Teams using Elementor, Beaver Builder, or Bricks that need consistent template duplication.
* SEO workflows that need metadata copied and replaced across many items.

= What you can do =
* Duplicate any public post type (including custom post types).
* Replace one or multiple placeholders across title, slug, and content.
* Import replacement values from `.csv` or `.txt`, or paste values line by line.
* Choose output status (Publish or Draft).
* Set parent page behavior for hierarchical post types.
* Copy featured image to all created items.
* Assign taxonomy terms (such as categories and tags) to all generated items.
* Export duplication results as CSV for reporting.

= Why it is safer and faster =
* Dry Run preview shows what will be created before writing content.
* Existing slugs are automatically skipped to prevent duplicates.
* Real-time progress includes current item, percent complete, and ETA.
* Cancel in-progress operation from the UI.
* Recent operation history supports rollback (bulk delete created items).
* Saved user preferences reduce repeated setup.

== How It Works ==
1. Go to `Tools > Bulk Page Duplicator`.
2. Choose a post type and select the template item.
3. Enter placeholder text:
   * Single placeholder example: `London`
   * Multiple placeholder example: `London, UK`
4. Add replacement values in the textarea or import CSV/TXT.
5. Select where replacements should be applied (title, slug, content, builder data, SEO data).
6. Optionally set status, parent page, taxonomy terms, and featured image behavior.
7. Click `Dry Run (Preview)` to validate expected output.
8. Click `Start Duplication` to generate items in batches.
9. Review Results Log, filter statuses, export CSV, and use Recent Operations for rollback if needed.

== Feature Highlights ==
= Bulk generation core =
* Batch duplication workflow for large value sets.
* Supports pages, posts, and any public custom post type.
* Automatic skip when a generated slug already exists.

= Smart replacement behavior =
* Multiple placeholders supported in one run.
* Case-aware replacement behavior for more natural output.
* Slug-safe handling for multi-word placeholders and replacement values.

= Builder and SEO integrations =
* Page builder support:
  * Elementor
  * Beaver Builder
  * Bricks
* SEO metadata replacement support:
  * Yoast SEO
  * Rank Math
  * All in One SEO
  * SEOPress

= Workflow productivity =
* Searchable template selector with status, thumbnail, and metadata.
* Placeholder and values validation feedback.
* Dry Run summary with create vs skip counts.
* Browser completion notifications (when permission is granted).
* Results summary, filtering, and CSV export.

= Recovery and control =
* Recent operation history with timestamp and counts.
* Rollback action to delete items created by an operation.
* User preferences persisted for faster repeat runs.

== CSV/TXT Format Guide ==
= Single placeholder =
If placeholder is `London`, use one value per line:

`New York`
`Paris`
`Chicago`

= Multiple placeholders =
If placeholders are `London, UK`, each line must provide matching comma-separated values:

`New York, USA`
`Paris, France`
`Toronto, Canada`

= Validation rules =
* For multi-placeholder mode, each line should contain the same number of values as placeholders.
* Empty lines are ignored.
* Duplicate values are flagged in validation feedback.

== Compatibility ==
* WordPress: 5.0+
* Tested up to: 7.0
* PHP: 7.2+
* Works with public post types (excluding attachments in selector).
* Supports taxonomy assignment for the selected post type.
* Page builders: Elementor, Beaver Builder, Bricks.
* SEO plugins: Yoast SEO, Rank Math, All in One SEO, SEOPress.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress Plugins screen.
2. Activate `Bulk Page Duplicator` from `Plugins`.
3. Go to `Tools > Bulk Page Duplicator`.
4. Select your template and start generating content.

== Usage Tips ==
* Use unique placeholders (for example `CITYNAME`) to avoid accidental replacements.
* Start with `Draft` status for review workflows.
* Use Dry Run before large batches.
* Keep the browser tab open during long runs for progress updates.
* Use Recent Operations to rollback quickly if required.

== Frequently Asked Questions ==
= Can I duplicate custom post types (CPTs)? =
Yes. The plugin supports any public post type.

= Does it skip duplicate slugs? =
Yes. If a generated slug already exists for that post type, the item is skipped and logged.

= Can I preview before creating content? =
Yes. Use `Dry Run (Preview)` to see totals, created/skipped counts, titles, and slugs.

= Can I undo a bulk operation? =
Yes. Recent Operations includes rollback to delete items created by a recorded operation.

= Who can use this plugin screen? =
Users with administrative capability (`manage_options`) can run duplication actions.

= Does this work with page builders? =
Yes. It supports Elementor, Beaver Builder, and Bricks data replacement when present.

= Does this work with SEO plugins? =
Yes. It supports metadata replacement for Yoast SEO, Rank Math, All in One SEO, and SEOPress.

= Is the plugin translation ready? =
Yes. The plugin uses the `bulk-page-duplicator` text domain and includes a POT file in `languages/`.

== Changelog ==
= 1.1.1 =
* Hardened AJAX authorization and output handling.
* Fixed rollback tracking for concurrent and multi-batch operations.
* Preserved multi-valued metadata and optional featured image and Elementor behavior.
* Improved large-site template, taxonomy, and dry-run performance.
* Added complete uninstall cleanup and WordPress 5.0-compatible history dates.

= 1.1.0 =
* Added support for duplicating any public post type.
* Added multiple placeholder replacement.
* Added CSV/TXT import for replacement values.
* Added Dry Run preview mode.
* Added searchable template selector with thumbnails and metadata.
* Added featured image copy option.
* Added enhanced results log with summaries, filters, and CSV export.
* Added real-time progress feedback with ETA and current item tracking.
* Added saved user preferences.
* Added taxonomy term assignment for generated items.
* Added operation history with rollback support.
* Added improved validation and duplicate prevention.
* Added extended page builder support for Beaver Builder and Bricks.

= 1.0.1 =
* Improved capitalization handling for hyphenated names.
* Improved multi-word placeholder replacement in slugs.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 1.1.1 =
Security, data-integrity, rollback, compatibility, and large-site performance fixes.

= 1.1.0 =
Major release with Dry Run preview, CSV import, rollback history, taxonomy assignment, and improved builder support.

== License ==
Bulk Page Duplicator is free software released under the GPLv2 or later.
