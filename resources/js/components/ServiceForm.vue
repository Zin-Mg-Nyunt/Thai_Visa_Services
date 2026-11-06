<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Save, X } from '@lucide/vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Admin/ServiceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { localised } from '@/lib/service';
import type { ServiceAvailability } from '@/lib/status';
import { index, show } from '@/routes/admin/services';
import type { ParentOption, Service } from '@/types';

const props = defineProps<{
    service?: Service;
    parentOptions: ParentOption[];
    defaultParentId?: number | null;
}>();

const { t } = useI18n();

const statuses: ServiceAvailability[] = ['active', 'inactive'];

const form = useForm({
    title: props.service?.title ?? '',
    title_my: props.service?.title_my ?? '',
    public_description: props.service?.public_description ?? '',
    public_description_my: props.service?.public_description_my ?? '',
    parent_id: (props.service?.parent_id ?? props.defaultParentId ?? null) as
        | number
        | null,
    status: (props.service?.status ?? 'active') as ServiceAvailability,
    default_fee: props.service?.default_fee ?? '',
    has_documents: props.service?.has_documents ?? false,
    show_on_website: props.service?.show_on_website ?? false,
});

const isSubService = computed(() => form.parent_id !== null);

watch(isSubService, (value) => {
    if (value) {
        form.show_on_website = false;
    }
});

const parentValue = computed({
    get: () => (form.parent_id === null ? 'none' : String(form.parent_id)),
    set: (value: string) =>
        (form.parent_id = value === 'none' ? null : Number(value)),
});

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base leading-relaxed shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm';

const cancelHref = () => (props.service ? show(props.service.id) : index());

const submit = () => {
    const target = props.service ? update(props.service.id) : store();

    form.submit(target.method, target.url);
};
</script>

<template>
    <form class="max-w-3xl space-y-6" @submit.prevent="submit">
        <section
            class="space-y-6 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div>
                <h2 class="text-lg font-semibold text-brand-dark">
                    {{ t('services.details.title') }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        t(
                            isSubService
                                ? 'services.form.subServiceLanguageHint'
                                : 'services.form.languageHint',
                        )
                    }}
                </p>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="title">{{ t('services.form.titleEn') }}</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        name="title"
                        autocomplete="off"
                        :aria-invalid="!!form.errors.title"
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="title_my">{{
                        t('services.form.titleMy')
                    }}</Label>
                    <Input
                        id="title_my"
                        v-model="form.title_my"
                        name="title_my"
                        autocomplete="off"
                        class="leading-relaxed"
                        :aria-invalid="!!form.errors.title_my"
                    />
                    <InputError :message="form.errors.title_my" />
                </div>

                <div v-if="parentOptions.length" class="grid gap-2">
                    <Label for="parent_id">{{
                        t('services.form.parent')
                    }}</Label>
                    <Select v-model="parentValue">
                        <SelectTrigger
                            id="parent_id"
                            class="w-full"
                            :aria-invalid="!!form.errors.parent_id"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">
                                {{ t('services.form.parentNone') }}
                            </SelectItem>
                            <SelectItem
                                v-for="option in parentOptions"
                                :key="option.id"
                                :value="String(option.id)"
                            >
                                {{
                                    localised(option.title, option.title_my) ||
                                    t('services.untitled')
                                }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ t('services.form.parentHint') }}
                    </p>
                    <InputError :message="form.errors.parent_id" />
                </div>
                <p
                    v-else-if="service"
                    class="self-end text-xs text-muted-foreground"
                >
                    {{ t('services.form.parentLocked') }}
                </p>

                <div class="grid gap-2">
                    <Label for="status">{{ t('services.form.status') }}</Label>
                    <Select v-model="form.status">
                        <SelectTrigger
                            id="status"
                            class="w-full"
                            :aria-invalid="!!form.errors.status"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="status in statuses"
                                :key="status"
                                :value="status"
                            >
                                {{ t(`serviceAvailability.${status}`) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.status" />
                </div>

                <div class="grid gap-2">
                    <Label for="default_fee">{{
                        t('services.form.defaultFee')
                    }}</Label>
                    <Input
                        id="default_fee"
                        v-model="form.default_fee"
                        name="default_fee"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        :aria-invalid="!!form.errors.default_fee"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('services.form.feeHint') }}
                    </p>
                    <InputError :message="form.errors.default_fee" />
                </div>
            </div>
        </section>

        <section
            class="space-y-4 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('services.form.options') }}
            </h2>

            <Label for="has_documents" class="flex items-center gap-3">
                <Checkbox
                    id="has_documents"
                    :model-value="form.has_documents"
                    @update:model-value="
                        (value) => (form.has_documents = value === true)
                    "
                />
                <span>{{ t('services.form.hasDocuments') }}</span>
            </Label>
            <InputError :message="form.errors.has_documents" />

            <Label
                v-if="!isSubService"
                for="show_on_website"
                class="flex items-center gap-3"
            >
                <Checkbox
                    id="show_on_website"
                    :model-value="form.show_on_website"
                    @update:model-value="
                        (value) => (form.show_on_website = value === true)
                    "
                />
                <span>{{ t('services.form.showOnWebsite') }}</span>
            </Label>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('services.form.subServiceNotShown') }}
            </p>
            <InputError :message="form.errors.show_on_website" />
        </section>

        <section
            class="space-y-4 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('services.form.descriptionTitle') }}
            </h2>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="public_description">{{
                        t('services.form.descriptionEn')
                    }}</Label>
                    <textarea
                        id="public_description"
                        v-model="form.public_description"
                        name="public_description"
                        rows="4"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors.public_description"
                    />
                    <InputError :message="form.errors.public_description" />
                </div>

                <div class="grid gap-2">
                    <Label for="public_description_my">{{
                        t('services.form.descriptionMy')
                    }}</Label>
                    <textarea
                        id="public_description_my"
                        v-model="form.public_description_my"
                        name="public_description_my"
                        rows="4"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors.public_description_my"
                    />
                    <InputError :message="form.errors.public_description_my" />
                </div>
            </div>
        </section>

        <div
            class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end"
        >
            <Button as-child variant="pillOutline" class="w-full sm:w-auto">
                <Link :href="cancelHref()">
                    <X class="size-4" />
                    {{ t('common.cancel') }}
                </Link>
            </Button>
            <Button
                type="submit"
                variant="pill"
                class="w-full sm:w-auto"
                :disabled="form.processing"
                data-test="save-service-button"
            >
                <Save class="size-4" />
                {{ t('common.save') }}
            </Button>
        </div>
    </form>
</template>
