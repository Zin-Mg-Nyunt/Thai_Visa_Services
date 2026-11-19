<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    FileText,
    Palette,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { pageTitle } from '@/lib/pageTitle';

defineProps<{
    account: {
        name: string;
        email: string;
        profile_url: string;
        security_url: string;
    };
    /** The addresses of the two website screens, or null when the admin may not manage the website. */
    appearance_url: string | null;
    content_url: string | null;
    /** Whether this admin's Google account is linked (read only). */
    google_linked: boolean;
    /** The currency of every amount (read only). */
    currency: string;
}>();

const { t } = useI18n();

const cardClass =
    'flex items-center gap-4 rounded-2xl border bg-card p-4 shadow-xs transition hover:border-brand hover:bg-mint/40 sm:p-5';
const iconClass =
    'flex size-10 shrink-0 items-center justify-center rounded-xl bg-mint text-brand-dark';
</script>

<template>
    <Head :title="pageTitle('nav.settings')" />

    <h1 class="text-2xl font-bold text-brand-dark">{{ t('nav.settings') }}</h1>
    <p class="mt-1 mb-6 text-sm text-muted-foreground">
        {{ t('siteSettings.description') }}
    </p>

    <div class="grid max-w-3xl gap-8">
        <section data-test="account-section">
            <h2 class="mb-3 text-lg font-semibold text-brand-dark">
                {{ t('siteSettings.account.title') }}
            </h2>
            <p
                class="mb-3 text-sm text-muted-foreground"
                data-test="account-who"
            >
                {{ account.name }} · {{ account.email }}
            </p>
            <ul class="grid gap-3">
                <li>
                    <Link
                        :href="account.profile_url"
                        :class="cardClass"
                        data-test="profile-link"
                    >
                        <span :class="iconClass"
                            ><UserRound class="size-5"
                        /></span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-bold text-brand-dark"
                                >{{ t('siteSettings.account.profile') }}</span
                            >
                            <span
                                class="mt-0.5 block text-sm text-muted-foreground"
                                >{{
                                    t('siteSettings.account.profileHint')
                                }}</span
                            >
                        </span>
                        <ChevronRight
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
                <li>
                    <Link
                        :href="account.security_url"
                        :class="cardClass"
                        data-test="security-link"
                    >
                        <span :class="iconClass"
                            ><ShieldCheck class="size-5"
                        /></span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-bold text-brand-dark"
                                >{{ t('siteSettings.account.security') }}</span
                            >
                            <span
                                class="mt-0.5 block text-sm text-muted-foreground"
                                >{{
                                    t('siteSettings.account.securityHint')
                                }}</span
                            >
                        </span>
                        <ChevronRight
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
            </ul>
        </section>

        <section
            v-if="appearance_url || content_url"
            data-test="website-section"
        >
            <h2 class="mb-3 text-lg font-semibold text-brand-dark">
                {{ t('siteSettings.website.title') }}
            </h2>
            <ul class="grid gap-3">
                <li v-if="appearance_url">
                    <Link
                        :href="appearance_url"
                        :class="cardClass"
                        data-test="appearance-link"
                    >
                        <span :class="iconClass"
                            ><Palette class="size-5"
                        /></span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-bold text-brand-dark"
                                >{{ t('siteSettings.appearance.title') }}</span
                            >
                            <span
                                class="mt-0.5 block text-sm text-muted-foreground"
                                >{{
                                    t('siteSettings.appearance.description')
                                }}</span
                            >
                        </span>
                        <ChevronRight
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
                <li v-if="content_url">
                    <Link
                        :href="content_url"
                        :class="cardClass"
                        data-test="content-link"
                    >
                        <span :class="iconClass"
                            ><FileText class="size-5"
                        /></span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-bold text-brand-dark"
                                >{{ t('siteSettings.content.title') }}</span
                            >
                            <span
                                class="mt-0.5 block text-sm text-muted-foreground"
                                >{{
                                    t('siteSettings.content.description')
                                }}</span
                            >
                        </span>
                        <ChevronRight
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
            </ul>
        </section>

        <section data-test="system-section">
            <h2 class="mb-3 text-lg font-semibold text-brand-dark">
                {{ t('siteSettings.system.title') }}
            </h2>
            <dl class="divide-y rounded-2xl border bg-card text-sm shadow-xs">
                <div
                    class="flex items-center justify-between gap-4 p-4 sm:px-5"
                >
                    <div class="min-w-0">
                        <dt class="font-semibold text-brand-dark">
                            {{ t('siteSettings.system.google') }}
                        </dt>
                        <dd class="mt-0.5 text-muted-foreground">
                            {{ t('siteSettings.system.googleHint') }}
                        </dd>
                    </div>
                    <Badge
                        variant="outline"
                        :class="
                            google_linked
                                ? 'border-brand/40 bg-mint text-brand-dark'
                                : 'text-muted-foreground'
                        "
                        data-test="google-state"
                    >
                        {{
                            google_linked
                                ? t('siteSettings.system.linked')
                                : t('siteSettings.system.notLinked')
                        }}
                    </Badge>
                </div>
                <div
                    class="flex items-center justify-between gap-4 p-4 sm:px-5"
                >
                    <div class="min-w-0">
                        <dt class="font-semibold text-brand-dark">
                            {{ t('siteSettings.system.currency') }}
                        </dt>
                        <dd class="mt-0.5 text-muted-foreground">
                            {{ t('siteSettings.system.currencyHint') }}
                        </dd>
                    </div>
                    <span
                        class="font-semibold text-brand-dark"
                        data-test="currency"
                        >{{ currency }}</span
                    >
                </div>
            </dl>
        </section>
    </div>
</template>
