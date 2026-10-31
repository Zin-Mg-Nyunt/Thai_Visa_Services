import { i18n } from '@/i18n';

/**
 * The browser tab title is always English, whatever language the UI is in.
 */
export function pageTitle(key: string): string {
    return i18n.global.t(key, {}, { locale: 'en' });
}
