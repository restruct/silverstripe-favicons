# Silverstripe Favicons

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

One project icon in, every favicon and touch icon browsers and devices ask for out: served at the
paths they probe, and linked from the frontend, the CMS (so the backend tab shows the site's icon,
not a generic one) and the login pages.

Browsers and home-screen shortcuts probe fixed root paths whether or not a page links them:
`/favicon.ico`, `/apple-touch-icon.png`, `/apple-touch-icon-precomposed.png`,
`/apple-touch-icon-152x152.png`, `/manifest.json` and more. Without a file there, each probe is a
404 that runs the full Silverstripe stack. This module answers all of them from one SVG or PNG.

## Requirements

- Silverstripe 5.4 or newer, or 6 (`silverstripe/framework ^5.4 || ^6`, admin, assets, siteconfig)
- PHP 8.1 or newer
- GD or Imagick, to resize the icon (GD is what Silverstripe uses by default, so it is almost always
  there)
- For an **SVG** source, a way to turn SVG into PNG: the Imagick extension with SVG support, or the
  `rsvg-convert` tool (see [SVG sources](#svg-sources))

`composer.json` is the source of truth for supported versions.

| Branch | Module version | Silverstripe | PHP |
|---|---|---|---|
| `main` | 1.x (unreleased) | 5.4+, 6 | 8.1+ |

## Installation

```sh
composer require restruct/silverstripe-favicons
```

Point it at your icon, then run `dev/build` (SS5) or `sake db:build` (SS6):

```yaml
# app/_config/favicons.yml
Restruct\Favicons\FaviconService:
  source: 'themes/mytheme/images/icon.svg'
```

`dev/build` prints one line saying what is being served, eg
`Favicons: Serving all favicon variants from file:themes/mytheme/images/icon.svg.`

### Remove physical icon files from the webroot

The web server serves an existing file before Silverstripe runs. A `public/favicon.ico`,
`public/apple-touch-icon.png` or `public/site.webmanifest` therefore **wins over this module** for
that path, and keeps serving the old icon. Delete them (some project skeletons ship a
`public/favicon.ico`).

### Remove icon tags from your theme

The module adds its `<link>` tags to every page without template changes. If your theme's
`Page.ss` already has its own `<link rel="icon">`, `apple-touch-icon` or `manifest` tags, remove
them, or browsers get two competing sets. Alternatively keep the theme's tags and set
`frontend_tags: false`, in which case the module still answers the root probes and tags the CMS and
login pages.

## The source icon

One icon, SVG or PNG, square, at least 512x512 px for a PNG. Transparent backgrounds are fine: the
variants that must be opaque get a background colour.

### From configuration

`source` is a resource string, resolved through Silverstripe's `ModuleResourceLoader`, the same
resolver behind `$resourceURL()` in templates:

| Form | Example |
|---|---|
| Module resource (`vendor/package:path`) | `mycompany/site-theme:client/images/icon.svg` |
| Path relative to the project root | `app/images/icon.png`, `themes/mytheme/images/icon.svg`, `public/icon.png` |
| Absolute filesystem path | `/srv/shared/icon.svg` |

A theme without its own `composer.json` is not a module, so refer to it by path.

### From the CMS (optional)

```yaml
Restruct\Favicons\FaviconService:
  allow_upload: true
```

adds a **Site icon (favicon)** upload field on Settings, under the tagline. A published upload
overrules the configured `source`; removing the upload falls back to it. The field's description
says what is currently being served.

- The field accepts **PNG**, and **SVG as well when
  [restruct/silverstripe-svg-images](https://github.com/restruct/silverstripe-svg-images) 3.x is
  installed** (it makes SVG a sanitised Image upload; core does not allow SVG uploads at all).
  Versions before 3.0 never sanitised uploads, so `composer.json` declares a conflict with them.
  JPEG is not accepted because an icon needs transparency; WebP and GIF are not, because they would
  only be converted to PNG anyway.
- Only the **published** file is used: icons are fetched by anonymous visitors. Saving Settings
  publishes the upload (SiteConfig owns it). Once published it is served to everyone at the icon
  paths, **whatever its CanView setting** in the files area: do not upload anything you would not
  put on the public site.
- With `allow_upload: false` (the default) a stored upload is ignored and the field is hidden. The
  database column exists either way, so switching the option never changes the schema.

## What gets served

All paths are relative to the site's base URL. "ETag" responses answer `If-None-Match` with a 304.

| Path | Content | Size | Background | Notes |
|---|---|---|---|---|
| `/favicon.ico` | ICO with PNG entries | 16, 32, 48 | transparent | `ico_sizes` |
| `/icon.svg` | the SVG source | vector | as drawn | only for an SVG source |
| `/apple-touch-icon.png` | PNG | 180 | `background_color` | iOS paints transparency black |
| `/apple-touch-icon-precomposed.png` | same as above | 180 | | legacy iOS probe |
| `/apple-touch-icon-120x120.png`, `-152x152`, `-167x167`, `-180x180` | PNG | as named | `background_color` | `touch_sizes` |
| `/apple-touch-icon-NxN[-precomposed].png`, any other N | nearest generated size | | | eg `240x240` gets the 180; `0x0` and non-square names are 404 |
| `/icon-192.png`, `/icon-512.png` | PNG | 192, 512 | transparent | referenced by the manifest |
| `/icon-maskable-512.png` | PNG | 512 | `background_color` | `maskable_padding` safe zone |
| `/site.webmanifest`, `/manifest.json` | web app manifest (JSON) | | | `manifest: true` (default) |
| anything in `extra_paths` | the variant it maps to | | | eg `sites/favicon.ico` |

Paths are matched case-insensitively (`/FAVICON.ICO` works). The PNG and ICO variants need GD or
Imagick, and for an SVG source an SVG rasteriser too. When those are missing, or generating them
failed in the last `failure_ttl` seconds, the variant is simply not served (404) and its tag is
left out.

Tags added to the `<head>` of frontend pages, the CMS and the Security (login) pages:

```html
<link rel="icon" href="/favicon.ico?v=..." sizes="32x32">
<link rel="icon" href="/icon.svg?v=..." type="image/svg+xml">   <!-- SVG source only -->
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=...">
<link rel="manifest" href="/site.webmanifest?v=...">           <!-- manifest: true -->
<meta name="theme-color" content="...">                        <!-- theme_color set -->
```

### Caching

- `?v=` is a hash of the source content, the module's generator version and the relevant config. A
  request carrying the current `v` is answered with `Cache-Control: public, max-age=31536000,
  immutable`. A bare probe (`/favicon.ico`, no query) gets `max_age` (one day) plus the ETag, so a
  changed icon reaches returning visitors within a day and revalidation is a 304 without a body.
- Generated variants are kept in a Silverstripe cache (`Psr\SimpleCache\CacheInterface.RestructFavicons`,
  filesystem by default), keyed on that hash. Nothing is written to the asset store and no files
  appear in the CMS. When the source changes the key changes, so the new icon is generated on its
  first request; when the cache is cleared (a fresh deploy without a persistent cache directory),
  each variant is regenerated once, which takes milliseconds for a PNG source. Point the cache at
  Redis or Memcached like any other Silverstripe cache to share it between servers.
- The icon routes run as a request middleware before routing, so no page logic runs and no new
  session is started. Core's trusted-proxy, allowed-hosts, session, cache-control, canonical-URL,
  authentication, basic-auth and password-expiration middleware run before it (so basic auth still
  protects a staging site's icons); middleware registered after it, such as URL specials, dev-URL
  confirmation, session-manager's LoginSession and versioned's stage handling, is skipped for icon
  requests (order listed from a booted SS5 and SS6 install).

## SVG sources

GD cannot read SVG, so the PNG and ICO variants need a rasteriser. The module uses **the first
available** of (`rasterisers` config on `Restruct\Favicons\Generator\SvgRasteriser`), and never
falls through to the next one when it fails:

1. **`rsvg-convert`**: the librsvg command-line tool (Debian/Ubuntu `librsvg2-bin`, Homebrew
   `librsvg`), run without a shell and killed after `rsvg_timeout` seconds. Found on `PATH`,
   `/usr/bin`, `/usr/local/bin` or `/opt/homebrew/bin`, or set `rsvg_convert_path` to an absolute
   path. Needs `proc_open`.
2. **`imagick`**, only where rsvg-convert is absent: the Imagick PHP extension, when ImageMagick
   reports an SVG reader. It renders at a density chosen from the SVG's declared size (so the raster
   is about 512 px whatever the file declares) and under pixel, memory and disk limits, restored
   afterwards. ImageMagick cannot be given a time limit that is safe in a long-running PHP worker,
   and which renderer it delegates SVG to (its own MSVG, librsvg, or Inkscape) differs per server
   and decides what the file can make it do. **Install `rsvg-convert` (`librsvg2-bin`) on servers
   that render SVG icons**; treat Imagick as the fallback.

Before either sees it, the SVG is **rebuilt from an allowlist** (`SvgSanitiser`):

- only SVG-namespace shape, text, structure, gradient, pattern, clip-path and mask elements are
  kept; scripts, `foreignObject`, animation, filters, fonts and anything in another namespace are
  left out;
- only unprefixed attributes are kept, minus event handlers and anything ending in `ref`; prefixed
  attributes (`xlink:*` apart from a vetted `xlink:href`, `xml:base`, `sodipodi:absref`,
  `inkscape:*`) are dropped;
- a reference survives only as a same-document `#id`, or on `<image>` as an embedded PNG, JPEG,
  GIF or WebP whose bytes match its label;
- CSS (style attributes, `<style>`, `url()` in presentation attributes) survives only without
  backslash escapes, `@` rules and any `url()` other than `url(#id)`;
- comments, processing instructions (`<?xml-stylesheet?>`) and DOCTYPEs are not carried over.

Two consequences for files from design tools:

- **SVG filters are stripped**, so a shape with a filter effect (eg a Figma or Illustrator drop
  shadow) renders without the effect. Flatten the effect into the artwork, or use a PNG.
- **An SVG with an entity block is refused.** Older Illustrator "Save As SVG" exports declare
  entities in a DOCTYPE; re-export with "Preserve Illustrator Editing Capabilities" switched off,
  or use a PNG. Entity declarations are not accepted on purpose (they are how XML loads files and
  expands bombs).

An SVG is refused outright on any XML parse error or warning, an entity declaration, a non-`<svg>`
root, more than 2 MB, more than 50 `#id` references or a reference chain deeper than 3 (a 1 KB
`<use>` bomb), a reference cycle, a geometry value beyond one million, or a declared width, height
or viewBox over 10000. A failed attempt is remembered for `failure_ttl` seconds (10 minutes) and
not retried in that time, and while one request renders a source, concurrent requests for it get a
404 instead of starting a second render. `/icon.svg` itself is served as stored (browsers load no
references from an icon), with a CSP that blocks scripts and loads, plus `sandbox`.

With neither available, an SVG source still serves `/icon.svg` and a manifest pointing at it, which
modern desktop browsers use; `favicon.ico` and the touch icons are not served. One warning is
logged, `dev/build` prints a red line and the CMS field explains it. The simplest fix where you
cannot install either: use (or upload) a PNG.

## Configuration reference

All on `Restruct\Favicons\FaviconService` unless noted.

| Option | Default | Meaning |
|---|---|---|
| `source` | `null` | The icon, see [From configuration](#from-configuration) |
| `allow_upload` | `false` | Show the upload field on Settings; a published upload overrules `source` |
| `upload_folder` | `'favicon'` | Asset folder for uploads |
| `background_color` | `'#ffffff'` | Fill for the opaque variants (touch icons, maskable); also the manifest default |
| `touch_icon_padding` | `0` | Margin around the icon on the touch icons, % of the edge per side |
| `maskable_padding` | `10` | Margin on the maskable icon, % per side (10 keeps it inside Android's 80% safe circle) |
| `ico_sizes` | `[16, 32, 48]` | Sizes inside `favicon.ico` (1-256) |
| `touch_sizes` | `[120, 152, 167, 180]` | `apple-touch-icon-NxN.png` sizes; 180 is always included |
| `manifest` | `true` | Serve `/site.webmanifest` and `/manifest.json` and link it |
| `manifest_fields` | see below | Manifest members |
| `theme_color` | `null` | `<meta name="theme-color">` and manifest `theme_color`; null emits nothing |
| `extra_paths` | `[]` | Extra request paths, `path: variant` |
| `frontend_tags` | `true` | Add the tags on frontend pages (ContentController) |
| `admin_tags` | `true` | Add the tags in the CMS (LeftAndMain) |
| `security_tags` | `true` | Add the tags on Security pages (login, lost password) |
| `max_age` | `86400` | Cache lifetime for a bare probe, seconds |
| `versioned_max_age` | `31536000` | Cache lifetime for a `?v=` URL, seconds (sent with `immutable`) |
| `failure_ttl` | `600` | Seconds a failed generation is remembered (variants withdrawn, not retried) |
| `svg_images_class` | `'Restruct\Silverstripe\SVG\SVGImage'` | Class whose presence allows SVG uploads |

`manifest_fields` defaults: `name` and `short_name` empty (both fall back to the SiteConfig
title), `start_url` empty (the site's base URL, so a subdirectory install works), `display: 'browser'` (adding the site to a home screen keeps it a normal
website; set `standalone` deliberately if you want app-like behaviour), `background_color` and
`theme_color` empty (falling back to the options above). Empty members are left out of the JSON.

On `Restruct\Favicons\Generator\SvgRasteriser`:

| Option | Default | Meaning |
|---|---|---|
| `rasterisers` | `['rsvg-convert', 'imagick']` | Order to try; remove one to rule it out |
| `rsvg_convert_path` | `'rsvg-convert'` | Binary name (searched) or absolute path |
| `rsvg_timeout` | `5` | Seconds before rsvg-convert is killed |
| `imagick_busy_ttl` | `60` | Seconds the in-progress marker lives when Imagick renders (rsvg-convert: `rsvg_timeout` + 5) |

Example with an alias for a probe seen in a 404 log:

```yaml
Restruct\Favicons\FaviconService:
  source: 'app/images/icon.svg'
  allow_upload: true
  background_color: '#0b3d91'
  touch_icon_padding: 8
  theme_color: '#0b3d91'
  manifest_fields:
    short_name: 'Example'
  extra_paths:
    'sites/favicon.ico': 'favicon.ico'
    'favicon.svg': 'icon.svg'
```

## Coexistence with silverstripe-admintweaks

`restruct/silverstripe-admintweaks` has its own `SiteConfig.FavIcon` upload, shown with its
`enable_logo_upload_fields` option. That relation is unrelated to this module: this module uses its
own `FaviconSource` relation and never reads `FavIcon`. To move an icon over, upload it again in
the new field (or set `source`) and stop using the admintweaks one.

## Running the tests

From a host project that requires this module through a symlinked path repository (`/tests` is
`export-ignore`, so a dist install has no tests):

```sh
# Silverstripe 5 (PHPUnit 9): the path first, then flush=1
vendor/bin/phpunit vendor/restruct/silverstripe-favicons/tests flush=1

# Silverstripe 6 (PHPUnit 11): a bare flush=1 is read as a test path, use the environment variable
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-favicons/tests
```

The tests for SVG rasterising skip when the machine has neither Imagick with SVG support nor
`rsvg-convert`.

Browser tests (Playwright, `tests/browser/`) check that the CMS links the configured icon and that
a Settings upload replaces it end to end. They run through the maintainers' shared runner, which
builds one scratch host per Silverstripe major, and in CI through `.github/workflows/browser-tests.yml`.

## License

MIT, see [LICENSE](LICENSE).
