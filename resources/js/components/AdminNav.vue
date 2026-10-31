<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { useAdminNavItems } from '@/composables/useAdminNavItems';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { isCurrentOrParentUrl } = useCurrentUrl();

const items = useAdminNavItems();

const handleLogout = () => {
    router.flushAll();
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
                        'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition-colors',
                        isCurrentOrParentUrl(item.href)
                            ? 'bg-sidebar-primary font-semibold text-sidebar-primary-foreground'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                    )
                "
            >
                <component :is="item.icon" class="size-5 shrink-0" />
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
            >
                <span
                    class="flex w-full items-center gap-2 text-sm font-semibold text-sidebar-accent-foreground"
                >
                    <UserRound class="size-4 shrink-0" />
                    <span class="truncate">{{ user.name }}</span>
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
                class="flex w-full items-center gap-2 rounded-xl px-2 py-2 text-left text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar/40"
                data-test="logout-button"
                @click="handleLogout"
            >
                <LogOut class="size-4 shrink-0" />
                {{ t('nav.signOut') }}
            </Link>
        </div>
    </div>
</template>
