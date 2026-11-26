<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminBottomNav from '@/components/AdminBottomNav.vue';
import AdminNav from '@/components/AdminNav.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { Toaster } from '@/components/ui/sonner';
import { dashboard, logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

const { t } = useI18n();
const page = usePage();
// The visible name beside the logo is the agency name (none when it is empty); the link's label falls back to the app name.
const logoName = computed(() => page.props.logoName);
const siteName = computed(() => logoName.value ?? page.props.name);
const user = computed(() => page.props.auth.user);

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <div class="min-h-svh bg-background">
        <aside
            class="fixed inset-y-0 left-0 hidden w-60 flex-col gap-8 bg-sidebar p-4 lg:flex"
        >
            <Link :href="dashboard()" class="flex w-fit items-center gap-2">
                <AppLogoIcon variant="white" class="size-12 rounded-xl" />
                <span
                    v-if="logoName"
                    class="hidden text-lg font-bold text-sidebar-foreground sm:inline"
                >
                    {{ logoName }}
                </span>
            </Link>
            <AdminNav class="min-h-0 flex-1" />
        </aside>

        <header
            class="sticky top-0 z-30 flex items-center justify-between gap-2 bg-sidebar px-3 py-2 lg:hidden"
            data-test="top-bar"
        >
            <Link
                :href="dashboard()"
                class="flex shrink-0 items-center gap-3"
                :aria-label="siteName"
            >
                <AppLogoIcon variant="white" class="size-10 rounded-lg" />
                <span
                    v-if="logoName"
                    class="hidden text-lg font-bold text-sidebar-foreground sm:inline"
                >
                    {{ logoName }}
                </span>
            </Link>

            <div class="flex min-w-0 items-center justify-end gap-2">
                <Link
                    :href="editProfile()"
                    class="flex min-w-0 flex-col items-center gap-2 rounded-xl px-2 py-1 transition-colors hover:bg-sidebar-accent"
                    data-test="top-bar-user"
                >
                    <span
                        class="max-w-20 truncate text-sm font-semibold text-sidebar-foreground sm:max-w-40"
                    >
                        {{ user.name }}
                    </span>
                    <span
                        class="shrink-0 rounded-full bg-sidebar-primary px-2 py-0.5 text-xs font-semibold text-sidebar-primary-foreground"
                        data-test="top-bar-role-badge"
                    >
                        {{ t(`roles.${user.role}`) }}
                    </span>
                </Link>
                <LanguageSwitcher tone="dark" />
                <Link
                    :href="logout()"
                    as="button"
                    class="flex size-9 shrink-0 items-center justify-center rounded-full text-sidebar-foreground transition-colors hover:bg-sidebar-accent"
                    :aria-label="t('nav.signOut')"
                    :title="t('nav.signOut')"
                    data-test="top-bar-logout-button"
                    @click="handleLogout"
                >
                    <LogOut class="size-5" />
                </Link>
            </div>
        </header>

        <main
            class="min-w-0 overflow-x-clip p-4 pb-24 md:p-8 md:pb-28 lg:pb-8 lg:pl-68"
        >
            <slot />
        </main>
        <AdminBottomNav />
        <Toaster :mobile-offset="{ bottom: 88 }" />
    </div>
</template>
