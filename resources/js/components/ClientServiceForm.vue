<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Save, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Admin/ClientServiceController';
import InputError from '@/components/InputError.vue';
import RemarkList from '@/components/RemarkList.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/format';
import { localised } from '@/lib/service';
import { serviceStatusKey } from '@/lib/status';
import { show } from '@/routes/admin/clients';
import type {
    Client,
    ClientService,
    ServiceOption,
    SubServiceOption,
} from '@/types';

const props = defineProps<{
    client: Client;
    /** Adding: the services that can be chosen. */
    serviceOptions?: ServiceOption[];
    /** Editing: the record, whose service stays fixed. */
    clientService?: ClientService;
    /** Editing: the sub-services the record can switch between. */
    subServiceOptions?: SubServiceOption[];
    /** Editing: the default fee of the record's service, used when the chosen sub-service has none. */
    serviceDefaultFee?: string | null;
    /** Editing: the money received so far, to warn when the fee goes below it. */
    received?: string;
}>();

const { t } = useI18n();

const isEditing = computed(() => props.clientService !== undefined);

// A cancelled service is history: only a new remark can be added.
const isLocked = computed(() => props.clientService?.status === 'cancel');

// A Job Search service changes status only through its applications, never by editing the record. When it is Complete
// its fee is locked and it takes no new remark; the dates stay editable.
const isJobSearch = computed(() => props.clientService?.is_job_search === true);
const isCompleteJobSearch = computed(
    () => isJobSearch.value && props.clientService?.status === 'complete',
);

const form = useForm({
    service_id: null as number | null,
    sub_service_id: (props.clientService?.sub_service_id ?? null) as
        | number
        | null,
    status: (props.clientService?.status === 'complete'
        ? 'complete'
        : 'in_progress') as 'in_progress' | 'complete',
    start_date: props.clientService?.start_date ?? '',
    end_date: props.clientService?.end_date ?? '',
    fee: props.clientService?.fee ?? '',
    received_now: '',
    remark: '',
});

const titleOf = (option: { title: string | null; title_my: string | null }) =>
    localised(option.title, option.title_my) || t('services.untitled');

const selectedService = computed(() =>
    props.serviceOptions?.find((option) => option.id === form.service_id),
);

const subOptions = computed<SubServiceOption[]>(() =>
    isEditing.value
        ? (props.subServiceOptions ?? [])
        : (selectedService.value?.sub_services ?? []),
);

const selectedSub = computed(() =>
    subOptions.value.find((option) => option.id === form.sub_service_id),
);

const serviceValue = computed({
    get: () => (form.service_id === null ? '' : String(form.service_id)),
    set: (value: string) => {
        form.service_id = Number(value);
        form.sub_service_id = null;
    },
});

const subServiceValue = computed({
    get: () =>
        form.sub_service_id === null ? 'none' : String(form.sub_service_id),
    set: (value: string) =>
        (form.sub_service_id = value === 'none' ? null : Number(value)),
});

const statuses = ['in_progress', 'complete'] as const;

// The fee follows the chosen service (its sub-service fee first) until the admin types their own.
const feeTouched = ref(false);

watch([selectedService, selectedSub], () => {
    if (isEditing.value || feeTouched.value) {
        return;
    }

    form.fee =
        selectedSub.value?.default_fee ??
        selectedService.value?.default_fee ??
        '';
});

// Editing: changing the sub-service sets the fee to its default fee (or the service's), and a notice
// under the fee says what changed. Going back to the saved sub-service restores the saved fee.
const savedSubServiceId = props.clientService?.sub_service_id ?? null;
const savedFee = props.clientService?.fee ?? '';
const feeNotice = ref<{ from: string; to: string; name: string } | null>(null);

watch(
    () => form.sub_service_id,
    (subServiceId) => {
        if (!props.clientService) {
            return;
        }

        if (subServiceId === savedSubServiceId) {
            form.fee = savedFee;
            feeNotice.value = null;

            return;
        }

        const subFee = selectedSub.value?.default_fee ?? null;
        const defaultFee = subFee ?? props.serviceDefaultFee ?? null;

        if (defaultFee === null || defaultFee === form.fee) {
            feeNotice.value = null;

            return;
        }

        const source =
            subFee !== null && selectedSub.value
                ? selectedSub.value
                : props.clientService.service;

        feeNotice.value = {
            from: form.fee,
            to: defaultFee,
            name: source ? titleOf(source) : '',
        };
        form.fee = defaultFee;
    },
);

