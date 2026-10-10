# Changelog

## Unreleased

First release (would be 1.0.0).

### Added

- One icon source, SVG or PNG: `source` in YAML (any `ModuleResourceLoader` resource string or a
  project-relative path), optionally overruled by a published CMS upload on Settings with
  `allow_upload: true`. The upload accepts SVG only when restruct/silverstripe-svg-images is
  installed.
- Generated variants: `favicon.ico` (16/32/48, PNG entries), `icon.svg` for an SVG source,
  opaque `apple-touch-icon.png` (180) plus 120/152/167/180 size variants, `icon-192.png`,
  `icon-512.png`, a maskable 512 with a safe-zone margin, and a web manifest.
- Served at the paths browsers probe, including every `apple-touch-icon[-NxN][-precomposed].png`
  name (nearest generated size), `/manifest.json`, and any `extra_paths` aliases. Long-lived cache
  headers for versioned URLs, ETag and 304 for bare probes.
- `<link>` tags on frontend pages, in the CMS (LeftAndMain) and on the Security pages, without
  template changes. Each can be switched off.
- SVG to PNG with Imagick or `rsvg-convert`; without either an SVG source degrades to serving
  `icon.svg` only, with one logged warning, a `dev/build` line and a CMS hint.
