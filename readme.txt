=== Bulk Page Duplicator ===
Contributors: nazim848
Donate link: https://buymeacoffee.com/nazim848
Tags: duplicate page, bulk duplicate, page generator, clone, duplicate post
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Duplicate pages in one click, generate hundreds from a template, and keep them up to date with Page Generators and Template Sync.

== Description ==
Bulk Page Duplicator turns one page into many. Duplicate any page or post in a single click, or build a template once (for example "Plumber in London") and generate a page for every city, service or product, with your text replaced on each one.

When the template changes, push the change to every generated page with Template Sync, and keep your list of cities in a reusable Page Generator so adding a new one is a single click.

Every feature is free.

= Who it is for =
* Agencies and site owners building location, service or landing pages at scale.
* SEO teams who need titles, descriptions and metadata replaced on every page.
* Anyone who just wants a quick "Duplicate" link for pages, posts and custom post types.

= One-click duplication =
* "Duplicate" link in the Pages and Posts lists, the admin bar, the classic editor and the block editor.
* "Duplicate" bulk action to copy several selected items at once.
* Copies content, custom fields, featured image, categories, tags and page builder data. Copies are saved as drafts.

= Bulk generation =
* Pick a template, enter the text to replace (for example `London`, or `London, UK` for several placeholders) and add one line of values per page.
* Paste values or import a `.csv` / `.txt` file.
* Replaces text in titles, slugs, content, excerpts, page builder data and SEO fields, matching case (`London` → `Paris`, `LONDON` → `PARIS`).
* Choose draft or published, a parent page, categories and tags, and whether to copy the featured image and the template's terms.
* Dry Run preview, live progress with ETA, a results log with CSV export, and one-click rollback of a whole run.
* Works with pages, posts and any public custom post type. Existing slugs are skipped, never overwritten.

= Page Generators =
* Save a template, its placeholders and its list of values as a generator (`Tools > Page Generators`, or `Save as Generator` on the bulk screen).
* Run it again at any time: only missing pages are created.
* Edit a value and its page is updated in place; remove a value and its page is moved to the trash (after confirmation).
* See every value with its page, status and edit/view links. Pages you created earlier from the same template are linked, not duplicated.

= Template Sync =
* Edit the template, then click `Sync changes` in its `Bulk Page Duplicator` box in the editor.
* Choose what to push: content, excerpt, page builder data, SEO meta, featured image, page template, terms, and (with confirmation) titles or slugs.
* Preview which pages will change. Pages edited by hand since they were generated are skipped unless you choose to overwrite them.
* A revision is saved before each page changes, including page builder data, so any sync can be undone from the Revisions screen.

= Works with =
* Page builders: Elementor, Beaver Builder, Bricks.
* SEO plugins: Yoast SEO, Rank Math, All in One SEO, SEOPress.

== How It Works ==
1. Go to `Tools > Bulk Page Duplicator`.
2. Choose a post type and select the template.
3. Enter the placeholder text, for example `London` or `London, UK`.
4. Add one line of replacement values per page, or import a CSV/TXT file.
5. Choose where to replace text (title, slug, content, page builder data, SEO data) and the other options.
6. Click `Dry Run (Preview)` to check the result, then `Start Duplication`.
7. Review the Results Log. Use Recent Operations to roll back a run if needed, or `Save as Generator` to manage these pages later.

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
* WordPress 5.0 or later, tested up to 7.1.
* PHP 7.2 or later.
* Pages, posts and any public custom post type.
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

= How do I duplicate a single page? =
Hover over the page in `Pages > All Pages` and click `Duplicate`, or use `Duplicate` in the admin bar or the editor. The copy is saved as a draft with the same content, custom fields, featured image and terms.

= Can I duplicate custom post types (CPTs)? =
Yes. The plugin supports any public post type.

= Does it skip duplicate slugs? =
Yes. If a generated slug already exists for that post type, the item is skipped and logged.

