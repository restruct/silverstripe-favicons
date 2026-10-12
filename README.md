# Silverstripe Favicons

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

One PNG in, every favicon and touch icon browsers and devices ask for out: served at the
paths they probe, and linked from the frontend, the CMS (so the backend tab shows the site's icon,
not a generic one) and the login pages.

Browsers and home-screen shortcuts probe fixed root paths whether or not a page links them:
`/favicon.ico`, `/apple-touch-icon.png`, `/apple-touch-icon-precomposed.png`,
`/apple-touch-icon-152x152.png`, `/manifest.json` and more. Without a file there, each probe is a
404 that runs the full Silverstripe stack. This module answers all of them from one PNG.

## Requirements

- Silverstripe 5.4 or newer, or 6 (`silverstripe/framework ^5.4 || ^6`, admin, assets, siteconfig)
- PHP 8.1 or newer
- A working Silverstripe image backend. The icons are drawn by the framework's own `Image_Backend`
  (Intervention Image 2 on Silverstripe 5, 3 on Silverstripe 6, with the GD driver by default), so
  whatever already resizes your site's images resizes the icons too. The module makes no GD or
  Imagick calls of its own.

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
  source: 'themes/mytheme/images/icon.png'
```

`dev/build` generates the icons and prints one line saying what is being served, eg
`Favicons: Serving all favicon variants from file:themes/mytheme/images/icon.png.` The line is red
when a source is set but cannot be used, with the reason.

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

One **PNG** of at least **512x512 px**: its shortest side at least 512 px, its longest side at
most `max_source_size` (2048 px by default). Square is best. A transparent background is fine and
usually best too: the variants that must be opaque get `background_color` behind the icon.

A **non-square** PNG is accepted and **centre-cropped** to a square of its shortest side, as online
favicon generators do: a 1024x600 source becomes its middle 600x600. It is cropped rather than
padded, because padding would invent a margin colour and put the icon off-centre in some variants.
If the part you want is not in the middle, crop the image yourself before uploading it. The crop is
done by the project's image backend (the same `croppedResize()` that `Fill()` uses), in the
AssetStore like everything else.

Anything else is refused with a reason, in the upload field, on `dev/build` and in the Settings
hint: a JPEG, WebP, GIF or SVG (also when renamed to `.png`; the file's header is checked, not its
name), a PNG whose shortest side is under 512 px (the 512 px variants would be upscaled and
blurry), and a PNG whose longest side is over `max_source_size`.

### Where the icon comes from

The first usable one of these wins:

1. **The CMS upload**, when `allow_upload: true` (see [From the CMS](#from-the-cms-optional)).
2. **admintweaks' Settings icon**: `SiteConfig.FavIcon` from
   [restruct/silverstripe-admintweaks](https://github.com/restruct/silverstripe-admintweaks), when
   that module is installed, its field is filled in, and `use_admintweaks` is on (the default).
3. **The configured `source`**.

A source that is set but unusable (eg a 64 px logo in the admintweaks field) is skipped, and the
next one is used; `dev/build` and the Settings hint say which one was skipped and why. The order
puts the most deliberate choice first: this module's own field exists only because a project
switched it on, admintweaks' field is a general "logos" field, and YAML is the developer's default.

### From configuration

`source` is a resource string, resolved through Silverstripe's `ModuleResourceLoader`, the same
resolver behind `$resourceURL()` in templates:

| Form | Example |
|---|---|
| Module resource (`vendor/package:path`) | `mycompany/site-theme:client/images/icon.png` |
| Path relative to the project root | `app/images/icon.png`, `themes/mytheme/images/icon.png`, `public/icon.png` |
| Absolute filesystem path | `/srv/shared/icon.png` |

It names one file. It is **not looked up through the theme stack**, so when the site switches to
another theme, update `source` to that theme's icon. A theme without its own `composer.json` is not
a module, so refer to it by path.

The file is read where it is: it does not become a File in the CMS.

### From the CMS (optional)

```yaml
Restruct\Favicons\FaviconService:
  allow_upload: true
