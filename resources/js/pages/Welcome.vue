<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Award,
    BriefcaseBusiness,
    HeartHandshake,
    Mail,
    MapPin,
    MessageCircle,
    Phone,
    Target,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import HeroIllustration from '@/components/HeroIllustration.vue';
import { Button } from '@/components/ui/button';
import { localized } from '@/composables/useLocale';
import {
    agencyName,
    hasContactDetails,
    serviceCardsOf,
} from '@/lib/landingSections';
import type { LandingService } from '@/lib/landingSections';
import type { PublicSite } from '@/types/site';

const { t, locale } = useI18n();
// The two public numbers are real totals from the database (cached on the server). It is null when there is nothing to show
// yet, which hides the section. A zero is shown as a zero when the other number is not.
const props = defineProps<{
    statistics: Partial<
        Record<'clients_served' | 'services_provided', number>
    > | null;
    site: PublicSite;
    services: LandingService[];
}>();

// The tab title is the agency name (the app name when there is none); documentTitle() adds the app name only if it is not already in it.
const page = usePage();
const name = computed(() => agencyName(props.site, page.props.name as string));

// Only the statistics the admin approved reach the page (see WelcomeController); each one that is sent is shown.
const stats = computed(() =>
    (
        [
            { key: 'clients_served', label: 'landing.stats.clients' },
            { key: 'services_provided', label: 'landing.stats.services' },
        ] as const
    )
        .filter((stat) => props.statistics?.[stat.key] !== undefined)
        .map((stat) => ({
            value: props.statistics?.[stat.key] ?? 0,
            label: stat.label,
        })),
);

// The texts the admin wrote in Website content (shown once the website is published). Where one is empty the built-in text is the
// fallback, except for Experience, which has none: an empty card is hidden.
const heroHeading = computed(() =>
    localized(props.site.hero_heading, props.site.hero_heading_my),
);
const heroDescription = computed(
    () =>
        localized(
            props.site.hero_description,
            props.site.hero_description_my,
        ) || t('landing.hero.description'),
);
const overview = computed(() =>
    localized(props.site.overview, props.site.overview_my),
);
const aboutCards = computed(() =>
    [
        {
            key: 'experience',
            icon: Award,
            title: t('landing.about.experienceTitle'),
            text: localized(props.site.experience, props.site.experience_my),
        },
        {
            key: 'mission',
            icon: Target,
            title: t('landing.about.missionTitle'),
            text:
                localized(props.site.mission, props.site.mission_my) ||
                t('landing.about.missionText'),
        },
        {
            key: 'help',
            icon: HeartHandshake,
            title: t('landing.about.helpTitle'),
            text:
                localized(props.site.how_we_help, props.site.how_we_help_my) ||
                t('landing.about.helpText'),
        },
    ].filter((card) => card.text !== ''),
);

// Contact details come only from the settings, and only the filled ones are shown. A link is built only from a filled field.
const address = computed(() =>
    localized(props.site.address, props.site.address_my),
);
const socialNames: Record<string, string> = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    tiktok: 'TikTok',
    youtube: 'YouTube',
};
const socialLinks = computed(() =>
    Object.entries(props.site.social_links).map(([key, url]) => ({
        key,
        url,
        label: socialNames[key] ?? key,
    })),
);
const hasContact = computed(() => hasContactDetails(props.site));

// The contact details in one line from the medium screens up: phone, email, address. Phone and email are links, built from the filled
// fields only.
const contactItems = computed(() =>
    [
        props.site.phone && props.site.phone_tel
            ? {
                  key: 'phone',
                  icon: Phone,
                  text: props.site.phone,
                  href: `tel:${props.site.phone_tel}`,
              }
            : null,
        props.site.email
            ? {
                  key: 'email',
                  icon: Mail,
                  text: props.site.email,
                  href: `mailto:${props.site.email}`,
              }
            : null,
        address.value
            ? { key: 'address', icon: MapPin, text: address.value, href: null }
            : null,
    ].filter((item) => item !== null),
);

// The services come from the catalogue (main services shown on the website), in both languages; see serviceCardsOf().
const serviceCards = computed(() => serviceCardsOf(props.services));

const formatNumber = (value: number) =>
    value.toLocaleString(locale.value === 'my' ? 'my-MM' : 'en-US');
</script>

