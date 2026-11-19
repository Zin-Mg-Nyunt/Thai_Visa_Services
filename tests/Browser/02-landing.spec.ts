import { expect, test, useEnglish } from './support/fixtures';

const SIZES = [
    { name: 'phone', width: 375, height: 812 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'desktop', width: 1280, height: 800 },
] as const;

/**
 * The public landing page at 375, 768 and 1280 px. The seeded website is published, with an agency name, texts, contact details,
 * a messaging link, a social link, two services and both statistics approved.
 */
for (const size of SIZES) {
    test.describe(`landing page at ${size.width} px`, () => {
        test.use({ viewport: { width: size.width, height: size.height } });

        test('it shows the content, scrolls only down, and every file of the page loads', async ({
            page,
            problems,
        }, info) => {
            void problems;
            await useEnglish(page);
            await page.goto('/');

            await expect(page.getByTestId('hero-title')).toHaveText(
                'Visas made simple',
            );
            await expect(
                page
                    .getByTestId('site-name')
                    .or(page.getByTestId('site-logo'))
                    .first(),
            ).toBeVisible();
            await expect(page.getByTestId('service-card')).toHaveCount(2);
            await expect(page.getByTestId('stats-section')).toBeVisible();
            await expect(page.getByTestId('contact-section')).toBeVisible();

            // The page never scrolls sideways.
            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - window.innerWidth,
            );
            expect(overflow).toBeLessThanOrEqual(0);

            await page.screenshot({
                path: info.outputPath(`landing-${size.width}.png`),
                fullPage: true,
            });
            await info.attach(`landing-${size.width}`, {
                path: info.outputPath(`landing-${size.width}.png`),
                contentType: 'image/png',
            });
        });

        test('Admin Login is always in the header', async ({
            page,
            problems,
        }) => {
            void problems;
            await useEnglish(page);
            await page.goto('/');

            await expect(
                page.getByRole('link', { name: 'Admin Login' }),
            ).toBeVisible();
        });

        test('the section links: a row under the header on small screens, inside it from 768 px', async ({
            page,
            problems,
        }) => {
            void problems;
            await useEnglish(page);
            await page.goto('/');

            const wide = size.width >= 768;
            await expect(page.getByTestId('site-nav')).toBeVisible({
                visible: wide,
            });
            await expect(page.getByTestId('site-nav-mobile')).toBeVisible({
                visible: !wide,
            });

            const nav = wide
                ? page.getByTestId('site-nav')
                : page.getByTestId('site-nav-mobile');
            await expect(nav.getByRole('link')).toHaveText([
                'About',
                'Services',
                'Contact',
            ]);

            await nav.getByRole('link', { name: 'Contact' }).click();
            await expect(page.getByTestId('contact-section')).toBeInViewport();
        });

        test('the contact details are on one line from 768 px and stacked on a phone', async ({
            page,
            problems,
        }) => {
            void problems;
            await useEnglish(page);
            await page.goto('/');

            const items = page.getByTestId('contact-details').locator('li');
            await expect(items).toHaveCount(3);
            const tops = await items.evaluateAll((nodes) =>
                nodes.map((node) =>
                    Math.round(node.getBoundingClientRect().top),
                ),
            );

            if (size.width >= 768) {
                expect(new Set(tops).size).toBe(1);
            } else {
                expect(new Set(tops).size).toBe(3);
            }
        });
    });
}

test.describe('landing page content', () => {
    test("the admin's texts, the real statistics and the links are on the page", async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto('/');

        await expect(
            page.getByText('We help with visas and work permits in Thailand.'),
        ).toBeVisible();
        await expect(page.getByTestId('about-overview')).toHaveText(
            'A small agency that looks after your paperwork.',
        );
        await expect(page.getByTestId('stats-section')).toContainText(
            'Clients served',
        );
        await expect(page.getByTestId('stats-section')).toContainText(
            'Services provided',
        );
        await expect(page.getByTestId('call-now')).toHaveAttribute(
            'href',
            'tel:+6621234567',
        );
        await expect(page.getByTestId('message-us')).toHaveAttribute(
            'href',
            'https://line.me/ti/p/browser',
        );
        await expect(
            page
                .getByTestId('social-links')
                .getByRole('link', { name: 'Facebook' }),
        ).toHaveAttribute('target', '_blank');
    });

    test('the tab title is the agency name once, and the page has the description and link-preview tags', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto('/');

        // The agency name comes first and appears once. The app name after it is the one the scripts were built with.
        const title = await page.title();
        expect(title).toMatch(/^Browser Agency( - [^-]+)?$/);
        expect(title.split('Browser Agency').length - 1).toBe(1);
        await expect(page.locator('meta[name="description"]')).toHaveAttribute(
            'content',
            'We help with visas and work permits in Thailand.',
        );
        await expect(page.locator('meta[property="og:title"]')).toHaveAttribute(
            'content',
            /^Browser Agency/,
        );
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute(
            'href',
            /browser-assets\/site\/browser-seed-favicon\.png/,
        );
    });

    test('the uploaded logo is loaded from the public images folder', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto('/');

        const logo = page.getByTestId('site-logo');
        await expect(logo).toHaveAttribute(
            'src',
            /browser-assets\/site\/browser-seed-logo\.png/,
        );
        expect(
            await logo.evaluate(
                (image: HTMLImageElement) =>
                    image.complete && image.naturalWidth > 0,
            ),
        ).toBe(true);
    });

    test('the page can switch to Burmese and back', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto('/');

        await page.getByRole('button', { name: /Language/ }).click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'my');
        await expect(page.getByTestId('stats-section')).not.toContainText(
            'Clients served',
        );

        await page.getByRole('button', { name: /ဘာသာ|Language/ }).click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    });
});
