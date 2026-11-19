<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, Palette } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { pageTitle } from '@/lib/pageTitle';

defineProps<{
    /** The address of the Website appearance screen, or null when the admin may not manage it. */
    appearance_url: string | null;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="pageTitle('nav.settings')" />

    <h1 class="text-2xl font-bold text-brand-dark">{{ t('nav.settings') }}</h1>
    <p class="mt-1 mb-6 text-sm text-muted-foreground">
        {{ t('siteSettings.description') }}
    </p>

    <ul class="grid max-w-3xl gap-3">
        <li v-if="appearance_url">
            <Link
                :href="appearance_url"
                class="flex items-center gap-4 rounded-2xl border bg-card p-4 shadow-xs transition hover:border-brand hover:bg-mint/40 sm:p-5"
                data-test="appearance-link"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-mint text-brand-dark"
                >
                    <Palette class="size-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-bold text-brand-dark">{{
                        t('siteSettings.appearance.title')
                    }}</span>
                    <span class="mt-0.5 block text-sm text-muted-foreground">{{
                        t('siteSettings.appearance.description')
                    }}</span>
                </span>
                <ChevronRight class="size-5 shrink-0 text-muted-foreground" />
            </Link>
        </li>
    </ul>
</template>
