<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { Button } from '@/components/ui/button';
import { localized } from '@/composables/useLocale';
import { home, login } from '@/routes';
import type { PublicSite } from '@/types/site';

const { t } = useI18n();
const page = usePage();

// The logo and the agency name the admin set in the website settings; without them, the built-in logo and the app name.
const site = computed(() => page.props.site as PublicSite | undefined);
const name = computed(
    () =>
        localized(site.value?.agency_name, site.value?.agency_name_my) ||
        (page.props.name as string),
);
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background">
        <header class="sticky top-0 z-40 bg-brand-dark text-white shadow-sm">
            <div
                class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 md:px-8"
            >
                <Link :href="home()" class="flex min-w-0 items-center gap-3">
                    <img
                        v-if="site?.logo_url"
                        :src="site.logo_url"
                        :alt="name"
                        class="size-10 rounded-lg object-contain"
                        data-test="site-logo"
                    />
                    <AppLogoIcon
                        v-else
                        variant="white"
                        class="size-10 rounded-lg"
                    />
                    <span
                        class="hidden truncate text-lg font-bold sm:inline"
                        data-test="site-name"
                        >{{ name }}</span
                    >
                </Link>
                <div
                    class="flex shrink-0 flex-col-reverse items-end gap-2 sm:flex-row sm:items-center sm:gap-3"
                >
                    <LanguageSwitcher tone="dark" />
                    <Button as-child class="rounded-full font-semibold">
                        <Link :href="login()">{{
                            t('common.adminLogin')
                        }}</Link>
                    </Button>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="bg-brand-dark text-sm text-white/80">
            <div
                class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-5 md:px-8"
            >
                <img
                    v-if="site?.logo_url"
                    :src="site.logo_url"
                    alt=""
                    class="size-7 rounded-md object-contain"
                />
                <AppLogoIcon v-else variant="white" class="size-7 rounded-md" />
                <span>{{ t('landing.rights', { name }) }}</span>
            </div>
        </footer>
    </div>
</template>
