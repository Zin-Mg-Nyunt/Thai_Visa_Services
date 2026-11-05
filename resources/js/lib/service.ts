import { i18n } from '@/i18n';

/**
 * Picks the text in the selected language and falls back to the other language when it is missing or empty.
 * An empty string is returned when neither language has a value, never a translation key.
 */
export function localised(
    english: string | null | undefined,
    burmese: string | null | undefined,
): string {
    const [first, second] =
        i18n.global.locale.value === 'my'
            ? [burmese, english]
            : [english, burmese];

    return first?.trim() || second?.trim() || '';
}
