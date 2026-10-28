import { http } from '@inertiajs/vue3';
import { computed } from 'vue';
import { defaultLocale, i18n, supportedLocales } from '@/i18n';
import type { Locale } from '@/i18n';

export type { Locale };

const storageKey = 'locale';

const isLocale = (value: unknown): value is Locale =>
    supportedLocales.includes(value as Locale);

const getStoredLocale = (): Locale | null => {
    try {
        const stored = localStorage.getItem(storageKey);

        return isLocale(stored) ? stored : null;
    } catch {
        return null;
    }
};

const applyLocale = (value: Locale): void => {
    i18n.global.locale.value = value;
    document.documentElement.lang = value === 'my' ? 'my' : 'en';
};

/**
 * Applies the saved language before the first screen is drawn and sends it to
 * the server with every request, so validation messages use the same language.
 */
export function initializeLocale(): void {
    if (typeof window === 'undefined') {
        return;
    }

    applyLocale(getStoredLocale() ?? defaultLocale);

    http.onRequest((config) => {
        config.headers = {
            ...config.headers,
            'X-Locale': i18n.global.locale.value,
        };

        return config;
    });
}

/**
 * Picks a database value in the selected language, falling back to the other
 * language, or an empty string when neither is set.
 */
export function localized(
    en: string | null | undefined,
    my: string | null | undefined,
): string {
    const values = i18n.global.locale.value === 'my' ? [my, en] : [en, my];

    return values.find((value) => value && value.trim() !== '') ?? '';
}

export function useLocale() {
    const locale = computed<Locale>(() => i18n.global.locale.value);

    function setLocale(value: Locale): void {
        applyLocale(value);

        try {
            localStorage.setItem(storageKey, value);
        } catch {
            // Storage can be unavailable (private mode); the choice then lasts for this visit only.
        }
    }

    return {
        locale,
        supportedLocales,
        setLocale,
        localized,
    };
}
