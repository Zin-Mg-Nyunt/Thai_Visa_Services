import {
    BriefcaseBusiness,
    ConciergeBell,
    LayoutDashboard,
    Settings,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { dashboard } from '@/routes';
import { settings } from '@/routes/admin';
import { index as jobSearch } from '@/routes/admin/job-search';
import { index as services } from '@/routes/admin/services';
import { index as clients } from '@/routes/admin/clients';
import type { NavItem } from '@/types';

export function useAdminNavItems() {
    const { t } = useI18n();

    return computed<NavItem[]>(() => [
        { title: t('nav.dashboard'), href: dashboard(), icon: LayoutDashboard },
        { title: t('nav.clients'), href: clients(), icon: Users },
        { title: t('nav.services'), href: services(), icon: ConciergeBell },
        {
            title: t('nav.jobSearch'),
            href: jobSearch(),
            icon: BriefcaseBusiness,
        },
        { title: t('nav.settings'), href: settings(), icon: Settings },
    ]);
}