const onFeeInput = () => {
    feeTouched.value = true;
    feeNotice.value = null;
};

// Editing: a fee below the money received turns the extra into a refund when saved. Worked out in whole
// satang read from the digits, so no float is involved; the server works out the refund itself.
const toSatang = (value: string): number | null => {
    const match = /^\s*(\d*)(?:\.(\d{0,2}))?\s*$/.exec(value);

    if (!match || (match[1] === '' && !match[2])) {
        return null;
    }

    return (
        Number(match[1] || '0') * 100 + Number((match[2] ?? '').padEnd(2, '0'))
    );
};

const fromSatang = (satang: number) =>
    `${Math.floor(satang / 100)}.${String(satang % 100).padStart(2, '0')}`;

const refundPreview = computed(() => {
    if (
        !isEditing.value ||
        isLocked.value ||
        props.received === undefined ||
        String(form.fee).trim() === ''
    ) {
        return null;
    }

    const received = toSatang(props.received);
    const fee = toSatang(String(form.fee));

    if (received === null || fee === null || received <= fee) {
        return null;
    }

    return fromSatang(received - fee);
});

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base leading-relaxed shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm';

const submit = () => {
    // The status of a Job Search service is not part of this form: the server refuses it.
    form.transform((data) => {
        if (!isJobSearch.value) {
            return data;
        }

        const { status, ...rest } = data;
        void status;

        return rest;
    });

    const target = props.clientService
        ? update({
              client: props.client.id,
              clientService: props.clientService.id,
          })
        : store(props.client.id);

    form.submit(target.method, target.url);
};
</script>

