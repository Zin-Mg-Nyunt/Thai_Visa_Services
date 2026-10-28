import { createI18n } from 'vue-i18n';
import en from '@/locales/en';
import my from '@/locales/my';

export type Locale = 'my' | 'en';

export const supportedLocales: Locale[] = ['my', 'en'];

export const defaultLocale: Locale = 'my';

/*
 * A missing value falls back to the other language. If neither language has
 * it, an empty string is shown, never the raw translation key.
 */
export const i18n = createI18n({
    legacy: false,
    locale: defaultLocale,
    fallbackLocale: { my: ['en'], en: ['my'], default: ['my', 'en'] },
    messages: { en, my },
    missingWarn: false,
    fallbackWarn: false,
    missing: () => '',
});
