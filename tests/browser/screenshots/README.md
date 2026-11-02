# README screenshots

`readme.shot.ts` writes the PNGs in `docs/images/` that the module README shows. It is not a test
suite: it runs with its own config, `playwright.screenshots.config.ts`, which the browser tests and
CI never load, so a normal test run never rewrites the images. Never edit the PNGs by hand;
regenerate them.

What it does, on the host:

- `settings-upload.png`: uploads `demo-icon.png` (this directory) in Settings, saves, and captures
  the field once it reports that the icon is served. The upload is left in place afterwards; the
  browser tests remove it before they start.
- `generated-icons.png`: a page drawn by the script around the images the host serves at
  `/favicon.ico`, `/apple-touch-icon.png`, `/icon-192.png` and `/icon-maskable-512.png`. The images
  are the module's real output; the grey frame, the labels and the rounded and circular masks
  (standing in for what iOS and Android do to the icons) are the script's.

`demo-icon.png` is a neutral 1024 px icon on a transparent background, drawn with plain GD by
`make-demo-icon.php` (`php make-demo-icon.php` rewrites it).

What it needs: a Silverstripe 6 host with this module installed and the browser fixtures from
`tests/browser/fixtures/` loaded (they switch on `allow_upload`), and `BROWSER_TARGET_URLS`
pointing at it (only the `ss6` target is used, else the first).

The shared runner in the maintainers' workspace does all of that:

```bash
BROWSER_ONLY=ss6 tools/browser/run.sh silverstripe-favicons --config=playwright.screenshots.config.ts
```

Against a host you started yourself, from `tests/browser/`:

```bash
npm ci
BROWSER_TARGET_URLS="ss6=http://127.0.0.1:8910" npx playwright test --config=playwright.screenshots.config.ts
```
