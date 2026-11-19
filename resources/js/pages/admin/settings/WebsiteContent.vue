<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    publish,
    update,
} from '@/actions/App/Http/Controllers/Admin/WebsiteContentController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import LinkField from '@/components/LinkField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { pageTitle } from '@/lib/pageTitle';

const props = defineProps<{
    content: {
        agency_name: string;
        agency_name_my: string;
        overview: string;
        overview_my: string;
        experience: string;
        experience_my: string;
        mission: string;
        mission_my: string;
        how_we_help: string;
        how_we_help_my: string;
        address: string;
        address_my: string;
        phone: string;
        email: string;
        messaging_link: string;
        social_links: Record<string, string>;
        approved_statistics: string[];
        is_published: boolean;
    };
    has_logo: boolean;
    has_favicon: boolean;
}>();

const { t } = useI18n();

const form = useForm({
    agency_name: props.content.agency_name,
    agency_name_my: props.content.agency_name_my,
    overview: props.content.overview,
    overview_my: props.content.overview_my,
    experience: props.content.experience,
    experience_my: props.content.experience_my,
    mission: props.content.mission,
    mission_my: props.content.mission_my,
    how_we_help: props.content.how_we_help,
    how_we_help_my: props.content.how_we_help_my,
    address: props.content.address,
    address_my: props.content.address_my,
    phone: props.content.phone,
    email: props.content.email,
    messaging_link: props.content.messaging_link,
    social_links: { ...props.content.social_links },
    approved_statistics: [...props.content.approved_statistics],
});

// Publishing is its own action, separate from Save: it acts at once and publishes what is saved.
const publishForm = useForm({ published: false });
const confirmOpen = ref(false);

const socialPlatforms = [
    { key: 'facebook', label: 'Facebook' },
    { key: 'instagram', label: 'Instagram' },
    { key: 'tiktok', label: 'TikTok' },
    { key: 'youtube', label: 'YouTube' },
];

const statistics = [
    { key: 'clients_served', label: 'landing.stats.clients' },
    { key: 'services_provided', label: 'landing.stats.services' },
];

const aboutTexts = [
    { field: 'overview', label: 'overview' },
    { field: 'experience', label: 'experience' },
    { field: 'mission', label: 'mission' },
    { field: 'how_we_help', label: 'howWeHelp' },
] as const;

const canPublish = props.has_logo && props.has_favicon;

const togglePublish = () => {
    publishForm.published = !props.content.is_published;
    publishForm.post(publish().url, {
        preserveScroll: true,
        onFinish: () => (confirmOpen.value = false),
    });
};

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base leading-relaxed shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm';

const isApproved = (key: string) => form.approved_statistics.includes(key);

const setApproved = (key: string, checked: boolean) => {
    form.approved_statistics = checked
        ? [...form.approved_statistics.filter((item) => item !== key), key]
        : form.approved_statistics.filter((item) => item !== key);
};

const submit = () => {
    const target = update();

    form.submit(target.method, target.url, { preserveScroll: true });
};
</script>

