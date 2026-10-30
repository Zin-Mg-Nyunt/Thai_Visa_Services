<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { jobSearch, services, settings } from '@/routes/admin';
import { index as clients } from '@/routes/admin/clients';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const emit = defineEmits<{
    navigate: [];
}>();

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { isCurrentOrParentUrl } = useCurrentUrl();

const items = computed<NavItem[]>(() => [
    { title: t('nav.dashboard'), href: dashboard() },
    { title: t('nav.clients'), href: clients() },
    { title: t('nav.services'), href: services() },
    { title: t('nav.jobSearch'), href: jobSearch() },
    { title: t('nav.settings'), href: settings() },
]);

const handleLogout = () => {
    router.flushAll();
    emit('navigate');
};
</script>

<template>
    <div class="flex h-full flex-col justify-between gap-6">
        <nav class="flex flex-col gap-1" :aria-label="t('nav.dashboard')">
            <Link
                v-for="item in items"
                :key="String(item.title)"
                :href="item.href"
                :class="
                    cn(
                        'rounded-xl px-4 py-3 text-sm font-medium transition-colors',
                        isCurrentOrParentUrl(item.href)
                            ? 'bg-sidebar-primary font-semibold text-sidebar-primary-foreground'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                    )
                "
                @click="emit('navigate')"
            >
                {{ item.title }}
            </Link>
        </nav>

        <div
            class="flex flex-col gap-3 rounded-2xl bg-sidebar-accent p-3"
            data-test="sidebar-card"
        >
            <Link
                :href="editProfile()"
                class="flex w-full flex-col items-start gap-2 rounded-xl px-2 py-2 transition-colors hover:bg-sidebar/40"
                data-test="sidebar-user"
                @click="emit('navigate')"
            >
                <span
                    class="w-full truncate text-sm font-semibold text-sidebar-accent-foreground"
                >
                    {{ user.name }}
                </span>
                <span
                    class="rounded-full bg-sidebar-primary px-2.5 py-0.5 text-xs font-semibold text-sidebar-primary-foreground"
                    data-test="role-badge"
                >
                    {{ t(`roles.${user.role}`) }}
                </span>
            </Link>
            <div class="border-t border-sidebar-border pt-3">
                <LanguageSwitcher tone="dark" />
            </div>
            <Link
                :href="logout()"
                as="button"
                class="w-full rounded-xl px-2 py-2 text-left text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar/40"
                data-test="logout-button"
                @click="handleLogout"
            >
                {{ t('nav.signOut') }}
            </Link>
        </div>
    </div>
</template>
