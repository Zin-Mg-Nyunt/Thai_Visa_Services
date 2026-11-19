<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { update } from '@/actions/App/Http/Controllers/Admin/AppearanceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WebsiteImagePicker from '@/components/WebsiteImagePicker.vue';
import { pageTitle } from '@/lib/pageTitle';

const props = defineProps<{
    appearance: {
        logo_url: string | null;
        favicon_url: string | null;
        hero_image_url: string | null;
        hero_heading: string;
        hero_heading_my: string;
        hero_description: string;
        hero_description_my: string;
    };
}>();

const { t } = useI18n();

const form = useForm({
    logo: null as File | null,
    favicon: null as File | null,
    hero_image: null as File | null,
    remove_hero_image: false,
    hero_heading: props.appearance.hero_heading,
    hero_heading_my: props.appearance.hero_heading_my,
    hero_description: props.appearance.hero_description,
    hero_description_my: props.appearance.hero_description_my,
});

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base leading-relaxed shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm';

const submit = () => {
    const target = update();

    form.submit(target.method, target.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('logo', 'favicon', 'hero_image', 'remove_hero_image');
        },
    });
};
</script>

<template>
    <Head :title="pageTitle('appearance.head')" />

    <h1 class="text-2xl font-bold text-brand-dark">
        {{ t('appearance.title') }}
    </h1>
    <p class="mt-1 mb-6 text-sm text-muted-foreground">
        {{ t('appearance.description') }}
    </p>

    <form
        class="max-w-3xl space-y-6"
        data-test="appearance-form"
        @submit.prevent="submit"
    >
        <section
            class="space-y-8 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('appearance.images') }}
            </h2>

            <WebsiteImagePicker
                v-model="form.logo"
                kind="logo"
                :label="t('appearance.logo.title')"
                :hint="t('appearance.logo.hint')"
                :current-url="appearance.logo_url"
                :error="form.errors.logo"
            />
            <WebsiteImagePicker
                v-model="form.favicon"
                kind="favicon"
                :label="t('appearance.favicon.title')"
                :hint="t('appearance.favicon.hint')"
                :current-url="appearance.favicon_url"
                :error="form.errors.favicon"
            />
            <WebsiteImagePicker
                v-model="form.hero_image"
                v-model:removed="form.remove_hero_image"
                kind="hero"
                removable
                :label="t('appearance.hero.title')"
                :hint="t('appearance.hero.hint')"
                :current-url="appearance.hero_image_url"
                :error="form.errors.hero_image"
            />
        </section>

        <section
            class="space-y-6 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div>
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('appearance.text.title') }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('appearance.text.hint') }}
                </p>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="hero_heading">{{
                        t('appearance.text.headingEn')
                    }}</Label>
                    <Input
                        id="hero_heading"
                        v-model="form.hero_heading"
                        name="hero_heading"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.hero_heading"
                    />
                    <InputError :message="form.errors.hero_heading" />
                </div>
                <div class="grid gap-2">
                    <Label for="hero_heading_my">{{
                        t('appearance.text.headingMy')
                    }}</Label>
                    <Input
                        id="hero_heading_my"
                        v-model="form.hero_heading_my"
                        name="hero_heading_my"
                        autocomplete="off"
                        class="leading-relaxed"
                        :aria-invalid="!!form.errors.hero_heading_my"
                    />
                    <InputError :message="form.errors.hero_heading_my" />
                </div>
                <div class="grid gap-2">
                    <Label for="hero_description">{{
                        t('appearance.text.descriptionEn')
                    }}</Label>
                    <textarea
                        id="hero_description"
                        v-model="form.hero_description"
                        name="hero_description"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors.hero_description"
                    />
                    <InputError :message="form.errors.hero_description" />
                </div>
                <div class="grid gap-2">
                    <Label for="hero_description_my">{{
                        t('appearance.text.descriptionMy')
                    }}</Label>
                    <textarea
                        id="hero_description_my"
                        v-model="form.hero_description_my"
                        name="hero_description_my"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors.hero_description_my"
                    />
                    <InputError :message="form.errors.hero_description_my" />
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <Button
                type="submit"
                variant="pill"
                :disabled="form.processing"
                data-test="appearance-save"
            >
                <Save class="size-4" />
                {{ t('common.save') }}
            </Button>
        </div>
    </form>
</template>