```

adds a **Site icon (favicon)** upload field on Settings, under the tagline. A published upload
overrules everything else; removing the upload falls back to the next source. The field's
description says what is currently being served.

- The field accepts **PNG only**, and checks its size **at upload**: a refused file shows the
  reason in the field and is not stored. The field's own checks (allowed extensions, maximum file
  size, and silverstripe/mimevalidator's MIME check when installed) still run first.
- **Choosing an existing file from the files area is switched off**: that path skips upload
  validation. Saving Settings also re-checks a newly chosen icon, so one that reaches the relation
  some other way is refused on save.
- Only the **published** file is used: icons are fetched by anonymous visitors. Saving Settings
  publishes the upload (SiteConfig owns it). Once published it is served to everyone at the icon
  paths, **whatever its CanView setting** in the files area: do not upload anything you would not
  put on the public site.
- With `allow_upload: false` (the default) a stored upload is ignored and the field is hidden. The
  database column exists either way, so switching the option never changes the schema.

### With silverstripe-admintweaks

admintweaks' field is a general file picker: it also accepts a file **chosen from the files
area**, including one whose CanView is restricted, and does not check the image. Whatever it holds,
once it is a usable PNG, is **published at the icon paths** (as a re-encoded copy, see
[Storage](#storage)), whatever its permissions. Set `use_admintweaks: false` if editors use that
field for anything that must not be public.

Besides the icon (above), admintweaks' **`SiteConfig.ThemeBrowserColor`** is used as the theme
colour (`<meta name="theme-color">` and the manifest's `theme_color`) when it holds a hex colour and
`theme_color` is not configured. Set `use_admintweaks: false` to ignore both fields. This module's
own upload is a separate `FaviconSource` relation, so the two fields never collide.

## What gets served

All paths are relative to the site's base URL. Responses carry an ETag and answer `If-None-Match`
with a 304.

| Path | Content | Size | Background | Notes |
|---|---|---|---|---|
| `/favicon.ico` | ICO with PNG entries | 16, 32, 48 | transparent | `ico_sizes` |
| `/apple-touch-icon.png` | PNG | 180 | `background_color` | iOS paints transparency black |
| `/apple-touch-icon-precomposed.png` | same as above | 180 | | legacy iOS probe |
| `/apple-touch-icon-120x120.png`, `-152x152`, `-167x167`, `-180x180` | PNG | as named | `background_color` | `touch_sizes` |
| `/apple-touch-icon-NxN[-precomposed].png`, any other N | nearest generated size | | | eg `240x240` gets the 180; `0x0` and non-square names are 404 |
| `/icon-192.png`, `/icon-512.png` | PNG | 192, 512 | transparent | referenced by the manifest |
| `/icon-maskable-512.png` | PNG | 512 | `background_color` | `maskable_padding` safe zone |
| `/site.webmanifest`, `/manifest.json` | web app manifest (JSON) | | | `manifest: true` (default) |
| anything in `extra_paths` | the variant it maps to | | | eg `sites/favicon.ico` |

Paths are matched case-insensitively (`/FAVICON.ICO` works). If the image backend cannot decode
the source (a damaged PNG with a valid header), that is remembered for `failure_ttl` seconds: the
image paths are 404, their tags are left out, the manifest lists no icons, one warning is logged
and `dev/build` prints a red line.

Tags added to the `<head>` of frontend pages, the CMS and the Security (login) pages:

```html
<link rel="icon" href="/favicon.ico?v=..." sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=...">
<link rel="manifest" href="/site.webmanifest?v=...">           <!-- manifest: true -->
<meta name="theme-color" content="...">                        <!-- theme colour set -->
```

### Storage

The generated files live in the framework's **AssetStore**, so they work with whatever adapter the
project uses (local disk, S3 and so on), under `assets/_favicons/<set>/`:

- `icon.png`: the source as the image backend re-encodes it after shrinking it to the largest
  variant (512 px with the default sizes). Not the uploaded bytes: text, EXIF and other metadata
  chunks in the original are not published;
- its PNG variants, stored as asset variants of that file (`icon__Favicon180Opaque.png` and so on),
  each drawn on and written by the framework's image backend; an existing one is reused, not
  redrawn;
- `favicon.ico`, packed from the 16/32/48 variants.

None of these has a File record, so nothing appears in the CMS files area. `<set>` is a hash of
the source content and the generation config (sizes, colours, margins), so a new icon or new config
is a new folder.

**When a set is generated:** on `dev/build`, when the icon changes in Settings (on save and
publish), and otherwise by the first icon request (`/favicon.ico` and the like, which browsers send
on their own). **Never during a page render**: until a set is complete, pages carry no icon tags,
and an error in the tag code is logged and leaves the tags out rather than breaking the page. One
build per set runs at a time (a "busy" marker, `busy_ttl`): concurrent first requests get a 404
instead of each decoding the source. A source the backend cannot decode, or whose decode kills the
worker (memory or time limit), is remembered for `failure_ttl` and not retried in that time.

When a new set is complete, the previous one is deleted. If the store refuses that delete (file
permissions, a remote error, `dev/build` run as another user than the web server), the old files
stay behind with one logged warning; nothing else is affected.

Which sets exist is remembered in the module's cache
(`Psr\SimpleCache\CacheInterface.RestructFavicons`): a plain filesystem pool under `TEMP_PATH`,
deliberately not created through Silverstripe's `CacheFactory`, so `dev/build` on the command line,
the CMS and the site all read the same entries (the factory picks a different adapter for CLI and
web on Silverstripe 5, and segments by stage with silverstripe/versioned). If that cache is
cleared, the set it named is no longer known: it is left behind when the next one is generated (a
few small files), and the tags disappear until the next icon request or `dev/build` re-registers
it. Delete `assets/_favicons/` (or that prefix in your bucket) to remove leftovers.

**Several web servers sharing one remote store:** override that service with a shared pool (Redis,
Memcached), so every server reads the same registry. With the default per-server pool it still
works, at three costs:

- each server decodes the source once to build its own registry entry (the stored files themselves
  are reused: variants that exist are not redrawn);
- a server shows no icon tags until its first icon request or `dev/build`;
- servers with different libgd versions may encode `icon.png` differently. The first copy stored
  is kept (`CONFLICT_USE_EXISTING`) and the other servers adopt it, so they do not overwrite each
  other's copy.

The build lock (one build per set at a time) is a file in each server's `TEMP_PATH`, so it only
coordinates the requests of one server.

### Memory

Generation decodes the source once, through the image backend. Whether that bitmap counts against
PHP's `memory_limit` depends on how GD was built:

- **PHP's bundled libgd** (`gd_info()` reports "bundled", as in the official Docker images):
  libgd allocates through PHP's own allocator (php-src `ext/gd/libgd/gdhelpers.h` maps `gdMalloc`
  to `emalloc`), so the decode counts against `memory_limit` and a too-large source ends in a
  memory-limit fatal (remembered for `failure_ttl`).
- **An external libgd** (most distribution and Homebrew packages): its memory is invisible to
  `memory_limit`, so only the process size grows.

Peak process size (RSS) of one generation, measured on PHP 8.3 with Homebrew's external libgd
2.3.3 and the default GD driver:

| Source | Silverstripe 5 | Silverstripe 6 |
|---|---|---|
| 512 px | 54 MB | 50 MB |
| 1024 px | 66 MB | 58 MB |
| 2048 px | 99 MB | 79 MB |
| 4096 px | 237 MB | 176 MB |

That is why `max_source_size` defaults to 2048: a larger source adds nothing to a 512 px icon.

A non-square source costs one extra full-size bitmap at its peak: the backend's `croppedResize()`
works on a copy of the decoded image, which is released once the cropped square exists. The table
above was measured on square sources only. `max_source_size` bounds the longest side, so a
non-square source never decodes to more than a square one at the same limit.

### Caching and serving

- The root paths are answered by **streaming the file from the AssetStore**, not by redirecting to
  `/assets/...`: a redirect costs every probe a round trip, and some clients do not follow
  redirects for icons. With a remote adapter each uncached probe therefore reads from the store;
  the cache headers below keep that rare.
- `?v=` is a hash of the set and the manifest contents. A request carrying the current `v` is
  answered with `Cache-Control: public, max-age=31536000, immutable`. A bare probe (`/favicon.ico`,
  no query) gets `max_age` (one day) plus the ETag, so a changed icon reaches returning visitors
  within a day and revalidation is a 304 without a body.
- The icon routes run as a request middleware before routing, so no page logic runs and no new
  session is started. Core's trusted-proxy, allowed-hosts, session, cache-control, canonical-URL,
  authentication, basic-auth and password-expiration middleware run before it (so basic auth still
  protects a staging site's icons); middleware registered after it, such as URL specials, dev-URL
  confirmation, session-manager's LoginSession and versioned's stage handling, is skipped for icon
  requests (order listed from a booted SS5 and SS6 install).
- A 304 carries the variant's `Content-Type` too (not required, but some proxies key on it).

## Configuration reference

All on `Restruct\Favicons\FaviconService`.

| Option | Default | Meaning |
|---|---|---|
| `source` | `null` | The icon, see [From configuration](#from-configuration) |
| `allow_upload` | `false` | Show the upload field on Settings; a published upload overrules every other source |
| `upload_folder` | `'favicon'` | Asset folder for uploads |
| `use_admintweaks` | `true` | Use admintweaks' `FavIcon` and `ThemeBrowserColor` as fallbacks when set |
| `max_source_size` | `2048` | Largest accepted source edge in px, see [Memory](#memory); 0 for no limit |
| `busy_ttl` | `120` | Seconds a set counts as "being generated" if the worker building it dies |
| `background_color` | `'#ffffff'` | Fill for the opaque variants (touch icons, maskable); also the manifest default |
| `touch_icon_padding` | `0` | Margin around the icon on the touch icons, % of the edge per side |
| `maskable_padding` | `10` | Margin on the maskable icon, % per side (10 keeps it inside Android's 80% safe circle) |
| `ico_sizes` | `[16, 32, 48]` | Sizes inside `favicon.ico` (1-256) |
| `touch_sizes` | `[120, 152, 167, 180]` | `apple-touch-icon-NxN.png` sizes; 180 is always included |
| `manifest` | `true` | Serve `/site.webmanifest` and `/manifest.json` and link it |
| `manifest_fields` | see below | Manifest members |
| `theme_color` | `null` | `<meta name="theme-color">` and manifest `theme_color`; null falls back to admintweaks' `ThemeBrowserColor`, else nothing |
| `extra_paths` | `[]` | Extra request paths, `path: variant` |
| `frontend_tags` | `true` | Add the tags on frontend pages (ContentController) |
| `admin_tags` | `true` | Add the tags in the CMS (LeftAndMain) |
| `security_tags` | `true` | Add the tags on Security pages (login, lost password) |
| `max_age` | `86400` | Cache lifetime for a bare probe, seconds |
| `versioned_max_age` | `31536000` | Cache lifetime for a `?v=` URL, seconds (sent with `immutable`) |
| `failure_ttl` | `600` | Seconds an undecodable source (or one that killed the worker) is remembered (variants withdrawn, not retried) |
| `asset_folder` | `'_favicons'` | AssetStore folder for the generated sets |

`manifest_fields` defaults: `name` and `short_name` empty (both fall back to the SiteConfig
title), `start_url` empty (the site's base URL, so a subdirectory install works), `display:
'browser'` (adding the site to a home screen keeps it a normal website; set `standalone`
deliberately if you want app-like behaviour), `background_color` and `theme_color` empty (falling
back to the options above). Empty members are left out of the JSON. Keys other than these pass
through as they are, so members such as `id`, `scope` or `orientation` can be set the same way.

For a member that has to be computed (per environment, from SiteConfig, extra icons), add an
extension to `FaviconService` with an `updateManifestData` hook. It receives the members (by
reference, icons included) and the `IconSource` in use (or null), before empty members are dropped,
so setting a member to null removes it. The result is part of the `?v=` version, so a change is
picked up by browsers:

```php
use Restruct\Favicons\IconSource;
use SilverStripe\Core\Extension;

