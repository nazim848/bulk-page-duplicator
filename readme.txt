=== Bulk Page Duplicator ===
Contributors: nazim848
Donate link: https://buymeacoffee.com/nazim848
Tags: duplicate page, bulk duplicate, page generator, clone, duplicate post
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.3.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Duplicate pages in one click, generate hundreds from a template, and keep them up to date with Page Generators and Template Sync.

== Description ==
Bulk Page Duplicator turns one page into many. Duplicate any page or post in a single click, or build a template once (for example "Plumber in London") and generate a page for every city, service or product, with your text replaced on each one.

When the template changes, push the change to every generated page with Template Sync, and keep your list of cities in a reusable Page Generator so adding a new one is a single click.

Import CSV column headers as named placeholders such as `{{city}}`, replace text in custom fields, and build an "Areas we serve" hub with the Generated Pages List block or shortcode. WP-CLI commands let you run generators and template syncs from the command line.

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
* Paste values or import a `.csv` / `.txt` file. Quoted CSV values can contain commas; column headers can become named placeholders such as `{{city}}` and `{{phone_number}}`.
* Replaces text in titles, slugs, content, excerpts, custom fields, page builder data and SEO fields. The placeholder exactly as typed inserts the value exactly as entered (`London` → `iPhone Town`); other case variants get matching case (`LONDON` → `IPHONE TOWN`).
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
* Choose what to push: content, excerpt, custom fields, page builder data, SEO meta, featured image, page template, terms, and (with confirmation) titles or slugs.
* Preview which pages will change. Pages edited by hand since they were generated are skipped unless you choose to overwrite them.
* A revision is saved before each page changes, including page builder data, so any sync can be undone from the Revisions screen.

= Generated Pages List =
* Add the "Generated Pages List" block to a hub page and choose a template or generator, sort order, columns and excerpts.
* Or use `[bpd_generated_pages template="123" columns="3" orderby="title" show_excerpt="yes"]`. Replace `123` with your template ID, or use `generator="45"` to select a generator instead.
* With neither ID set, a generated page lists its siblings and a template lists its generated pages. The current page is excluded by default.
* Lists published items only, with 100 items by default. Set `limit="500"` for the maximum of 500 items; columns range from 1 to 4.

= WP-CLI =
Run commands as a WordPress administrator with `--user=<ID-or-login>`:

* List generators: `wp bulk-page-duplicator generator list --user=admin`
* Preview a generator run: `wp bulk-page-duplicator generator run 45 --dry-run --user=admin`
* Run a generator: `wp bulk-page-duplicator generator run 45 --user=admin`. Add `--no-update` to create missing pages without updating changed rows.
* Preview a template sync: `wp bulk-page-duplicator sync 123 --fields=content,excerpt --dry-run --user=admin`. Remove `--dry-run` to apply it; add `--overwrite-edited` only if you want to replace hand-edited pages too.

Replace the example IDs and `admin` with your own template, generator and administrator account.

= Works with =
* Page builders: Elementor, Beaver Builder, Bricks.
* SEO plugins: Yoast SEO, Rank Math, All in One SEO, SEOPress.

== How It Works ==
1. Go to `Tools > Bulk Page Duplicator`.
2. Choose a post type and select the template.
3. Enter the placeholder text, for example `London` or `London, UK`.
4. Add one line of replacement values per page, or import a CSV/TXT file.
5. Choose where to replace text (title, slug, content, custom fields, page builder data, SEO data) and the other options.
6. Click `Dry Run (Preview)` to check the result, then `Start Duplication`.
7. Review the Results Log. Use Recent Operations to roll back a run if needed, or `Save as Generator` to manage these pages later.

== CSV/TXT Format Guide ==

= Single placeholder =
If the placeholder is `London`, use one value per line:

    New York
    Paris
    Chicago

= Multiple placeholders and quoted values =
If the placeholders are `London, UK`, each line must contain matching comma-separated values. Wrap a value containing a comma in double quotes:

    New York, USA
    "Paris, France", EU
    Toronto, Canada

One line creates one item. Empty lines are ignored; quoted fields containing line breaks are not supported.

= Column headers and named placeholders =
When importing a CSV, the plugin offers to turn a first row of column names into placeholders. For example:

    City,Phone Number
    Manchester,0161 496 0000
    Birmingham,0121 496 0000

Accept the header suggestion to use `{{city}}, {{phone_number}}` as your placeholders. Write those tokens in your template's title, content or custom fields wherever each value should appear. The header row is removed from the replacement values when you accept it.

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

= How do I show links to my generated pages? =
Add the "Generated Pages List" block or `[bpd_generated_pages template="123"]` shortcode to a hub page. Replace `123` with your template ID. Only published items are listed.

= Can I replace text in ACF or other custom fields? =
Yes. Select "Custom Fields" in bulk duplication or a generator, or "Custom fields" in Template Sync. It replaces text in public custom fields, including nested array values used by ACF, Meta Box and similar plugins. Internal field keys beginning with an underscore are left unchanged. Untick this option to copy custom field values without replacing their text during duplication.

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

= 1.3.0 =
**New**

* Named placeholders from CSV column headers: `City,Phone Number` can become `{{city}}, {{phone_number}}`.
* Quoted CSV values containing commas, in bulk duplication and Page Generators.
* Custom field replacement in bulk duplication, Page Generators and Template Sync, including nested field data used by ACF, Meta Box and similar plugins.
* "Generated Pages List" block and `[bpd_generated_pages]` shortcode, with template or generator selection, sorting, columns and optional excerpts.
* WP-CLI commands to list and run generators and sync templates, with dry runs.

**Changed**

* A placeholder matching exactly as typed now inserts the value exactly as entered, preserving names such as "iPhone Town" and "McAllen". Other case variants still receive matching capitalization. Check existing templates with Dry Run before generating new pages.

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

= 1.3.0 =
Adds named CSV placeholders, custom field replacement, a Generated Pages List block/shortcode and WP-CLI. Exact-case placeholders now preserve input capitalization; preview existing templates with Dry Run.

= 1.2.0 =
Adds one-click Duplicate, reusable Page Generators and Template Sync to push template changes to generated pages, plus All in One SEO 4 and multi-placeholder fixes.

= 1.1.1 =
Security, data-integrity, rollback, compatibility, and large-site performance fixes.

= 1.1.0 =
Major release with Dry Run preview, CSV import, rollback history, taxonomy assignment, and improved builder support.

== License ==
Bulk Page Duplicator is free software released under the GPLv2 or later.
