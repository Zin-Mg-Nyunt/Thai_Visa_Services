import { expect, signInAsAdmin, test } from './support/fixtures';

/**
 * The Add worker dialog on a job order. The phone lookup is limited to 30 a minute for each admin (a lookup answers with a person's
 * name, birthday and gender); the 31st gets 429 and the dialog says so under the phone field. This was the Phase 4 check that could
 * not be done without a real browser.
 */
test.describe('Add worker', () => {
    test('a lookup in the dialog finds nobody for a new number and offers to create the person', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/job-search/1');

        await page.getByTestId('add-worker-button').click();
        await page.getByTestId('worker-phone-input').fill('+66855550000');
        await page.getByTestId('worker-search-button').click();

        await expect(page.getByTestId('worker-new-person')).toBeVisible();
        await expect(
            page.getByText('Too many searches in a short time'),
        ).toHaveCount(0);
    });

    test('after 30 lookups in a minute the next one says "Too many searches" under the phone field', async ({
        page,
        problems,
    }) => {
        void problems;
        await signInAsAdmin(page);
        await page.goto('/admin/job-search/1');

        // Use up the limit with the same requests the dialog sends.
        const cookies = await page.context().cookies();
        const xsrf = decodeURIComponent(
            cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')!.value,
        );
        let refused = 0;

        for (let attempt = 1; attempt <= 31 && refused === 0; attempt++) {
            const response = await page.request.post(
                '/admin/job-search/1/workers/lookup',
                {
                    headers: {
                        'X-XSRF-TOKEN': xsrf,
                        Accept: 'application/json',
                    },
                    data: {
                        phone: `+6681111${String(attempt).padStart(4, '0')}`,
                    },
                },
            );

            if (response.status() === 429) {
                refused = attempt;
            }
        }

        // The limit is 30 a minute: the 31st (or an earlier one, if the first test used some) is the first refusal.
        expect(refused).toBeGreaterThan(0);
        expect(refused).toBeLessThanOrEqual(31);

        await page.getByTestId('add-worker-button').click();
        await page.getByTestId('worker-phone-input').fill('+66822220000');
        await page.getByTestId('worker-search-button').click();

        await expect(
            page.getByText(
                'Too many searches in a short time. Wait a minute, then try again.',
            ),
        ).toBeVisible();
        // No person is offered, and nothing leaks.
        await expect(page.getByTestId('worker-new-person')).toHaveCount(0);
        await expect(page.getByTestId('worker-existing-person')).toHaveCount(0);
    });
});
