<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div
        class="relative mx-auto aspect-[4/5] w-full max-w-64 overflow-hidden rounded-3xl bg-white/10 sm:max-w-xs md:max-w-sm"
        data-test="hero-illustration"
    >
        <div
            class="absolute -top-8 -right-8 size-2/5 rounded-full bg-brand/25"
            aria-hidden="true"
        />

        <svg
            viewBox="0 0 280 350"
            class="absolute inset-x-0 bottom-0 w-full"
            role="img"
            :aria-label="t('landing.hero.photoTag')"
        >
            <path d="M40 350c0-70 45-110 100-110s100 40 100 110z" fill="#fff" />
            <path
                d="M110 232h60v38a30 30 0 0 1-60 0z"
                fill="#e8b996"
                transform="translate(0 -6)"
            />
            <path
                d="M112 236l28 38 28-38"
                fill="none"
                stroke="#00d47f"
                stroke-width="9"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path
                d="M92 110c0-45 22-70 50-70s50 25 50 70z"
                fill="#14292e"
                transform="translate(-2 -4)"
            />
            <ellipse cx="140" cy="132" rx="50" ry="62" fill="#eec6a1" />
            <path
                d="M92 112c0-48 22-72 50-72s50 24 50 72c-12-24-30-32-50-32s-38 8-50 32z"
                fill="#14292e"
            />
        </svg>

        <span
            class="absolute bottom-3 left-3 rounded-full bg-brand-dark px-3 py-1 text-[11px] font-medium text-white"
        >
            {{ t('landing.hero.photoTag') }}
        </span>
    </div>
</template>
