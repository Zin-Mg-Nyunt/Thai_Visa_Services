<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useLocale } from '@/composables/useLocale';
import type { Locale } from '@/composables/useLocale';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        tone?: 'light' | 'dark';
    }>(),
    {
        tone: 'light',
    },
);

const { t } = useI18n();
const { locale, supportedLocales, setLocale } = useLocale();

const options: Locale[] = [...supportedLocales].reverse();
</script>

<template>
    <div
        role="group"
        :aria-label="t('language.label')"
        :class="
            cn(
                'inline-flex items-center rounded-full border p-0.5 text-sm font-semibold',
                tone === 'dark'
                    ? 'border-white/70 text-white'
                    : 'border-brand-dark/40 text-brand-dark',
            )
        "
    >
        <button
            v-for="option in options"
            :key="option"
            type="button"
            :lang="option"
            :aria-pressed="locale === option"
            :class="
                cn(
                    'rounded-full px-3 py-1 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    locale === option
                        ? 'bg-brand text-brand-dark'
                        : 'opacity-80 hover:opacity-100',
                )
            "
            @click="setLocale(option)"
        >
            {{ t(`language.${option}`) }}
        </button>
    </div>
</template>
