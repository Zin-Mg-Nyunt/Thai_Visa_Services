import {
    ADMIN_EMAIL,
    ADMIN_PASSWORD,
    expect,
    test,
    useEnglish,
} from './support/fixtures';

test.describe('admin login', () => {
    test('a wrong password is refused with a message and stays on the login page', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto('/admin/login');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill('not-the-password');
        await page.locator('[data-test="login-button"]').click();

        await expect(page).toHaveURL(/\/admin\/login/);
        await expect(
            page.getByText(/do not match|incorrect|failed/i).first(),
        ).toBeVisible();
    });

    test('the right password opens the dashboard and the client list', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto('/admin/login');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.locator('[data-test="login-button"]').click();

        await page.waitForURL('**/admin/dashboard');
        await expect(
            page.getByRole('heading', { name: 'Business dashboard' }),
        ).toBeVisible();

        await page.goto('/admin/clients');
        await expect(
            page
                .getByRole('link', { name: 'Alice Browser' })
                .locator('visible=true')
                .first(),
        ).toBeVisible();
    });

    test('an admin page needs the login: a visitor is sent to the login page', async ({
        page,
        problems,
    }) => {
        void problems;
        await page.goto('/admin/settings');

        await expect(page).toHaveURL(/\/admin\/login/);
    });
});
