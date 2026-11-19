import type { Page } from '@playwright/test';
import { expect, makePng, signInAsAdmin, test } from './support/fixtures';

/**
 * The Website appearance screen with real images. The browser makes a large picture smaller before it is uploaded (the logo to at
 * most 512 px, the hero image to at most 1600 px), a type that is not allowed is refused on the spot, and the saved images show on the
 * public landing page. The server does not resize: the size that is saved is the size the browser sent.
 */
async function naturalSize(
    page: Page,
    selector: string,
): Promise<{ width: number; height: number }> {
    return page
        .locator(selector)
        .first()
        .evaluate(async (image: HTMLImageElement) => {
            if (!image.complete) {
                await new Promise((resolve) =>
                    image.addEventListener('load', resolve, { once: true }),
                );
            }

            return { width: image.naturalWidth, height: image.naturalHeight };
        });
}

test.describe('Website appearance', () => {
    test('the page opens from the Settings page for an admin', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/settings');

        await page.getByTestId('appearance-link').click();

        await page.waitForURL('**/admin/settings/appearance');
        await expect(
            page.getByRole('heading', { name: 'Website appearance' }),
        ).toBeVisible();
        await expect(
            page.getByTestId('image-logo').getByTestId('image-preview'),
        ).toBeVisible();
    });

    test('a type that is not allowed is refused in the browser, with nothing saved', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/settings/appearance');

        await page
            .getByTestId('image-logo')
            .getByTestId('image-input')
            .setInputFiles({
                name: 'logo.svg',
                mimeType: 'image/svg+xml',
                buffer: Buffer.from(
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
            });

        await expect(
            page.getByTestId('image-logo').getByTestId('image-error'),
        ).toContainText('Choose a PNG, JPG or WebP image.');
        await expect(
            page.getByTestId('image-logo').getByTestId('image-size'),
        ).toHaveCount(0);
    });

    test('a large logo is made smaller on the device, saved, and shown on the landing page', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/settings/appearance');

        const big = await makePng(page, 3000, 2000);
        await page
            .getByTestId('image-logo')
            .getByTestId('image-input')
            .setInputFiles({
                name: 'big-logo.png',
                mimeType: 'image/png',
                buffer: big,
            });

        // The picture the browser will send: its size is shown, and it is at most 512 px on the long side.
        await expect(
            page.getByTestId('image-logo').getByTestId('image-size'),
        ).toBeVisible();
        const preview = await naturalSize(
            page,
            '[data-test="image-logo"] [data-test="image-preview"]',
        );
        expect(Math.max(preview.width, preview.height)).toBeLessThanOrEqual(
            512,
        );
        expect(preview.width / preview.height).toBeCloseTo(1.5, 1);

        await page.getByTestId('appearance-save').click();
        await expect(page.getByText('Appearance saved.')).toBeVisible();

        // Saved, and the old file is replaced: the address is a new one.
        const saved = await naturalSize(
            page,
            '[data-test="image-logo"] [data-test="image-preview"]',
        );
        expect(Math.max(saved.width, saved.height)).toBeLessThanOrEqual(512);

        await page.goto('/');
        const header = await naturalSize(page, '[data-test="site-logo"]');
        expect(Math.max(header.width, header.height)).toBeLessThanOrEqual(512);
        await expect(page.getByTestId('site-logo')).not.toHaveAttribute(
            'src',
            /browser-seed-logo/,
        );
    });

    test('a large hero image is saved as a JPG of at most 1600 px and shown on the landing page, and it can be removed', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/settings/appearance');

        const big = await makePng(page, 3200, 2400);
        await page
            .getByTestId('image-hero')
            .getByTestId('image-input')
            .setInputFiles({
                name: 'big-hero.png',
                mimeType: 'image/png',
                buffer: big,
            });
        await expect(
            page.getByTestId('image-hero').getByTestId('image-size'),
        ).toBeVisible();
        await page.getByTestId('appearance-save').click();
        await expect(page.getByText('Appearance saved.')).toBeVisible();

        await page.goto('/');
        await expect(page.getByTestId('hero-image')).toBeVisible();
        const src =
            (await page.getByTestId('hero-image').getAttribute('src')) ?? '';
        expect(src).toMatch(/browser-assets\/site\/[0-9a-f-]{36}\.jpg/);
        const hero = await naturalSize(page, '[data-test="hero-image"]');
        expect(Math.max(hero.width, hero.height)).toBeLessThanOrEqual(1600);
        expect(Math.max(hero.width, hero.height)).toBeGreaterThan(512);

        // Remove it: the built-in illustration comes back.
        await page.goto('/admin/settings/appearance');
        await page
            .getByTestId('image-hero')
            .getByTestId('image-remove')
            .click();
        await page.getByTestId('appearance-save').click();
        await expect(page.getByText('Appearance saved.')).toBeVisible();

        await page.goto('/');
        await expect(page.getByTestId('hero-image')).toHaveCount(0);
        await expect(page.getByTestId('hero-illustration')).toBeVisible();
    });

    test('the hero text is saved in both languages and shows on the landing page', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/settings/appearance');

        await page.locator('#hero_heading').fill('Your visa, handled');
        await page.locator('#hero_heading_my').fill('သင့်ဗီဇာ');
        await page.getByTestId('appearance-save').click();
        await expect(page.getByText('Appearance saved.')).toBeVisible();

        await page.goto('/');
        await expect(page.getByTestId('hero-title')).toHaveText(
            'Your visa, handled',
        );
    });
});