= Can I preview before creating content? =
Yes. Use `Dry Run (Preview)` to see totals, created/skipped counts, titles, and slugs.

= Can I undo a bulk operation? =
Yes. Recent Operations includes rollback to delete items created by a recorded operation.

= Who can use this plugin screen? =
Users with administrative capability (`manage_options`) can run bulk duplication, Page Generators and Template Sync. Anyone who can edit an item and create items of its type can use the one-click "Duplicate" links.

= What happens to pages I edited by hand when I sync a template? =
Template Sync detects items changed since they were generated or last synced and skips them unless you tick "Overwrite". A revision is saved before any item is changed.

= Does uninstalling delete my generated pages? =
No. Uninstalling removes the plugin's settings, history and generators, but generated pages are kept.

= Does this work with page builders? =
Yes. It supports Elementor, Beaver Builder, and Bricks data replacement when present.

= Does this work with SEO plugins? =
Yes. It supports metadata replacement for Yoast SEO, Rank Math, All in One SEO, and SEOPress.

= Is the plugin free? =
Yes. Every feature is included.

= Is the plugin translation ready? =
Yes. The plugin uses the `bulk-page-duplicator` text domain and includes a POT file in `languages/`.

== Screenshots ==

1. How it works: one template, a list of values, and a page for each value that you can keep in sync.
2. Bulk duplication: pick a template, enter the text to replace, add one value per line and check the live preview.
3. Page Generators: every value with its generated page, status and edit/view links. New values are created on the next run.
4. Template Sync: preview which pages will change. Pages edited by hand are skipped unless you choose to overwrite them.
5. One-click "Duplicate" and "Bulk Duplicate" links in the Pages list. Copies are saved as drafts.
6. Dry Run preview: see every title and slug before anything is created.
7. Results log with filters and CSV export, and Recent Operations to roll back a whole run.
8. All your Page Generators with their template, progress and last run.
9. The editor box: "Duplicate as new draft", how many pages a template generated, and a shortcut to Template Sync.

== Changelog ==

= 1.2.0 =
**New**

* One-click "Duplicate" links in the Pages and Posts lists, the admin bar, the classic editor and the block editor, plus a "Duplicate" bulk action.
* "Bulk Duplicate" link on each page that opens the bulk tool with that page as the template.
* Page Generators (Tools > Page Generators): save a template, its placeholders and values, and run it again at any time. New values create pages, edited values update their pages, and removed values move their pages to the trash after confirmation.
* "Save as Generator" button on the bulk duplication screen. Pages created earlier from the same template are linked instead of duplicated.
* Template Sync: push template changes (content, excerpt, Elementor, Beaver Builder, Bricks, SEO meta, featured image, page template, terms, title, slug) to every generated page, with a preview, hand-edit protection and a revision saved before each change.
* Editor box showing how many pages a template generated, or which template a page came from.
* "Copy Template Terms" option, and an "Open" link on the Plugins screen.

**Improved**

* Bulk duplication now copies the template's excerpt (with placeholder replacement) and menu order.

**Fixed**

* All in One SEO 4 titles, descriptions, social titles and focus keyphrases are now copied and replaced.
* Multiple placeholders no longer interfere with each other (for example the "uk" in "Dukeville" when "UK" is also a placeholder).
* Backslashes in content and Beaver Builder settings are kept.
* Page builder and SEO options are no longer saved as off while that plugin is inactive. If Elementor replacement was turned off this way, tick "Elementor Data" once to turn it back on.

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

= 1.2.0 =
Adds one-click Duplicate, reusable Page Generators and Template Sync to push template changes to generated pages, plus All in One SEO 4 and multi-placeholder fixes.

= 1.1.1 =
Security, data-integrity, rollback, compatibility, and large-site performance fixes.

= 1.1.0 =
Major release with Dry Run preview, CSV import, rollback history, taxonomy assignment, and improved builder support.

== License ==
Bulk Page Duplicator is free software released under the GPLv2 or later.
