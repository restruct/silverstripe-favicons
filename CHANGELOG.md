# Changelog

## 1.2.0 (2026-10-14)

### Added

- The root-path files (`favicon.ico`, `apple-touch-icon*.png`, `icon-*.png`, `site.webmanifest`,
  `manifest.json`, root-level `extra_paths` aliases, minus `disabled_paths`) are also written into
  the public webroot on `dev/build` and on a Settings save, so the web server serves them without
  PHP; the existing route stays as the fallback. Only a missing file, one from an older set or one
  replaced since is written: an unchanged `dev/build` reads and writes nothing. Skipped when the
  webroot is not writable (one `dev/build` line) and in dev mode; `write_public_files` and
  `write_public_files_in_dev` switch it. A foreign file in the way is renamed once in place (eg
  `favicon.bu-20261012.ico`) unless it is identical; files the module wrote are removed again when
  they are no longer answered (source removed, `disabled_paths`, feature off). `dev/build` prints
  what was written, renamed or removed.

### Changed

- README: "Remove physical icon files from the webroot" and the nginx note now describe the
  fallback cases only.

## 1.1.0 (2026-10-12)

### Changed

- A non-square PNG source is no longer refused: it is centre-cropped to a square of its shortest
  side when the icons are generated, through the image backend's `croppedResize()` (as `Fill()`
  does), so it works on any AssetStore. A source is now accepted when its shortest side is at least
  512 px and its longest side at most `max_source_size`. The upload hint says so; the unused
  `ERROR_NOT_SQUARE` translation is removed. Square sources generate exactly as before, so
  existing icon sets are kept.
- The composer description no longer says the source must be square.

### Added

- `updateManifestData` extension hook on `FaviconService` (now `Extensible`), called with the
  manifest members and the icon source before empty members are dropped. Documented next to
  `manifest_fields`, which also passes unknown keys through.
- `$FaviconTags` template variable (a `TemplateGlobalProvider`): the same tags the module inserts
  into the head, for projects that set `frontend_tags: false` and place them themselves. Never
  throws; an error is logged and renders nothing.
- `disabled_paths` config: request paths the module does not answer, so a project route can have
  them (eg `manifest.json`). A disabled path is also not linked: its head tag or manifest icon
  entry is left out. Every path is still answered by default.

## 1.0.0 (2026-10-11)

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
