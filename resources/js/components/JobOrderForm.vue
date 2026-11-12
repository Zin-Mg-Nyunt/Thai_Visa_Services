<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Save, TriangleAlert, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Admin/JobOrderController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/admin/job-search';
import type { JobOrder } from '@/types';

const props = defineProps<{
    jobOrder?: JobOrder;
}>();

const { t } = useI18n();

const form = useForm({
    company_name: props.jobOrder?.company_name ?? '',
    manpower: props.jobOrder?.manpower ?? 0,
    male_count: props.jobOrder?.male_count ?? 0,
    female_count: props.jobOrder?.female_count ?? 0,
    interview_date: props.jobOrder?.interview_date ?? '',
    start_date: props.jobOrder?.start_date ?? '',
    notes: props.jobOrder?.notes ?? '',
});

const submit = () => {
    const target = props.jobOrder ? update(props.jobOrder.id) : store();

    form.submit(target.method, target.url);
};
</script>

<template>
    <form
        class="max-w-3xl space-y-6 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        @submit.prevent="submit"
    >
        <p
            v-if="jobOrder?.exceeds_manpower"
            class="flex items-start gap-2 rounded-xl bg-warning px-3 py-2 text-sm text-warning-foreground"
            role="status"
            data-test="exceeds-manpower-warning"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            {{
                t('jobOrders.exceeds', {
                    count: jobOrder.workers_count,
                    manpower: jobOrder.manpower,
                })
            }}
        </p>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="grid gap-2 md:col-span-2">
                <Label for="company_name"
                    >{{ t('jobOrders.form.company') }} *</Label
                >
                <Input
                    id="company_name"
                    v-model="form.company_name"
                    name="company_name"
                    required
                    maxlength="255"
                    autocomplete="off"
                    :aria-invalid="!!form.errors.company_name"
                />
                <InputError :message="form.errors.company_name" />
            </div>

            <div class="grid gap-2 md:col-span-2">
                <Label for="manpower"
                    >{{ t('jobOrders.form.manpower') }} *</Label
                >
                <Input
                    id="manpower"
                    v-model.number="form.manpower"
                    name="manpower"
                    type="number"
                    min="0"
                    max="65535"
                    step="1"
                    required
                    :aria-invalid="!!form.errors.manpower"
                />
                <p class="text-xs text-muted-foreground">
                    {{ t('jobOrders.form.manpowerHint') }}
                </p>
                <InputError :message="form.errors.manpower" />
            </div>

            <div class="grid gap-2">
                <Label for="male_count">{{ t('jobOrders.form.male') }} *</Label>
                <Input
                    id="male_count"
                    v-model.number="form.male_count"
                    name="male_count"
                    type="number"
                    min="0"
                    max="65535"
                    step="1"
                    required
                    :aria-invalid="!!form.errors.male_count"
                />
                <InputError :message="form.errors.male_count" />
            </div>

            <div class="grid gap-2">
                <Label for="female_count"
                    >{{ t('jobOrders.form.female') }} *</Label
                >
                <Input
                    id="female_count"
                    v-model.number="form.female_count"
                    name="female_count"
                    type="number"
                    min="0"
                    max="65535"
                    step="1"
                    required
                    :aria-invalid="!!form.errors.female_count"
                />
                <InputError :message="form.errors.female_count" />
            </div>

            <div class="grid gap-2">
                <Label for="interview_date">{{
                    t('jobOrders.form.interviewDate')
                }}</Label>
                <Input
                    id="interview_date"
                    v-model="form.interview_date"
                    name="interview_date"
                    type="date"
                    :aria-invalid="!!form.errors.interview_date"
                />
                <InputError :message="form.errors.interview_date" />
            </div>

            <div class="grid gap-2">
                <Label for="start_date">{{
                    t('jobOrders.form.startDate')
                }}</Label>
                <Input
                    id="start_date"
                    v-model="form.start_date"
                    name="start_date"
                    type="date"
                    :min="form.interview_date || undefined"
                    :aria-invalid="!!form.errors.start_date"
                />
                <InputError :message="form.errors.start_date" />
            </div>

            <div class="grid gap-2 md:col-span-2">
                <Label for="notes">{{ t('jobOrders.form.notes') }}</Label>
                <Input
                    id="notes"
                    v-model="form.notes"
                    name="notes"
                    maxlength="2000"
                    :placeholder="t('jobOrders.form.notesPlaceholder')"
                    :aria-invalid="!!form.errors.notes"
                />
                <InputError :message="form.errors.notes" />
            </div>
        </div>

        <div
            class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end"
        >
            <Button as-child variant="pillOutline" class="w-full sm:w-auto">
                <Link :href="index()">
                    <X class="size-4" />
                    {{ t('common.cancel') }}
                </Link>
            </Button>
            <Button
                type="submit"
                variant="pill"
                class="w-full sm:w-auto"
                :disabled="form.processing"
                data-test="save-job-order-button"
            >
                <Save class="size-4" />
                {{ t('common.save') }}
            </Button>
        </div>
    </form>
</template>
