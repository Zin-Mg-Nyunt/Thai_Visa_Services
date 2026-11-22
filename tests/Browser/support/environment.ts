import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

export const PORT = 8001;
export const ROOT = resolve(import.meta.dirname, '../../..');
export const DATABASE = resolve(ROOT, 'database/browser.sqlite');
export const STORAGE = resolve(ROOT, 'storage/browser');
export const ASSETS = resolve(ROOT, 'public/browser-assets');
/** The saved admin session, made once before the tests (the login is limited to 5 attempts a minute). */
export const ADMIN_STATE = resolve(STORAGE, 'admin-state.json');
/** Where a leftover public/hot is kept while the tests run. */
export const STALE_HOT = resolve(ROOT, 'public/hot.moved-by-browser-tests');

/**
 * The environment of the browser tests' server and of every artisan command they run. Variables set here beat the development .env
 * (the app never overrides a variable that is already set), so the app uses a throw-away SQLite file, its own storage folder, its
 * own folder for the website images, no Google sign-in, and no mail.
 */
export function browserEnvironment(): Record<string, string> {
    for (const folder of [
        'framework/cache/data',
        'framework/sessions',
        'framework/views',
        'logs',
        'app/private',
    ]) {
        mkdirSync(resolve(STORAGE, folder), { recursive: true });
    }
    mkdirSync(ASSETS, { recursive: true });

    return {
        APP_ENV: 'browser',
        APP_DEBUG: 'false',
        APP_NAME: 'Browser Test',
        APP_KEY: 'base64:YnJvd3Nlci10ZXN0cy1vbmx5LWtleS0wMTIzNDU2N3g=',
        APP_URL: `http://127.0.0.1:${PORT}`,
        APP_LOCALE: 'en',
        APP_FALLBACK_LOCALE: 'en',
        BCRYPT_ROUNDS: '4',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: DATABASE,
        DB_HOST: '',
        DB_USERNAME: '',
        DB_PASSWORD: '',
        SESSION_DRIVER: 'file',
        SESSION_DOMAIN: '',
        CACHE_STORE: 'file',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'log',
        LOG_CHANNEL: 'single',
        LARAVEL_STORAGE_PATH: STORAGE,
        STORAGE_DRIVER: 'local',
        PUBLIC_ASSETS_ROOT: ASSETS,
        PUBLIC_ASSETS_URL: `http://127.0.0.1:${PORT}/browser-assets`,
        DO_SPACES_PRIVATE_KEY: '',
        DO_SPACES_PRIVATE_SECRET: '',
        DO_SPACES_PUBLIC_KEY: '',
        DO_SPACES_PUBLIC_SECRET: '',
        GOOGLE_CLIENT_ID: '',
        GOOGLE_CLIENT_SECRET: '',
        INERTIA_SSR_ENABLED: 'false',
        CSP_ENFORCE: 'false',
    };
}
