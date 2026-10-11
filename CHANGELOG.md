# Changelog

## Unreleased

First release (would be 1.0.0).

### Added

- One icon source, a square PNG of at least 512x512 px: `source` in YAML (any
  `ModuleResourceLoader` resource string or a project-relative path, not looked up through the
  theme stack), optionally overruled by a published CMS upload on Settings with
  `allow_upload: true`. restruct/silverstripe-admintweaks' `SiteConfig.FavIcon` is used between
  the two when installed and set (`use_admintweaks`). An unusable source is skipped with its
  reason shown on `dev/build` and in the Settings hint.
- The upload field accepts PNG only and refuses non-square, too small or too large images at
  upload, on top of the field's own validator; picking an existing file from the files area is
  switched off, and saving Settings re-checks a newly chosen icon.
- Generated variants: `favicon.ico` (16/32/48, PNG entries), opaque `apple-touch-icon.png` (180)
  plus 120/152/167/180 size variants, `icon-192.png`, `icon-512.png`, a maskable 512 with a
  safe-zone margin, and a web manifest. Drawn through the framework's own `Image_Backend` (via
  `DBFile::manipulate()`, from one decode of the source per set), with no GD or Imagick calls in
  the module.
- Stored in the framework AssetStore under `_favicons/<set>/` (public, no File records, works with
  remote adapters), keyed on the source content and config; the stored source is re-encoded (no
  metadata chunks published); the previous set is deleted once a new one is complete, and a
  failing delete is logged, not fatal.
- Sets are generated on dev/build, when the icon changes in Settings, and by the first icon
  request, one build at a time and from a single decode of the source; never during a page render.
  Pages carry no icon tags until a set exists, and an error in the tag code leaves the tags out
  instead of breaking the page. `max_source_size` defaults to 2048 px.
- One build per set at a time through an atomic lock file in `TEMP_PATH` (`busy_ttl`, stale locks
  taken over); stored set files are written with `CONFLICT_USE_EXISTING`, so servers with
  different libgd builds do not overwrite each other.
- Served at the paths browsers probe, streamed from the AssetStore, including every
  `apple-touch-icon[-NxN][-precomposed].png` name (nearest generated size), `/manifest.json`, and
  any `extra_paths` aliases. Long-lived cache headers for versioned URLs, ETag and 304 for bare
  probes.
- `<link>` tags on frontend pages, in the CMS (LeftAndMain) and on the Security pages, without
  template changes. Each can be switched off.
- `theme_color` falls back to admintweaks' `SiteConfig.ThemeBrowserColor` when that holds a hex
  colour.
- A source the image backend cannot decode is remembered for `failure_ttl`: its image paths are
  404 and their tags left out, with one logged warning and a red `dev/build` line.
