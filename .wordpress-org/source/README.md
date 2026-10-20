# WordPress.org screenshot assets

The numbered PNGs in the parent directory match the Screenshots section of
`readme.txt`. The 1.3.0 set replaces all nine older images and adds screenshots
10–13 for custom fields, the listing block, frontend output and WP-CLI.

## Resolution and capture

- `screenshot-1.png`: 3840 × 2160, rendered from `how-it-works.svg`.
- Interface screenshots: fresh Chromium captures at device scale factor 2,
  with a 1440 CSS-pixel viewport. Every exported PNG is at least 2400 pixels wide.
- No raster enlargement, JPEG compression or AI recreation of interface text.
- Browser chrome, staging permalinks and unrelated admin controls are excluded.
- Screenshots show demonstration content. Frontend styling comes from the theme.
- The CLI image presents actual command output in a styled terminal frame.

To export the graphic, render the SVG at a 1920 × 1080 CSS-pixel viewport with
device scale factor 2. Set its CSS width and height to those viewport dimensions
before capture; its intrinsic dimensions are the final 4K dimensions.

## Graphic direction

The built-in imagegen tool supplied the initial visual concept using the plugin
banner as a reference. The final graphic was redrawn as editable vector artwork
to keep typography and connectors sharp at 4K.

Prompt direction: modern three-stage template → CSV locations → generated pages
explainer; deep navy-to-violet background, warm yellow highlights, white document
illustrations, named placeholders, and preview/sync/rollback steps; match the
existing plugin banner; no URLs or browser frame.

## Publishing

Publish only `screenshot-*.png` to the WordPress.org SVN `assets` directory,
alongside the matching readme. Do not upload this source directory to SVN assets.
The release script excludes `.wordpress-org` from the installable plugin archive;
it does not copy these PNGs into SVN assets automatically, so include that step
when preparing the explicitly authorized release from its pushed Git tag.