<template>
    <form class="max-w-3xl space-y-6" @submit.prevent="submit">
        <section
            class="space-y-6 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <p
                v-if="isLocked"
                class="rounded-xl border bg-muted/50 p-3 text-sm text-muted-foreground"
                data-test="cancelled-notice"
            >
                {{ t('clientServices.form.cancelledNotice') }}
            </p>
            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="service_id">{{
                        t('clientServices.form.service')
                    }}</Label>
                    <p
                        v-if="clientService?.service"
                        id="service_id"
                        class="flex min-h-9 items-center text-sm font-medium text-brand-dark"
                    >
                        {{ titleOf(clientService.service) }}
                    </p>
                    <template v-else>
                        <Select v-model="serviceValue">
                            <SelectTrigger
                                id="service_id"
                                class="w-full"
                                :aria-invalid="!!form.errors.service_id"
                            >
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'clientServices.form.servicePlaceholder',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in serviceOptions"
                                    :key="option.id"
                                    :value="String(option.id)"
                                >
                                    {{ titleOf(option) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.service_id" />
                    </template>
                    <p v-if="isEditing" class="text-xs text-muted-foreground">
                        {{ t('clientServices.form.serviceLocked') }}
                    </p>
                </div>

                <div v-if="subOptions.length" class="grid gap-2">
                    <Label for="sub_service_id">{{
                        t('clientServices.form.subService')
                    }}</Label>
                    <Select v-model="subServiceValue" :disabled="isLocked">
                        <SelectTrigger
                            id="sub_service_id"
                            class="w-full"
                            :aria-invalid="!!form.errors.sub_service_id"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">
                                {{ t('clientServices.mainOnly') }}
                            </SelectItem>
                            <SelectItem
                                v-for="option in subOptions"
                                :key="option.id"
                                :value="String(option.id)"
                            >
                                {{ titleOf(option) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.sub_service_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="status">{{
                        t('clientServices.form.status')
                    }}</Label>
                    <StatusBadge
                        v-if="isLocked"
                        kind="serviceStatus"
                        status="cancel"
                        class="w-fit"
                    />
                    <template v-else-if="isJobSearch && clientService">
                        <StatusBadge
                            kind="serviceStatus"
                            :status="serviceStatusKey(clientService.status)"
                            class="w-fit"
                            data-test="job-search-status-readonly"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('clientServices.form.jobSearchStatusNote') }}
                        </p>
                    </template>
                    <Select v-else v-model="form.status">
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
                                {{
                                    t(
                                        `serviceStatus.${serviceStatusKey(status)}`,
                                    )
                                }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.status" />
                </div>

                <div class="grid gap-2">
                    <Label for="fee">{{ t('clientServices.form.fee') }}</Label>
                    <Input
                        id="fee"
                        :disabled="isLocked || isCompleteJobSearch"
                        v-model="form.fee"
                        name="fee"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        :aria-invalid="!!form.errors.fee"
                        @input="onFeeInput"
                    />
                    <p
                        v-if="feeNotice"
                        class="rounded-xl border border-brand/30 bg-brand/10 px-3 py-2 text-xs font-medium text-brand-dark"
                        role="status"
                        data-test="fee-changed-notice"
                    >
                        {{
                            t('clientServices.form.feeChanged', {
                                from: feeNotice.from
                                    ? formatMoney(feeNotice.from)
                                    : t('clientServices.notAvailable'),
                                to: formatMoney(feeNotice.to),
                                name: feeNotice.name,
                            })
                        }}
                    </p>
                    <p
                        v-if="refundPreview && received"
                        class="rounded-xl border border-warning-foreground/20 bg-warning px-3 py-2 text-xs font-medium text-warning-foreground"
                        role="alert"
                        data-test="refund-warning"
                    >
                        {{
                            t('clientServices.form.refundWarning', {
                                received: formatMoney(received),
                                refund: formatMoney(refundPreview),
                            })
                        }}
                    </p>
                    <p
                        v-if="isCompleteJobSearch"
                        class="text-xs text-muted-foreground"
                        data-test="complete-fee-locked"
                    >
                        {{ t('clientServices.form.completeFeeLocked') }}
                    </p>
                    <p v-if="!isEditing" class="text-xs text-muted-foreground">
                        {{ t('clientServices.form.feeHint') }}
                    </p>
                    <InputError :message="form.errors.fee" />
                </div>

                <div v-if="!isEditing" class="grid gap-2">
                    <Label for="received_now">{{
                        t('clientServices.form.receivedNow')
                    }}</Label>
                    <Input
                        id="received_now"
                        v-model="form.received_now"
                        name="received_now"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        :aria-invalid="!!form.errors.received_now"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('clientServices.form.receivedNowHint') }}
                    </p>
                    <InputError :message="form.errors.received_now" />
                </div>

                <div class="grid gap-2">
                    <Label for="start_date">{{
                        t('clientServices.form.startDate')
                    }}</Label>
                    <Input
                        id="start_date"
                        :disabled="isLocked"
                        v-model="form.start_date"
                        name="start_date"
                        type="date"
                        :aria-invalid="!!form.errors.start_date"
                    />
                    <InputError :message="form.errors.start_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="end_date">{{
                        t('clientServices.form.endDate')
                    }}</Label>
                    <Input
                        id="end_date"
                        :disabled="isLocked"
                        v-model="form.end_date"
                        name="end_date"
                        type="date"
                        :aria-invalid="!!form.errors.end_date"
                    />
                    <InputError :message="form.errors.end_date" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="remark">{{
                    isEditing
                        ? t('clientServices.form.addRemark')
                        : t('clientServices.form.remark')
                }}</Label>
                <textarea
                    id="remark"
                    v-model="form.remark"
                    name="remark"
                    rows="3"
                    :disabled="isCompleteJobSearch"
                    :class="textareaClass"
                    :placeholder="t('clients.form.remarksPlaceholder')"
                    :aria-invalid="!!form.errors.remark"
                />
                <p
                    v-if="isCompleteJobSearch"
                    class="text-xs text-muted-foreground"
                    data-test="complete-remark-locked"
                >
                    {{ t('clientServices.form.completeRemarkLocked') }}
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    {{ t('clientServices.form.remarkHint') }}
                </p>
                <InputError :message="form.errors.remark" />
                <RemarkList
                    v-if="clientService?.remarks?.length"
                    class="mt-2 border-t text-sm"
                    :remarks="clientService.remarks"
                />
            </div>
        </section>

        <div
            class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end"
        >
            <Button as-child variant="pillOutline" class="w-full sm:w-auto">
                <Link :href="show(client.id)">
                    <X class="size-4" />
                    {{ t('common.cancel') }}
                </Link>
            </Button>
            <Button
                type="submit"
                variant="pill"
                class="w-full sm:w-auto"
                :disabled="form.processing"
                data-test="save-client-service-button"
            >
                <Save class="size-4" />
                {{ t('common.save') }}
            </Button>
        </div>
    </form>
</template>
