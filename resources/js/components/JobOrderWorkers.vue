<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    cancelHired,
    updateStatus,
} from '@/actions/App/Http/Controllers/Admin/JobOrderWorkerController';
import AddWorkerDialog from '@/components/AddWorkerDialog.vue';
import CancelHiredDialog from '@/components/CancelHiredDialog.vue';
import HiredConfirmDialog from '@/components/HiredConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/format';
import { paymentStatusKey } from '@/lib/status';
import type { JobOrder, JobOrderWorker, WorkerCaseStatusValue } from '@/types';

const props = defineProps<{
    jobOrder: JobOrder;
    workers: JobOrderWorker[];
    defaultFee: string | null;
}>();

/**
 * Where an application may go next. Applied and Interview move back and forth and can end as Hired, Rejected or Cancelled;
 * those three are final. The server checks the same table again under locks.
 */
const nextStatuses: Partial<
    Record<WorkerCaseStatusValue, WorkerCaseStatusValue[]>
> = {
    applied: ['interview', 'hired', 'rejected', 'cancelled'],
    interview: ['applied', 'hired', 'rejected', 'cancelled'],
};
const statusOrder: WorkerCaseStatusValue[] = [
    'applied',
    'interview',
    'hired',
    'rejected',
    'cancelled',
];

/** Only an open order, and an application that is not final, on a Job Search record that is still in progress. */
const canChange = (worker: JobOrderWorker) =>
    props.jobOrder.can_edit &&
    worker.service_status === 'in_progress' &&
    worker.case_status in nextStatuses;

const changeable = computed(() => props.workers.filter(canChange));
const selected = ref<number[]>([]);
const bulkTarget = ref('');

// An application that changed (or left the list) can no longer stay selected.
watch(changeable, (rows) => {
    const ids = new Set(rows.map((worker) => worker.id));
    selected.value = selected.value.filter((id) => ids.has(id));
});

const selectedRows = computed(() =>
    changeable.value.filter((worker) => selected.value.includes(worker.id)),
);

/** The statuses every selected application can move to. */
const bulkOptions = computed(() =>
    selectedRows.value.length === 0
        ? []
        : statusOrder.filter((status) =>
              selectedRows.value.every((worker) =>
                  nextStatuses[worker.case_status]?.includes(status),
              ),
          ),
);

watch(bulkOptions, (options) => {
    if (!options.includes(bulkTarget.value as WorkerCaseStatusValue)) {
        bulkTarget.value = '';
    }
});

const allSelected = computed(
    () =>
        changeable.value.length > 0 &&
        selected.value.length === changeable.value.length,
);

const toggle = (id: number, on: boolean) => {
    selected.value = on
        ? [...selected.value, id]
        : selected.value.filter((other) => other !== id);
};

const toggleAll = (on: boolean) => {
    selected.value = on ? changeable.value.map((worker) => worker.id) : [];
};

const form = useForm({
    changes: [] as { worker_id: number; status: string }[],
    confirm_hired: false,
});

const confirmOpen = ref(false);
const pending = ref<{ ids: number[]; status: string } | null>(null);

const send = (ids: number[], status: string, confirmHired: boolean) => {
    form.changes = ids.map((id) => ({ worker_id: id, status }));
    form.confirm_hired = confirmHired;
    form.patch(updateStatus.url(props.jobOrder.id), {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            bulkTarget.value = '';
        },
        onFinish: () => (confirmOpen.value = false),
    });
};

/** Hired is final and completes the Job Search record, so it is never sent without the admin's explicit confirmation. */
const change = (ids: number[], status: string) => {
    if (status === 'hired') {
        pending.value = { ids, status };
        confirmOpen.value = true;

        return;
    }

    send(ids, status, false);
};

// Hired to Cancelled is its own, individual action. It is offered on an open or a closed order, and only after the admin
// confirms: the server checks the confirmation too, and refuses (with a message) when it is not allowed.
const cancelHiredForm = useForm({ confirm_cancel_hired: false });
const cancelHiredTarget = ref<JobOrderWorker | null>(null);
const cancelHiredOpen = ref(false);

const askCancelHired = (worker: JobOrderWorker) => {
    cancelHiredTarget.value = worker;
    cancelHiredOpen.value = true;
};

const confirmCancelHired = () => {
    const worker = cancelHiredTarget.value;

    if (!worker) {
        return;
    }

    cancelHiredForm.confirm_cancel_hired = true;
    cancelHiredForm.patch(cancelHired.url([props.jobOrder.id, worker.id]), {
        preserveScroll: true,
        onFinish: () => {
            cancelHiredOpen.value = false;
            cancelHiredForm.confirm_cancel_hired = false;
        },
    });
};

const isCancelled = (worker: JobOrderWorker) =>
    worker.service_status === 'cancel';

const { t } = useI18n();

const isAddOpen = ref(false);
</script>