<template>
    <Head :title="pageTitle('websiteContent.head')" />

    <h1 class="text-2xl font-bold text-brand-dark">
        {{ t('websiteContent.title') }}
    </h1>
    <p class="mt-1 mb-6 text-sm text-muted-foreground">
        {{ t('websiteContent.description') }}
    </p>

    <form
        class="max-w-3xl space-y-6"
        data-test="content-form"
        @submit.prevent="submit"
    >
        <section
            class="space-y-6 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div>
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('websiteContent.agency.title') }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('websiteContent.languageHint') }}
                </p>
            </div>
            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="agency_name">{{
                        t('websiteContent.agency.nameEn')
                    }}</Label>
                    <Input
                        id="agency_name"
                        v-model="form.agency_name"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.agency_name"
                    />
                    <InputError :message="form.errors.agency_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="agency_name_my">{{
                        t('websiteContent.agency.nameMy')
                    }}</Label>
                    <Input
                        id="agency_name_my"
                        v-model="form.agency_name_my"
                        autocomplete="off"
                        class="leading-relaxed"
                        :aria-invalid="!!form.errors.agency_name_my"
                    />
                    <InputError :message="form.errors.agency_name_my" />
                </div>
            </div>
        </section>

        <section
            class="space-y-6 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div>
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('websiteContent.about.title') }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('websiteContent.about.hint') }}
                </p>
            </div>
            <div
                v-for="item in aboutTexts"
                :key="item.field"
                class="grid gap-6 md:grid-cols-2"
            >
                <div class="grid gap-2">
                    <Label :for="item.field">{{
                        t(`websiteContent.about.${item.label}En`)
                    }}</Label>
                    <textarea
                        :id="item.field"
                        v-model="form[item.field]"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors[item.field]"
                    />
                    <InputError :message="form.errors[item.field]" />
                </div>
                <div class="grid gap-2">
                    <Label :for="`${item.field}_my`">{{
                        t(`websiteContent.about.${item.label}My`)
                    }}</Label>
                    <textarea
                        :id="`${item.field}_my`"
                        v-model="form[`${item.field}_my`]"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors[`${item.field}_my`]"
                    />
                    <InputError :message="form.errors[`${item.field}_my`]" />
                </div>
            </div>
        </section>

        <section
            class="space-y-6 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div>
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('websiteContent.contact.title') }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('websiteContent.contact.hint') }}
                </p>
            </div>
            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="phone">{{
                        t('websiteContent.contact.phone')
                    }}</Label>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        type="tel"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.phone"
                    />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">{{
                        t('websiteContent.contact.email')
                    }}</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.email"
                    />
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="address">{{
                        t('websiteContent.contact.addressEn')
                    }}</Label>
                    <textarea
                        id="address"
                        v-model="form.address"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors.address"
                    />
                    <InputError :message="form.errors.address" />
                </div>
                <div class="grid gap-2">
                    <Label for="address_my">{{
                        t('websiteContent.contact.addressMy')
                    }}</Label>
                    <textarea
                        id="address_my"
                        v-model="form.address_my"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors.address_my"
                    />
                    <InputError :message="form.errors.address_my" />
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <Label for="messaging_link">{{
                        t('websiteContent.contact.messagingLink')
                    }}</Label>
                    <Input
                        id="messaging_link"
                        v-model="form.messaging_link"
                        type="url"
                        inputmode="url"
                        autocomplete="off"
                        placeholder="https://"
                        :aria-invalid="!!form.errors.messaging_link"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('websiteContent.contact.messagingHint') }}
                    </p>
                    <InputError :message="form.errors.messaging_link" />
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-brand-dark">
                    {{ t('websiteContent.social.title') }}
                </h3>
                <div class="mt-3 grid gap-6 md:grid-cols-2">
                    <LinkField
                        v-for="platform in socialPlatforms"
                        :id="`social_${platform.key}`"
                        :key="platform.key"
                        v-model="form.social_links[platform.key]"
                        :label="platform.label"
                        :error="form.errors[`social_links.${platform.key}`]"
                    />
                </div>
            </div>
        </section>

        <section
            class="space-y-4 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div>
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('websiteContent.statistics.title') }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('websiteContent.statistics.hint') }}
                </p>
            </div>
            <Label
                v-for="stat in statistics"
                :key="stat.key"
                :for="`stat_${stat.key}`"
                class="flex items-center gap-3"
            >
                <Checkbox
                    :id="`stat_${stat.key}`"
                    :model-value="isApproved(stat.key)"
                    :data-test="`stat-${stat.key}`"
                    @update:model-value="
                        (value) => setApproved(stat.key, value === true)
                    "
                />
                <span>{{ t(stat.label) }}</span>
            </Label>
            <InputError :message="form.errors.approved_statistics" />
        </section>

        <div class="flex justify-end">
            <Button
                type="submit"
                variant="pill"
                :disabled="form.processing"
                data-test="content-save"
            >
                <Save class="size-4" />
                {{ t('common.save') }}
            </Button>
        </div>
    </form>

    <section
        class="mt-6 max-w-3xl space-y-4 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        data-test="publish-section"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('websiteContent.publish.title') }}
            </h2>
            <Badge
                variant="outline"
                :class="
                    content.is_published
                        ? 'border-brand/40 bg-mint text-brand-dark'
                        : 'text-muted-foreground'
                "
                data-test="publish-state"
            >
                {{
                    content.is_published
                        ? t('websiteContent.publish.statePublished')
                        : t('websiteContent.publish.stateDraft')
                }}
            </Badge>
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                content.is_published
                    ? t('websiteContent.publish.hintPublished')
                    : t('websiteContent.publish.hint')
            }}
        </p>

        <p
            v-if="!content.is_published && !canPublish"
            class="text-sm text-destructive"
            data-test="publish-blocked"
        >
            {{
                t('websiteContent.publish.missing', {
                    items: [
                        !has_logo ? t('websiteContent.publish.logo') : null,
                        !has_favicon
                            ? t('websiteContent.publish.favicon')
                            : null,
                    ]
                        .filter(Boolean)
                        .join(', '),
                })
            }}
        </p>
        <p
            v-if="form.isDirty"
            class="text-sm text-muted-foreground"
            data-test="publish-save-first"
        >
            {{ t('websiteContent.publish.saveFirst') }}
        </p>
        <InputError :message="publishForm.errors.published" />

        <Button
            type="button"
            :variant="content.is_published ? 'pillOutline' : 'pill'"
            :disabled="
                form.isDirty ||
                publishForm.processing ||
                (!content.is_published && !canPublish)
            "
            data-test="publish-button"
            @click="confirmOpen = true"
        >
            {{
                content.is_published
                    ? t('websiteContent.publish.unpublishButton')
                    : t('websiteContent.publish.publishButton')
            }}
        </Button>
    </section>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="
            content.is_published
                ? t('websiteContent.publish.unpublishTitle')
                : t('websiteContent.publish.publishTitle')
        "
        :description="
            content.is_published
                ? t('websiteContent.publish.unpublishDescription')
                : t('websiteContent.publish.publishDescription')
        "
        :confirm-label="
            content.is_published
                ? t('websiteContent.publish.unpublishButton')
                : t('websiteContent.publish.publishButton')
        "
        :tone="content.is_published ? 'destructive' : 'default'"
        :processing="publishForm.processing"
        @confirm="togglePublish"
    />
</template>
