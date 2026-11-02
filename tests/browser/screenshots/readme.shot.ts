import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

// Generates the README screenshots in docs/images/. Not a test suite: run it with
// playwright.screenshots.config.ts (see README.md next to this file). The icon is demo-icon.png
// from this directory (drawn by make-demo-icon.php), uploaded through Settings like an editor
// would; the browser fixtures' allow_upload: true makes the field available.

const IMAGES = path.join(__dirname, '..', '..', '..', 'docs', 'images');
const DEMO_ICON = path.join(__dirname, 'demo-icon.png');

function field(page: Page) {
    return page.locator('#Form_EditForm_FaviconSource_Holder');
}

function uploadButton(page: Page) {
    return field(page).locator('.uploadfield__upload-button, button:has-text("Upload new")').first();
}

/** Save Settings: SS5 names the action save_siteconfig, SS6 the generic save (see upload.spec.ts). */
async function save(page: Page): Promise<void> {
    const saved = page.waitForResponse(
        (r) => r.request().method() === 'POST' && /admin\/settings\/EditForm/.test(r.url()) && r.status() === 200,
    );
    await page.locator('[name="action_save_siteconfig"], [name="action_save"]').first().click();
    await saved;
}

/** Remove whatever upload an earlier run (or the browser tests) left, so the demo icon is the one shown. */
async function removeUpload(page: Page): Promise<void> {
    // The React field renders its items and button some time after load; wait for it first.
    await expect(uploadButton(page)).toBeAttached();
    const remove = field(page).locator('.uploadfield-item__remove-btn');
    if (await remove.count()) {
        await remove.first().click();
        await expect(field(page).locator('.uploadfield-item')).toHaveCount(0);
        await save(page);
    }
}

/** Wait until every <img> on the page has loaded. */
async function imagesLoaded(page: Page): Promise<void> {
    await expect
        .poll(() => page.evaluate(() => Array.from(document.images).every((i) => i.complete && i.naturalWidth > 0)), {
            message: 'all images loaded',
        })
        .toBe(true);
}

test('Settings: the site icon upload, serving the demo icon', async ({ page }) => {
    await page.goto('/admin/settings');
    await expect(field(page)).toBeVisible();
    await removeUpload(page);

    // Upload the demo icon and save, which publishes it and generates the set.
    const chooser = page.waitForEvent('filechooser');
    await uploadButton(page).click();
    const uploaded = page.waitForResponse((r) => /\/field\/FaviconSource\/upload$/.test(r.url()));
    await (await chooser).setFiles(DEMO_ICON);
    expect((await uploaded).status()).toBe(200);
    await save(page);

    // Reload, so the field's description shows the status after the save ("Serving ... from ...").
    await page.goto('/admin/settings');
    await expect(field(page).locator('.uploadfield-item')).toHaveCount(1);
    await expect(field(page)).toContainText('Serving all favicon variants from');
    await imagesLoaded(page);
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await page.mouse.move(1270, 790);
    await page.waitForTimeout(500);

    // The top of the window down to just below the upload field and its description.
    const box = (await field(page).boundingBox())!;
    await page.screenshot({
        path: path.join(IMAGES, 'settings-upload.png'),
        clip: { x: 0, y: 0, width: 1280, height: Math.ceil(box.y + box.height + 16) },
    });
});

test('The generated icons, as browsers and devices fetch them', async ({ page, baseURL }) => {
    // Every image below is fetched from the module's own root paths on the host, so this shows the
    // real output of the upload above. Only the frame around it (labels, the circle and rounded
    // masks that Android and iOS apply) is drawn here, so the masks are labelled as such.
    const tile = (src: string, size: number, label: string, note: string, mask = '') => `
        <figure>
          <div class="well"><img src="${src}" width="${size}" height="${size}" style="${mask}"></div>
          <figcaption><code>${label}</code><span>${note}</span></figcaption>
        </figure>`;
    await page.setViewportSize({ width: 1280, height: 600 });
    await page.setContent(`<!doctype html>
      <html><head><base href="${baseURL}/">
      <style>
        body { margin: 0; font: 14px/1.4 -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; color: #1f2937; }
        #icons { display: inline-flex; gap: 28px; align-items: flex-end; padding: 28px 32px;
                 background: #e5e7eb; }
        figure { margin: 0; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .well { display: flex; align-items: center; justify-content: center; }
        .row { display: flex; gap: 10px; align-items: flex-end; }
        figcaption { text-align: center; display: flex; flex-direction: column; }
        figcaption span { color: #4b5563; font-size: 12px; }
        code { font: 12px ui-monospace, Menlo, monospace; }
      </style></head>
      <body><div id="icons">
        <figure>
          <div class="row">
            <img src="favicon.ico" width="16" height="16">
            <img src="favicon.ico" width="32" height="32">
            <img src="favicon.ico" width="48" height="48">
          </div>
          <figcaption><code>/favicon.ico</code><span>16, 32, 48, transparent</span></figcaption>
        </figure>
        ${tile('apple-touch-icon.png', 180, '/apple-touch-icon.png', '180, on background_color')}
        ${tile('apple-touch-icon.png', 180, '/apple-touch-icon.png', 'as iOS shows it (rounded by iOS)', 'border-radius: 40px')}
        ${tile('icon-192.png', 192, '/icon-192.png', 'manifest icon, transparent')}
        ${tile('icon-maskable-512.png', 180, '/icon-maskable-512.png', 'in a circle mask, as Android crops it', 'border-radius: 50%')}
      </div></body></html>`);
    await imagesLoaded(page);
    await page.locator('#icons').screenshot({ path: path.join(IMAGES, 'generated-icons.png') });
});
