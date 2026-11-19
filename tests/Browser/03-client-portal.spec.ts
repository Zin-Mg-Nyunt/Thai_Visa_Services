import {
    CLIENT_PIN,
    CLIENT_TOKEN,
    expect,
    test,
    useEnglish,
} from './support/fixtures';

/** The client PIN and portal flow: the tracking link, the 4-digit PIN, the portal, and signing out. */
async function enterPin(
    page: import('@playwright/test').Page,
    pin: string,
): Promise<void> {
    const boxes = page.getByTestId('pin-input');

    for (let index = 0; index < pin.length; index++) {
        await boxes.nth(index).fill(pin[index]);
    }
}

test.describe('client PIN and portal', () => {
    test('the tracking page shows the PIN boxes and nothing about the client', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto(`/track/${CLIENT_TOKEN}`);

        await expect(page.getByTestId('pin-input')).toHaveCount(4);
        await expect(page.getByTestId('pin-submit')).toBeDisabled();
        await expect(page.locator('body')).not.toContainText('Alice Browser');
        await expect(page.locator('body')).not.toContainText('8000');
        await expect(page).toHaveTitle(/^Track your case/);
    });

    test('a wrong PIN is refused and tells how many tries are left', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto(`/track/${CLIENT_TOKEN}`);

        await enterPin(page, '0000');
        await page.getByTestId('pin-submit').click();

        await expect(page.getByTestId('pin-message')).toContainText(
            /incorrect|wrong|left/i,
        );
        await expect(page).toHaveURL(new RegExp(`/track/${CLIENT_TOKEN}`));
    });

    test("the right PIN opens the portal with the client's own details, services and documents", async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto(`/track/${CLIENT_TOKEN}`);

        await enterPin(page, CLIENT_PIN);
        await page.getByTestId('pin-submit').click();

        await page.waitForURL(`**/client/${CLIENT_TOKEN}/profile`);
        await expect(page.getByTestId('client-welcome')).toContainText(
            'Alice Browser',
        );
        await expect(page.getByTestId('client-details')).toContainText(
            '+66812345678',
        );

        const service = page.getByTestId('client-service').first();
        await expect(service).toContainText('Work Permit');
        await expect(service).toContainText('8,000');
        await expect(service).toContainText('5,000');

        const documents = page.getByTestId('client-document');
        await expect(documents).toHaveCount(2);
        await expect(
            documents
                .filter({ hasText: 'Open permit card' })
                .getByTestId('download-document-link'),
        ).toBeVisible();
        await expect(
            documents
                .filter({ hasText: 'Locked permit card' })
                .getByTestId('document-state'),
        ).toBeVisible();
        await expect(
            documents
                .filter({ hasText: 'Locked permit card' })
                .getByTestId('download-document-link'),
        ).toHaveCount(0);

        // The portal says nothing about the agency's side: no remarks, no other person, no admin.
        await expect(page.locator('body')).not.toContainText(
            'admin@browser.test',
        );
        await expect(page.locator('body')).not.toContainText(
            'Browser Factory Co',
        );
    });

    test('a signed-in client is kept out of the admin area, and sign out ends the session', async ({
        page,
        problems,
    }) => {
        void problems;
        await useEnglish(page);
        await page.goto(`/track/${CLIENT_TOKEN}`);
        await enterPin(page, CLIENT_PIN);
        await page.getByTestId('pin-submit').click();
        await page.waitForURL(`**/client/${CLIENT_TOKEN}/profile`);

        await page.goto('/admin/dashboard');
        await expect(page).toHaveURL(/\/admin\/login/);

        await page.goto(`/client/${CLIENT_TOKEN}/profile`);
        await page.getByTestId('client-logout').click();
        await page.waitForURL(`**/track/${CLIENT_TOKEN}`);

        await page.goto(`/client/${CLIENT_TOKEN}/profile`);
        await expect(page).toHaveURL(new RegExp(`/track/${CLIENT_TOKEN}`));
    });

    test('the pages of a client carry the privacy headers', async ({
        page,
        problems,
    }) => {
        void problems;
        const response = await page.goto(`/track/${CLIENT_TOKEN}`);

        expect(response!.headers()['x-robots-tag']).toBe('noindex');
        expect(response!.headers()['referrer-policy']).toBe('no-referrer');
        expect(response!.headers()['x-frame-options']).toBe('DENY');
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute(
            'content',
            'noindex',
        );
    });
});