<template>
    <section
        class="mt-6 max-w-3xl space-y-4 rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        data-test="job-order-workers"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('jobOrders.workers.title', { count: workers.length }) }}
            </h2>
            <Button
                v-if="jobOrder.can_edit"
                variant="pill"
                size="sm"
                data-test="add-worker-button"
                @click="isAddOpen = true"
            >
                <UserPlus class="size-4" />
                {{ t('jobOrders.workers.add') }}
            </Button>
        </div>

        <InputError
            :message="
                form.errors.confirm_hired ??
                form.errors.changes ??
                cancelHiredForm.errors.confirm_cancel_hired
            "
        />

        <div
            v-if="selected.length"
            class="flex flex-wrap items-center gap-2 rounded-xl bg-muted/50 p-2 text-sm"
            data-test="bulk-bar"
        >
            <span class="font-medium">{{
                t('jobOrders.workers.selected', { count: selected.length })
            }}</span>
            <Select v-model="bulkTarget">
                <SelectTrigger class="h-8 w-44" data-test="bulk-status-select">
                    <SelectValue
                        :placeholder="t('jobOrders.workers.setStatusTo')"
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="status in bulkOptions"
                        :key="status"
                        :value="status"
                        :data-test="`bulk-option-${status}`"
                    >
                        {{ t(`workerCaseStatus.${status}`) }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button
                type="button"
                size="sm"
                variant="pill"
                :disabled="!bulkTarget || form.processing"
                data-test="bulk-status-apply"
                @click="change(selected, bulkTarget)"
            >
                {{ t('jobOrders.workers.apply') }}
            </Button>
        </div>

        <label
            v-if="changeable.length > 1"
            class="flex items-center gap-2 text-xs text-muted-foreground"
        >
            <Checkbox
                :model-value="allSelected"
                data-test="select-all-workers"
                @update:model-value="(on) => toggleAll(on === true)"
            />
            {{ t('jobOrders.workers.selectAll') }}
        </label>

        <ul v-if="workers.length" class="divide-y">
            <li
                v-for="worker in workers"
                :key="worker.id"
                class="flex flex-col gap-2 py-3 text-sm"
                :class="{ 'opacity-70': isCancelled(worker) }"
                :data-cancelled="isCancelled(worker)"
                data-test="worker-row"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <Checkbox
                        v-if="canChange(worker)"
                        class="mt-1"
                        :model-value="selected.includes(worker.id)"
                        :aria-label="worker.name"
                        data-test="worker-select"
                        @update:model-value="
                            (on) => toggle(worker.id, on === true)
                        "
                    />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium wrap-break-word text-brand-dark">
                            {{ worker.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ worker.phone }} ·
                            {{ t(`clients.gender.${worker.gender}`) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            v-if="isCancelled(worker)"
                            class="rounded-full bg-destructive/10 px-2.5 py-0.5 text-xs font-medium text-destructive"
                            data-test="worker-cancelled-badge"
                        >
                            {{ t('jobOrders.workers.cancelled') }}
                        </span>
                        <span
                            class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                            data-test="worker-case-status"
                        >
                            {{ t(`workerCaseStatus.${worker.case_status}`) }}
                        </span>
                        <Button
                            v-if="worker.can_cancel_hired"
                            type="button"
                            size="sm"
                            variant="pillOutline"
                            class="h-7 text-xs"
                            data-test="cancel-hired-button"
                            @click="askCancelHired(worker)"
                        >
                            {{ t('jobOrders.workers.cancelHired.button') }}
                        </Button>
                        <Select
                            v-if="canChange(worker)"
                            :key="`${worker.id}-${worker.case_status}`"
                            model-value=""
                            :disabled="form.processing"
                            @update:model-value="
                                (status) => change([worker.id], String(status))
                            "
                        >
                            <SelectTrigger
                                class="h-7 w-36 text-xs"
                                data-test="worker-status-select"
                            >
                                <SelectValue
                                    :placeholder="
                                        t('jobOrders.workers.changeStatus')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="status in nextStatuses[
                                        worker.case_status
                                    ]"
                                    :key="status"
                                    :value="status"
                                    :data-test="`status-option-${status}`"
                                >
                                    {{ t(`workerCaseStatus.${status}`) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span>
                        <span class="text-muted-foreground"
                            >{{ t('jobOrders.workers.fee') }}:</span
                        >
                        {{ formatMoney(worker.fee) }}
                    </span>
                    <span>
                        <span class="text-muted-foreground"
                            >{{ t('jobOrders.workers.received') }}:</span
                        >
                        {{ formatMoney(worker.received) }}
                    </span>
                    <StatusBadge
                        kind="paymentStatus"
                        :status="paymentStatusKey(worker.payment_status)"
                    />
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            {{ t('jobOrders.workers.empty') }}
        </p>
    </section>

    <HiredConfirmDialog
        v-model:open="confirmOpen"
        :count="pending?.ids.length ?? 0"
        :processing="form.processing"
        @confirm="pending && send(pending.ids, pending.status, true)"
    />

    <CancelHiredDialog
        v-model:open="cancelHiredOpen"
        :name="cancelHiredTarget?.name ?? ''"
        :processing="cancelHiredForm.processing"
        @confirm="confirmCancelHired"
    />

    <AddWorkerDialog
        v-if="jobOrder.can_edit"
        v-model:open="isAddOpen"
        :job-order-id="jobOrder.id"
        :company-name="jobOrder.company_name"
        :default-fee="defaultFee"
    />
</template>
