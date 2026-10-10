import { test, expect } from './support';

// The CMS backend links the site's icon (admin 2/3 emit none of their own), and the linked URLs
// answer with the generated icons.
test('the CMS links the configured favicon and it is served', async ({ page, request }) => {
    await page.goto('/admin/pages');
    await expect(page.locator('.cms-menu')).toBeVisible();

    const ico = page.locator('head link[rel="icon"][href*="/favicon.ico?v="]');
    await expect(ico).toHaveCount(1);
    await expect(page.locator('head link[rel="apple-touch-icon"][href*="/apple-touch-icon.png?v="]')).toHaveCount(1);
    await expect(page.locator('head link[rel="manifest"]')).toHaveCount(1);

    const href = (await ico.getAttribute('href'))!;
    const response = await request.get(href);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('image/x-icon');
    // The tag's own URL carries the current version, so it is cached as immutable
    expect(response.headers()['cache-control']).toContain('immutable');
});

test('the login page links the favicon too', async ({ browser }) => {
    // A visitor, not the saved admin session
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('/Security/login');
    await expect(page.locator('head link[rel="icon"][href*="/favicon.ico?v="]')).toHaveCount(1);
    await context.close();
});
