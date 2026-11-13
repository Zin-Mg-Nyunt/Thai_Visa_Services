<script setup lang="ts">
import { UserPlus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AddWorkerDialog from '@/components/AddWorkerDialog.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { paymentStatusKey } from '@/lib/status';
import type { JobOrder, JobOrderWorker } from '@/types';

defineProps<{
    jobOrder: JobOrder;
    workers: JobOrderWorker[];
    defaultFee: string | null;
}>();

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
                    <div class="min-w-0">
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
                        >
                            {{ t(`workerCaseStatus.${worker.case_status}`) }}
                        </span>
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

    <AddWorkerDialog
        v-if="jobOrder.can_edit"
        v-model:open="isAddOpen"
        :job-order-id="jobOrder.id"
        :company-name="jobOrder.company_name"
        :default-fee="defaultFee"
    />
</template>
