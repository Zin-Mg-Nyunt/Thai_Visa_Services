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

/**
 * Formats an ISO date-time from the server in the selected language, in the viewer's time zone.
 */
export function formatDateTime(value: string): string {
    const locale = i18n.global.locale.value === 'my' ? 'my-MM' : 'en-GB';

    return new Date(value).toLocaleString(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Formats an amount in Thai baht in the selected language, for example ฿ 8,000.
 * The amount is a decimal string from the server ("8000.00"). Intl formats the string
 * exactly, so it is never turned into a float on the way.
 */
export function formatMoney(value: string): string {
    const locale = i18n.global.locale.value === 'my' ? 'my-MM' : 'en-GB';
    const formatter = new Intl.NumberFormat(locale, {
        maximumFractionDigits: 2,
    });

    return `฿ ${formatter.format(value as Intl.StringNumericLiteral)}`;
}

/**
 * A file size in the selected language, for example 1.5 MB. Bytes are whole numbers from the server.
 */
export function formatFileSize(bytes: number): string {
    const locale = i18n.global.locale.value === 'my' ? 'my-MM' : 'en-GB';
    const formatter = new Intl.NumberFormat(locale, {
        maximumFractionDigits: 1,
    });

    if (bytes >= 1024 * 1024) {
        return `${formatter.format(bytes / (1024 * 1024))} MB`;
    }

    return `${formatter.format(Math.max(1, Math.round(bytes / 1024)))} KB`;
}
