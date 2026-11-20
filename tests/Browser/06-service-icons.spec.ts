import type { Page } from '@playwright/test';
import { expect, signInAsAdmin, test, useEnglish } from './support/fixtures';

/**
 * Service icons: each main service's landing page card shows the icon saved for it, the admin picks an icon in the service form
 * (from the allowed list only), and a changed icon shows on the landing page. The seeded data (BrowserTestSeeder) has Work Permit
 * with "file-check", Passport with "book-user" and a Passport sub-service, MOU, that has an icon but is never a card. Passport is
 * service 2. This file runs last, because it changes the catalogue the landing page tests read.
 */
function card(page: Page, title: string) {
    return page.getByTestId('service-card').filter({ hasText: title });
}

test.describe('Service icons', () => {
    test('the landing page shows each main service with its own icon, and no sub-service card', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto('/');

        await expect(
            card(page, 'Work Permit').getByTestId('service-icon'),
        ).toHaveAttribute('data-icon', 'file-check');
        await expect(
            card(page, 'Passport').getByTestId('service-icon'),
        ).toHaveAttribute('data-icon', 'book-user');
        await expect(card(page, 'MOU')).toHaveCount(0);
        await expect(page.getByTestId('service-card')).toHaveCount(2);
    });

    test('the edit form shows the saved icon, selected and previewed', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/services/2/edit');

        await expect(page.getByTestId('service-icon-preview')).toHaveAttribute(
            'data-icon',
            'book-user',
        );
        await expect(
            page.getByTestId('service-icon-option-book-user'),
        ).toHaveAttribute('aria-checked', 'true');
        await expect(
            page.locator(
                '[data-test^="service-icon-option-"][aria-checked="true"]',
            ),
        ).toHaveCount(1);
    });

    test('a new service starts with the default icon; the admin picks another and it shows on the landing page', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/services/create');

        const preview = page.getByTestId('service-icon-preview');
        await expect(preview).toHaveAttribute(
            'data-icon',
            'briefcase-business',
        );
        await expect(
            page.getByTestId('service-icon-option-briefcase-business'),
        ).toHaveAttribute('aria-checked', 'true');

        await page.getByTestId('service-icon-option-plane').click();
        await expect(preview).toHaveAttribute('data-icon', 'plane');
        await expect(
            page.getByTestId('service-icon-option-plane'),
        ).toHaveAttribute('aria-checked', 'true');
        await expect(
            page.getByTestId('service-icon-option-briefcase-business'),
        ).toHaveAttribute('aria-checked', 'false');

        await page.locator('#title').fill('Visa Extension');
        await page
            .locator('#public_description')
            .fill('Visa extension support.');
        await page.getByLabel('Show on website').click();
        await page.getByTestId('save-service-button').click();
        await page.waitForURL(/\/admin\/services\/\d+$/);

        await page.goto('/');
        await expect(
            card(page, 'Visa Extension').getByTestId('service-icon'),
        ).toHaveAttribute('data-icon', 'plane');
    });

    test('a sub-service form has no icon picker', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/services/create?parent=2');

        await expect(page.locator('#title')).toBeVisible();
        await expect(page.getByTestId('service-icon-section')).toHaveCount(0);
    });

    test('an icon changed in the edit form shows on the landing page', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/services/2/edit');

        await page.getByTestId('service-icon-option-globe').click();
        await page.getByTestId('save-service-button').click();
        await page.waitForURL('**/admin/services/2');

        await page.goto('/');
        await expect(
            card(page, 'Passport').getByTestId('service-icon'),
        ).toHaveAttribute('data-icon', 'globe');
        await expect(
            card(page, 'Work Permit').getByTestId('service-icon'),
        ).toHaveAttribute('data-icon', 'file-check');
    });
});
