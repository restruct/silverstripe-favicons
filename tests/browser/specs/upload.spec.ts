import path from 'node:path';
import { createHash } from 'node:crypto';
import type { APIRequestContext, Page } from '@playwright/test';
import { test, expect } from './support';

// allow_upload: true (fixtures/_config/favicons.yml): upload a PNG in Settings, save (which
// publishes it), and /apple-touch-icon.png switches from the YAML source to the upload. The spec
// removes the upload again at the end, so a reused host starts from the YAML source next time.

// A valid source (square, 512 px) that differs from the configured tests/fixtures/icon.png
const UPLOAD = path.resolve(__dirname, '../../fixtures/icon2.png');
// 64 px: refused by the field's validator
const TOO_SMALL = path.resolve(__dirname, '../../fixtures/small.png');

async function touchIconHash(request: APIRequestContext): Promise<string> {
    const response = await request.get('/apple-touch-icon.png');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('image/png');
    return createHash('sha1').update(await response.body()).digest('hex');
}

function field(page: Page) {
    return page.locator('#Form_EditForm_FaviconSource_Holder');
}

async function save(page: Page): Promise<void> {
    const saved = page.waitForResponse(
        (r) => r.request().method() === 'POST' && /admin\/settings\/EditForm/.test(r.url()) && r.status() === 200,
    );
    // SS5 names the action save_siteconfig; SS6 (SingleRecordAdmin) uses the generic save. Both
    // publish what SiteConfig owns: SiteConfigLeftAndMain::save_siteconfig() on 5, versioned's
    // RecursivePublishableHandler (onAfterSave) on 6.
    await page.locator('[name="action_save_siteconfig"], [name="action_save"]').first().click();
    await saved;
}

function uploadButton(page: Page) {
    return field(page).locator('.uploadfield__upload-button, button:has-text("Upload new")').first();
}

async function removeUpload(page: Page): Promise<void> {
    // The field is a React component that renders its items and its upload button together, some
    // time after the page has loaded. Counting items before that sees none and skips the removal
    // (this was the SS5 CI failure: the upload stayed, and the restored icon never came back).
    // Attached, not visible: with the one allowed file present the button is rendered but hidden.
    await expect(uploadButton(page)).toBeAttached();
    const remove = field(page).locator('.uploadfield-item__remove-btn');
    if (await remove.count()) {
        await remove.first().click();
        // The field's React state must have dropped the item before saving, or the save posts the
        // old file ID again and nothing changes (seen on SS5 in CI).
        await expect(field(page).locator('.uploadfield-item')).toHaveCount(0);
        await save(page);
    }
}

async function chooseFile(page: Page, file: string) {
    const chooser = page.waitForEvent('filechooser');
    await uploadButton(page).click();
    // The item appears before its upload request finishes, with file ID 0 until the response
    // arrives; saving earlier posts FaviconSource[Files][]=0 and stores nothing.
    const uploaded = page.waitForResponse((r) => /\/field\/FaviconSource\/upload$/.test(r.url()));
    await (await chooser).setFiles(file);
    return uploaded;
}

test('an uploaded icon replaces the configured one, and removing it restores it', async ({ page, playwright, baseURL }) => {
    // Icons are fetched by anonymous visitors: check them without the admin session
    const request = await playwright.request.newContext({ baseURL });
    await page.goto('/admin/settings');
    await expect(field(page)).toBeVisible();
    // Start from the YAML source, whatever an earlier aborted run left behind
    await removeUpload(page);
    const configured = await touchIconHash(request);

    const uploaded = await chooseFile(page, UPLOAD);
    expect(uploaded.status()).toBe(200);
    await expect(field(page).locator('.uploadfield-item')).toHaveCount(1);
    await expect(field(page).locator('.uploadfield-item__title')).toHaveText('icon2');
    await save(page);

    // Served to an anonymous visitor: the save published the upload
    await expect.poll(() => touchIconHash(request)).not.toBe(configured);

    await page.reload();
    await removeUpload(page);
    await expect.poll(() => touchIconHash(request)).toBe(configured);
    await request.dispose();
});

test.describe('upload validation', () => {
    // The refused upload answers 400, which the browser logs as a console error
    test.use({ allowedConsoleErrors: [/status of 400 .*\/field\/FaviconSource\/upload\)$/] });

    test('a PNG smaller than 512 px is refused at upload', async ({ page }) => {
        await page.goto('/admin/settings');
        await expect(field(page)).toBeVisible();
        await removeUpload(page);

        const uploaded = await chooseFile(page, TOO_SMALL);
        expect(uploaded.status()).not.toBe(200);
        await expect(field(page).locator('.uploadfield-item__error-message')).toContainText('at least 512x512 px');
        // Nothing was stored: leaving the page without saving keeps the relation empty
        await page.reload();
        await expect(field(page).locator('.uploadfield-item')).toHaveCount(0);
    });
});
