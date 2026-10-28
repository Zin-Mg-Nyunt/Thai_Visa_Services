import { setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';

type AuthSection =
    | 'login'
    | 'register'
    | 'forgot'
    | 'reset'
    | 'confirm'
    | 'verify';

/**
 * Sets the auth layout title and description, and keeps them in the selected
 * language when the language is switched.
 */
export function useAuthLayoutProps(section: AuthSection): void {
    const { t } = useI18n();

    watchEffect(() => {
        setLayoutProps({
            title: t(`auth.${section}.title`),
            description: t(`auth.${section}.description`),
        });
    });
}
