<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useLocale } from '@/composables/useLocale';
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

const nextLocale = computed(() =>
    supportedLocales.find((option) => option !== locale.value)!,
);

const isEnglish = computed(() => locale.value === 'en');
</script>

<template>
    <button
        type="button"
        :lang="locale"
        :aria-label="`${t('language.label')}: ${t(`language.name.${locale}`)}`"
        :title="
            t('language.switchTo', {
                language: t(`language.name.${nextLocale}`),
            })
        "
        :class="
            cn(
                'relative inline-flex h-9 w-21 shrink-0 cursor-pointer items-center rounded-full border text-sm font-bold transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                tone === 'dark'
                    ? 'border-white/70 text-white hover:bg-white/10'
                    : 'border-brand-dark/40 text-brand-dark hover:bg-brand-dark/5',
            )
        "
        data-test="language-switcher"
        @click="setLocale(nextLocale)"
    >
        <span
            :class="
                cn(
                    'absolute inset-y-0 flex items-center justify-center',
                    isEnglish ? 'right-2 left-9' : 'right-9 left-2',
                )
            "
        >
            {{ t(`language.${locale}`) }}
        </span>

        <span
            :class="
                cn(
                    'absolute top-1 left-1 size-7 overflow-hidden rounded-full transition-transform duration-200',
                    isEnglish ? 'translate-x-0' : 'translate-x-12',
                )
            "
            aria-hidden="true"
        >
            <svg v-if="locale === 'en'" viewBox="15 0 30 30" class="size-full">
                <rect x="0" width="60" height="30" fill="#012169" />
                <path d="M0 0L60 30M60 0L0 30" stroke="#fff" stroke-width="6" />
                <path
                    d="M0 0L60 30M60 0L0 30"
                    stroke="#c8102e"
                    stroke-width="2"
                />
                <path d="M30 0V30M0 15H60" stroke="#fff" stroke-width="10" />
                <path d="M30 0V30M0 15H60" stroke="#c8102e" stroke-width="6" />
            </svg>
            <svg v-else viewBox="0 0 30 30" class="size-full">
                <rect width="30" height="10" fill="#fecb00" />
                <rect y="10" width="30" height="10" fill="#34b233" />
                <rect y="20" width="30" height="10" fill="#ea2839" />
                <polygon
                    points="15,6 17.02,12.22 23.56,12.22 18.27,16.06 20.29,22.28 15,18.44 9.71,22.28 11.73,16.06 6.44,12.22 12.98,12.22"
                    fill="#fff"
                />
            </svg>
        </span>
    </button>
</template>
