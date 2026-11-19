import { execFileSync } from 'node:child_process';
import { chromium } from '@playwright/test';
import { existsSync, readFileSync, renameSync, rmSync } from 'node:fs';
import {
    ADMIN_STATE,
    ASSETS,
    DATABASE,
    PORT,
    ROOT,
    STALE_HOT,
    STORAGE,
    browserEnvironment,
} from './environment';

/**
 * Before the browser tests: a fresh throw-away database. Everything here refuses to run unless it is about the browser tests' own
 * SQLite file, so it can never touch the development or the MySQL database.
 */
export default async function globalSetup(): Promise<void> {
    const environment = browserEnvironment();

    if (
        !DATABASE.endsWith('database/browser.sqlite') ||
        environment.DB_CONNECTION !== 'sqlite' ||
        environment.APP_ENV !== 'browser'
    ) {
        throw new Error(
            'The browser tests may only use database/browser.sqlite.',
        );
    }

    // The development server of Vite leaves public/hot behind while it runs; the app then loads its scripts from it. If the file is
    // left over and the server is not running, the pages would have no scripts, so the file is moved aside for the tests and put back
    // afterwards (see global-teardown.ts). A running dev server is left alone and used.
    const hot = `${ROOT}/public/hot`;

    if (existsSync(hot)) {
        const address = readFileSync(hot, 'utf8').trim();

        try {
            await fetch(address, { signal: AbortSignal.timeout(3000) });
        } catch {
            renameSync(hot, STALE_HOT);
        }
    }

    if (
        !existsSync(`${ROOT}/public/hot`) &&
        !existsSync(`${ROOT}/public/build/manifest.json`)
    ) {
        throw new Error(
            'There are no built scripts. Run `npm run build` first (or `npm run test:browser`, which does).',
        );
    }

    for (const file of [
        DATABASE,
        `${DATABASE}-journal`,
        `${DATABASE}-wal`,
        `${DATABASE}-shm`,
    ]) {
        rmSync(file, { force: true });
    }
    rmSync(`${STORAGE}/framework/cache/data`, { recursive: true, force: true });
    rmSync(`${STORAGE}/framework/sessions`, { recursive: true, force: true });
    rmSync(ASSETS, { recursive: true, force: true });
    browserEnvironment();
    execFileSync('touch', [DATABASE]);

    const run = (...artisan: string[]) =>
        execFileSync('php', ['artisan', ...artisan], {
            cwd: ROOT,
            env: { ...process.env, ...environment },
            stdio: 'pipe',
        });

    run('migrate', '--force');
    run('db:seed', '--class=BrowserTestSeeder', '--force');

    // Sign in once and keep the session. The admin login is limited to 5 attempts a minute, so every test that needs an admin starts
    // from this saved session instead of typing the password again; only the login tests use the form.
    const browser = await chromium.launch({ channel: 'chrome' });

    try {
        const context = await browser.newContext({
            baseURL: `http://127.0.0.1:${PORT}`,
            locale: 'en-US',
        });
        const page = await context.newPage();

        await page.goto('/admin/login');
        await page.locator('#email').fill('admin@browser.test');
        await page.locator('#password').fill('browser-test-password');
        await page.locator('[data-test="login-button"]').click();
        await page.waitForURL('**/admin/dashboard');
        await context.storageState({ path: ADMIN_STATE });
    } finally {
        await browser.close();
    }
}
