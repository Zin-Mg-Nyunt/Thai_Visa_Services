import { i18n } from '@/i18n';

/**
 * Formats a Y-m-d date from the server in the selected language.
 * The parts are read as a local date so the day never shifts with the time zone.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const [year, month, day] = value.split('-').map(Number);
    const locale = i18n.global.locale.value === 'my' ? 'my-MM' : 'en-GB';

    return new Date(year, month - 1, day).toLocaleDateString(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