class ManifestExtension extends Extension
{
    protected function updateManifestData(array &$fields, ?IconSource $source): void
    {
        $fields['id'] = '/';
        $fields['scope'] = '/';
    }
}
```

```yaml
Restruct\Favicons\FaviconService:
  extensions:
    - App\Extensions\ManifestExtension
```

Example with an alias for a probe seen in a 404 log:

```yaml
Restruct\Favicons\FaviconService:
  source: 'app/images/icon.png'
  allow_upload: true
  background_color: '#0b3d91'
  touch_icon_padding: 8
  theme_color: '#0b3d91'
  manifest_fields:
    short_name: 'Example'
  extra_paths:
    'sites/favicon.ico': 'favicon.ico'
```

## Running the tests

From a host project that requires this module through a symlinked path repository (`/tests` is
`export-ignore`, so a dist install has no tests):

```sh
# Silverstripe 5 (PHPUnit 9): the path first, then flush=1
vendor/bin/phpunit vendor/restruct/silverstripe-favicons/tests flush=1

# Silverstripe 6 (PHPUnit 11): a bare flush=1 is read as a test path, use the environment variable
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-favicons/tests
```

Browser tests (Playwright, `tests/browser/`) check that the CMS and login pages link the
configured icon, that a Settings upload replaces it end to end, and that a too-small PNG is refused
at upload. They run through the maintainers' shared runner, which
builds one scratch host per Silverstripe major, and in CI through `.github/workflows/browser-tests.yml`.

## License

MIT, see [LICENSE](LICENSE).
