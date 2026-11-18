<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Award,
    BookUser,
    BriefcaseBusiness,
    CalendarCheck,
    FileBadge,
    HeartHandshake,
    Mail,
    MapPin,
    Phone,
    Search,
    Target,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import HeroIllustration from '@/components/HeroIllustration.vue';
import { Button } from '@/components/ui/button';

const { t, locale } = useI18n();
const name = usePage().props.name;

// The two public numbers are real totals from the database (cached on the server). It is null when there is nothing to show
// yet, which hides the section. A zero is shown as a zero when the other number is not.
const props = defineProps<{
    statistics: { clients_served: number; services_provided: number } | null;
}>();

// Mockup data. The real contact details come from settings in a later phase.
const contact = { phone: '+66 2 123 4567', email: 'info@example.com' };
const stats = computed(() =>
    props.statistics
        ? [
              {
                  value: props.statistics.clients_served,
                  label: 'landing.stats.clients',
              },
              {
                  value: props.statistics.services_provided,
                  label: 'landing.stats.services',
              },
          ]
        : [],
);

const about = [
    { icon: Award, key: 'experience' },
    { icon: Target, key: 'mission' },
    { icon: HeartHandshake, key: 'help' },
];

const services = [
    { icon: BriefcaseBusiness, key: 'workPermit' },
    { icon: BookUser, key: 'passport' },
    { icon: FileBadge, key: 'pinkCard' },
    { icon: CalendarCheck, key: 'report' },
    { icon: FileBadge, key: 'ci' },
    { icon: Search, key: 'jobSearch' },
];

const formatNumber = (value: number) =>
    value.toLocaleString(locale.value === 'my' ? 'my-MM' : 'en-US');

const contactLine = computed(() => [
    contact.phone,
    contact.email,
    t('landing.contact.address'),
]);
</script>

<template>
    <Head :title="name" />

    <section class="bg-brand-dark text-white">
        <div
            class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 md:grid-cols-2 md:gap-8 md:px-8 md:py-20"
        >
            <div class="min-w-0">
                <p
                    class="text-xs font-bold tracking-wider text-brand uppercase"
                >
                    {{ t('landing.hero.eyebrow') }}
                </p>
                <h1
                    class="mt-3 text-3xl font-extrabold wrap-break-word sm:text-4xl md:text-5xl"
                    :class="locale === 'my' ? 'leading-[1.6]' : 'leading-tight'"
                    data-test="hero-title"
                >
                    {{ t('landing.hero.titleStart') }}
                    <span class="text-brand">{{
                        t('landing.hero.titleHighlight')
                    }}</span>
                </h1>
                <p
                    class="mt-4 max-w-md text-sm leading-relaxed text-white/80 sm:text-base"
                >
                    {{ t('landing.hero.description') }}
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Button as-child variant="pill">
                        <a href="#services">{{ t('landing.ourServices') }}</a>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        class="rounded-full border-white bg-transparent px-6 font-semibold text-white hover:bg-white/10 hover:text-white"
                    >
                        <a href="#contact">{{ t('landing.contactUs') }}</a>
                    </Button>
                </div>
            </div>

            <HeroIllustration />
        </div>
    </section>

    <section id="about" class="bg-background" data-test="about-section">
        <div class="mx-auto max-w-6xl px-4 py-12 md:px-8 md:py-16">
            <p
                class="text-xs font-bold tracking-wider text-brand-dark/70 uppercase"
            >
                {{ t('landing.about.eyebrow') }}
            </p>
            <h2 class="mt-1 text-2xl font-bold text-brand-dark sm:text-3xl">
                {{ t('landing.about.title') }}
            </h2>
            <div class="mt-6 grid gap-4 sm:grid-cols-3">
                <article
                    v-for="item in about"
                    :key="item.key"
                    class="rounded-2xl border bg-card p-5"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-mint text-brand-dark"
                    >
                        <component :is="item.icon" class="size-5" />
                    </div>
                    <h3 class="mt-4 text-sm font-bold text-brand-dark">
                        {{ t(`landing.about.${item.key}Title`) }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ t(`landing.about.${item.key}Text`) }}
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section
        id="services"
        class="scroll-mt-36 bg-mint/40 md:scroll-mt-20"
        data-test="services-section"
    >
        <div class="mx-auto max-w-6xl px-4 py-12 md:px-8 md:py-16">
            <p
                class="text-xs font-bold tracking-wider text-brand-dark/70 uppercase"
            >
                {{ t('landing.services.eyebrow') }}
            </p>
            <h2 class="mt-1 text-2xl font-bold text-brand-dark sm:text-3xl">
                {{ t('landing.services.title') }}
            </h2>
            <div class="mt-6 grid gap-4 min-[520px]:grid-cols-2 lg:grid-cols-4">
                <article
                    v-for="item in services"
                    :key="item.key"
                    class="rounded-2xl border bg-card p-5"
                    data-test="service-card"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-mint text-brand-dark"
                    >
                        <component :is="item.icon" class="size-5" />
                    </div>
                    <h3 class="mt-4 text-sm font-bold text-brand-dark">
                        {{ t(`landing.services.${item.key}Title`) }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ t(`landing.services.${item.key}Text`) }}
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section v-if="statistics" class="bg-background" data-test="stats-section">
        <div class="mx-auto max-w-6xl px-4 py-10 md:px-8 md:py-14">
            <div
                class="grid gap-6 rounded-3xl bg-brand-dark px-6 py-8 text-center text-white sm:grid-cols-2 sm:py-10"
            >
                <div v-for="stat in stats" :key="stat.label">
                    <p class="text-4xl font-extrabold text-brand">
                        {{ formatNumber(stat.value) }}
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-white/80">
                        {{ t(stat.label) }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section
        id="contact"
        class="scroll-mt-36 bg-mint/40 md:scroll-mt-20"
        data-test="contact-section"
    >
        <div
            class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-12 md:flex-row md:items-center md:justify-between md:px-8 md:py-16"
        >
            <div class="min-w-0">
                <p
                    class="text-xs font-bold tracking-wider text-brand-dark/70 uppercase"
                >
                    {{ t('landing.contact.eyebrow') }}
                </p>
                <h2 class="mt-1 text-2xl font-bold text-brand-dark sm:text-3xl">
                    {{ t('landing.contact.title') }}
                </h2>
                <ul class="mt-3 grid gap-1.5 text-sm text-muted-foreground">
                    <li class="flex items-center gap-2 break-all">
                        <Phone class="size-4 shrink-0" />
                        {{ contactLine[0] }}
                    </li>
                    <li class="flex items-center gap-2 break-all">
                        <Mail class="size-4 shrink-0" />
                        {{ contactLine[1] }}
                    </li>
                    <li class="flex items-center gap-2">
                        <MapPin class="size-4 shrink-0" />
                        {{ contactLine[2] }}
                    </li>
                </ul>
            </div>
            <div class="flex flex-wrap gap-3">
                <Button as-child variant="pill">
                    <a
                        :href="`tel:${contact.phone.replace(/\s/g, '')}`"
                        data-test="call-now"
                    >
                        <Phone class="size-4" />
                        {{ t('landing.contact.callNow') }}
                    </a>
                </Button>
                <Button as-child variant="pillDark">
                    <a :href="`mailto:${contact.email}`" data-test="message-us">
                        <Mail class="size-4" />
                        {{ t('landing.contact.message') }}
                    </a>
                </Button>
            </div>
        </div>
    </section>
</template>
