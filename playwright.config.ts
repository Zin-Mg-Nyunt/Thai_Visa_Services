import { defineConfig } from '@playwright/test';
import { browserEnvironment, PORT } from './tests/Browser/support/environment';

/**
 * Browser tests (HLD Phase 5, step 5h.1). They run the real app in the real Google Chrome against a throw-away SQLite database
 * (database/browser.sqlite) with its own storage folder and website-image folder, on port 8001. They never touch the development
 * or the MySQL database. Run them with `npm run test:browser`. See tests/Browser/support/environment.ts.
 */
export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.ts',
    // One worker, in file order: the tests share one database.
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI
        ? [['github'], ['html', { open: 'never' }]]
        : [['list']],
    timeout: 60_000,
    expect: { timeout: 10_000 },
    globalSetup: './tests/Browser/support/global-setup.ts',
    globalTeardown: './tests/Browser/support/global-teardown.ts',
    outputDir: 'test-results',
    use: {
        baseURL: `http://127.0.0.1:${PORT}`,
        // The Google Chrome that is installed (no browser download); GitHub's runners have it too.
        channel: 'chrome',
        headless: true,
        locale: 'en-US',
        // The app marks its elements with data-test.
        testIdAttribute: 'data-test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        // Laravel's own router script looks for index.php in the folder it is started from, so it starts in public/.
        command: `php -d variables_order=EGPCS -S 127.0.0.1:${PORT} -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        cwd: 'public',
        url: `http://127.0.0.1:${PORT}/up`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: browserEnvironment(),
    },
});