<template>
    <Head :title="name" />

    <section class="bg-brand-dark text-white">
        <div
            :class="
                site.hero_image_url
                    ? 'md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]'
                    : 'md:grid-cols-2'
            "
            class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 md:gap-8 md:px-8 md:py-20"
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
                    <template v-if="heroHeading">{{ heroHeading }}</template>
                    <template v-else>
                        {{ t('landing.hero.titleStart') }}
                        <span class="text-brand">{{
                            t('landing.hero.titleHighlight')
                        }}</span>
                    </template>
                </h1>
                <p
                    class="mt-4 max-w-md text-sm leading-relaxed text-white/80 sm:text-base"
                >
                    {{ heroDescription }}
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Button
                        v-if="serviceCards.length > 0"
                        as-child
                        variant="pill"
                    >
                        <a href="#services">{{ t('landing.ourServices') }}</a>
                    </Button>
                    <Button
                        v-if="hasContact"
                        as-child
                        variant="outline"
                        class="rounded-full border-white bg-transparent px-6 font-semibold text-white hover:bg-white/10 hover:text-white"
                    >
                        <a href="#contact">{{ t('landing.contactUs') }}</a>
                    </Button>
                </div>
            </div>

            <img
                v-if="site.hero_image_url"
                :src="site.hero_image_url"
                alt=""
                class="mx-auto h-80 w-full max-w-xl rounded-3xl object-cover sm:h-100 md:h-104 lg:h-120"
                data-test="hero-image"
            />
            <HeroIllustration v-else />
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
            <p
                v-if="overview"
                class="mt-3 max-w-3xl text-sm leading-relaxed whitespace-pre-line text-muted-foreground sm:text-base"
                data-test="about-overview"
            >
                {{ overview }}
            </p>
            <div
                class="mt-6 grid gap-4"
                :class="
                    aboutCards.length === 3
                        ? 'sm:grid-cols-3'
                        : 'sm:grid-cols-2'
                "
            >
                <article
                    v-for="item in aboutCards"
                    :key="item.key"
                    class="rounded-2xl border bg-card p-5"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-mint text-brand-dark"
                    >
                        <component :is="item.icon" class="size-5" />
                    </div>
                    <h3 class="mt-4 text-sm font-bold text-brand-dark">
                        {{ item.title }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed whitespace-pre-line text-muted-foreground"
                    >
                        {{ item.text }}
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section
        v-if="serviceCards.length > 0"
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
                    v-for="service in serviceCards"
                    :key="service.id"
                    class="rounded-2xl border bg-card p-5"
                    data-test="service-card"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-mint text-brand-dark"
                    >
                        <BriefcaseBusiness class="size-5" />
                    </div>
                    <h3
                        class="mt-4 text-sm font-bold wrap-break-word text-brand-dark"
                    >
                        {{ service.title }}
                    </h3>
                    <p
                        v-if="service.description !== ''"
                        class="mt-2 text-sm leading-relaxed wrap-break-word text-muted-foreground"
                    >
                        {{ service.description }}
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
        v-if="hasContact"
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
                <ul
                    class="mt-3 flex flex-col gap-1.5 text-sm text-muted-foreground md:flex-row md:flex-wrap md:items-center md:gap-x-0 md:gap-y-1"
                    data-test="contact-details"
                >
                    <li
                        v-for="(item, index) in contactItems"
                        :key="item.key"
                        class="flex items-center"
                    >
                        <span
                            v-if="index > 0"
                            class="mx-3 hidden md:inline"
                            aria-hidden="true"
                            >·</span
                        >
                        <component
                            :is="item.href ? 'a' : 'span'"
                            :href="item.href ?? undefined"
                            class="flex items-center gap-2 break-all"
                            :class="
                                item.href
                                    ? 'underline-offset-4 hover:text-brand-dark hover:underline'
                                    : ''
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-4 shrink-0"
                            />
                            {{ item.text }}
                        </component>
                    </li>
                </ul>
                <ul
                    v-if="socialLinks.length > 0"
                    class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm"
                    data-test="social-links"
                >
                    <li v-for="link in socialLinks" :key="link.key">
                        <a
                            :href="link.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-semibold text-brand-dark underline-offset-4 hover:underline"
                            >{{ link.label }}</a
                        >
                    </li>
                </ul>
            </div>
            <div class="flex flex-wrap gap-3">
                <Button v-if="site.phone_tel" as-child variant="pill">
                    <a :href="`tel:${site.phone_tel}`" data-test="call-now">
                        <Phone class="size-4" />
                        {{ t('landing.contact.callNow') }}
                    </a>
                </Button>
                <Button v-if="site.messaging_link" as-child variant="pillDark">
                    <a
                        :href="site.messaging_link"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="message-us"
                    >
                        <MessageCircle class="size-4" />
                        {{ t('landing.contact.message') }}
                    </a>
                </Button>
            </div>
        </div>
    </section>
</template>
