import { readFileSync } from 'node:fs';
import { expect, test as base } from '@playwright/test';
import type { Page } from '@playwright/test';
import { ADMIN_STATE } from './environment';

export const ADMIN_EMAIL = 'admin@browser.test';
export const ADMIN_PASSWORD = 'browser-test-password';
export const CLIENT_TOKEN = `browser-test-token-${'a'.repeat(29)}`;
export const CLIENT_PIN = '5678';

type Problems = {
    /** Messages the browser printed about the Content Security Policy. */
    csp: string[];
    /** Scripts that threw. */
    scriptErrors: string[];
    /** Images, fonts, stylesheets and scripts of the app that failed to load (4xx or 5xx). */
    brokenAssets: string[];
};

/**
 * `page` with a watch on three kinds of problem that look fine in a PHP test: a Content Security Policy violation (the policy is
 * sent as Report-Only, so the browser only lists them), a script error, and a file of the page that does not load (a broken image,
 * font or script). After every test they must all be empty.
 */
export const test = base.extend<{ problems: Problems }>({
    problems: async ({ page }, use) => {
        const problems: Problems = {
            csp: [],
            scriptErrors: [],
            brokenAssets: [],
        };

        page.on('console', (message) => {
            if (/content security policy/i.test(message.text())) {
                problems.csp.push(message.text());
            }
        });
        page.on('pageerror', (error) =>
            problems.scriptErrors.push(error.message),
        );
        page.on('response', (response) => {
            const type = response.request().resourceType();

            if (
                ['image', 'font', 'stylesheet', 'script'].includes(type) &&
                response.status() >= 400
            ) {
                problems.brokenAssets.push(
                    `${response.status()} ${response.url()}`,
                );
            }
        });

        await use(problems);

        expect
            .soft(problems.csp, 'Content Security Policy violations')
            .toEqual([]);
        expect.soft(problems.scriptErrors, 'script errors').toEqual([]);
        expect
            .soft(problems.brokenAssets, 'files that did not load')
            .toEqual([]);
    },
});

export { expect };

/** Use the English interface, whatever the browser's saved language is. */
export async function useEnglish(page: Page): Promise<void> {
    await page
        .context()
        .addCookies([
            { name: 'locale', value: 'en', url: 'http://127.0.0.1:8001' },
        ]);
    await page.addInitScript(() => localStorage.setItem('locale', 'en'));
}

/**
 * Be signed in as the seeded admin, using the session saved before the tests (tests/Browser/support/global-setup.ts). The login form is
 * limited to 5 attempts a minute, so only the login tests use it.
 */
export async function signInAsAdmin(page: Page): Promise<void> {
    await useEnglish(page);

    const state = JSON.parse(readFileSync(ADMIN_STATE, 'utf8')) as {
        cookies: Parameters<ReturnType<Page['context']>['addCookies']>[0];
    };
    await page.context().addCookies(state.cookies);
}

/** A PNG made in the browser: solid colour with noise, so it is a real picture of the given size. */
export async function makePng(
    page: Page,
    width: number,
    height: number,
): Promise<Buffer> {
    const dataUrl = await page.evaluate(
        ({ width, height }) => {
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d')!;
            const gradient = context.createLinearGradient(0, 0, width, height);
            gradient.addColorStop(0, '#023842');
            gradient.addColorStop(1, '#00d47f');
            context.fillStyle = gradient;
            context.fillRect(0, 0, width, height);

            return canvas.toDataURL('image/png');
        },
        { width, height },
    );

    return Buffer.from(dataUrl.split(',')[1], 'base64');
}
