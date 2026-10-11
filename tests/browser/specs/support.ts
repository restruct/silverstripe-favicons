import { test as base, expect } from '@playwright/test';

// Shared fixtures for the favicons specs. They run as the logged-in admin (the saved session from
// login.setup.ts), because what they check is the CMS.

/**
 * test, extended with an automatic console guard: every spec fails if the page logs a console
 * error or throws an uncaught exception. "Failed to load resource" (any 4xx/5xx request) arrives as
 * a console error too, so a 404 for an icon the page links is caught here as well.
 */
export const test = base.extend<{ consoleGuard: void; allowedConsoleErrors: RegExp[] }>({
    // A spec that provokes an error on purpose (an upload the server refuses) names exactly that
    // error with test.use({ allowedConsoleErrors: [...] }); everything else still fails the spec.
    allowedConsoleErrors: [[], { option: true }],
    consoleGuard: [
        async ({ page, allowedConsoleErrors }, use, testInfo) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    const line = `console.error: ${msg.text()} (${msg.location().url})`;
                    if (!allowedConsoleErrors.some((re) => re.test(line))) {
                        errors.push(line);
                    }
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };
