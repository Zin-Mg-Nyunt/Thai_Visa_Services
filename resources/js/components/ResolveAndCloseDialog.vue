<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { close } from '@/actions/App/Http/Controllers/Admin/JobOrderController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { OpenApplication } from '@/types';

const props = defineProps<{
    jobOrderId: number;
    companyName: string;
    status: 'done' | 'cancelled';
    /** The open applications of people who are not deleted: every one needs a decision before the order can close. */
    openApplications: OpenApplication[];
    /** Open applications of deleted people: the close cancels them by itself. */
    deletedOpen: number[];
    /** Set on the edit page: a closed order's edit page is refused, so the server sends the admin to the list. */
    fromEdit?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();

type Resolution = 'hired' | 'rejected' | 'cancelled';
const resolutions: Resolution[] = ['hired', 'rejected', 'cancelled'];

const step = ref<'choose' | 'review'>('choose');
const choice = ref<Record<number, Resolution | undefined>>({});
const selected = ref<number[]>([]);
const bulkTarget = ref('');
const understood = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        step.value = 'choose';
        choice.value = {};
        selected.value = [];
        bulkTarget.value = '';
        understood.value = false;
        form.reset();
        form.clearErrors();
    }
});

const allChosen = computed(() =>
    props.openApplications.every((row) => choice.value[row.worker_id]),
);

const hiredCount = computed(
    () =>
        props.openApplications.filter(
            (row) => choice.value[row.worker_id] === 'hired',
        ).length,
);

const count = (status: Resolution) =>
    props.openApplications.filter(
        (row) => choice.value[row.worker_id] === status,
    ).length;

const allSelected = computed(
    () => selected.value.length === props.openApplications.length,
);

const toggle = (id: number, on: boolean) => {
    selected.value = on
        ? [...selected.value, id]
        : selected.value.filter((other) => other !== id);
};

const applyBulk = () => {
    for (const id of selected.value) {
        choice.value[id] = bulkTarget.value as Resolution;
    }

    selected.value = [];
    bulkTarget.value = '';
};

const form = useForm({
    status: props.status,
    from: undefined as string | undefined,
    resolutions: [] as { worker_id: number; status: string }[],
    confirm_hired: false,
    expected: {
        open: [] as { worker_id: number; status: string }[],
        deleted_open: [] as number[],
    },
});

/**
 * One request does it all on the server, in one transaction: the deleted people's open applications are cancelled, these
 * decisions are applied and the order closes. Hired is only sent with the explicit confirmation given on the review step.
 * If anything changed since this dialog opened, the server rolls everything back and the admin starts again.
 */
const confirm = () => {
    form.status = props.status;
    form.from = props.fromEdit ? 'edit' : undefined;
    form.resolutions = props.openApplications.map((row) => ({
        worker_id: row.worker_id,
        status: choice.value[row.worker_id] as string,
    }));
    form.confirm_hired = hiredCount.value > 0 && understood.value;
    form.expected = {
        open: props.openApplications.map((row) => ({
            worker_id: row.worker_id,
            status: row.status,
        })),
        deleted_open: props.deletedOpen,
    };

    form.post(close.url(props.jobOrderId), {
        preserveScroll: true,
        onFinish: () => (open.value = false),
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto sm:max-w-xl"
            data-test="resolve-close-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{
                    t(`jobOrders.close.${status}.title`)
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        step === 'choose'
                            ? t('jobOrders.close.resolve.intro', {
                                  company: companyName,
                              })
                            : t('jobOrders.close.resolve.reviewIntro')
                    }}
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="deletedOpen.length"
                class="rounded-xl bg-warning px-3 py-2 text-sm text-warning-foreground"
                data-test="close-deleted-note"
            >
                {{
                    t('jobOrders.close.deletedNote', {
                        count: deletedOpen.length,
                    })
                }}
            </p>

            <template v-if="step === 'choose'">
                <div
                    v-if="selected.length"
                    class="flex flex-wrap items-center gap-2 rounded-xl bg-muted/50 p-2 text-sm"
                    data-test="resolve-bulk-bar"
                >
                    <span class="font-medium">{{
                        t('jobOrders.workers.selected', {
                            count: selected.length,
                        })
                    }}</span>
                    <Select v-model="bulkTarget">
                        <SelectTrigger
                            class="h-8 w-44"
                            data-test="resolve-bulk-select"
                        >
                            <SelectValue
                                :placeholder="
                                    t('jobOrders.workers.setStatusTo')
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in resolutions"
                                :key="option"
                                :value="option"
                            >
                                {{ t(`workerCaseStatus.${option}`) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        type="button"
                        size="sm"
                        variant="pill"
                        :disabled="!bulkTarget"
                        data-test="resolve-bulk-apply"
                        @click="applyBulk"
                    >
                        {{ t('jobOrders.workers.apply') }}
                    </Button>
                </div>

                <label
                    v-if="openApplications.length > 1"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Checkbox
                        :model-value="allSelected"
                        data-test="resolve-select-all"
                        @update:model-value="
                            (on) =>
                                (selected =
                                    on === true
                                        ? openApplications.map(
                                              (row) => row.worker_id,
                                          )
                                        : [])
                        "
                    />
                    {{ t('jobOrders.workers.selectAll') }}
                </label>

                <ul class="divide-y" data-test="resolve-list">
                    <li
                        v-for="row in openApplications"
                        :key="row.worker_id"
                        class="flex flex-wrap items-center gap-2 py-2 text-sm"
                        data-test="resolve-row"
                    >
                        <Checkbox
                            :model-value="selected.includes(row.worker_id)"
                            :aria-label="row.name"
                            data-test="resolve-select"
                            @update:model-value="
                                (on) => toggle(row.worker_id, on === true)
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium wrap-break-word">
                                {{ row.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.phone }} ·
                                {{ t(`workerCaseStatus.${row.status}`) }}
                            </p>
                        </div>
                        <Select
                            :model-value="choice[row.worker_id] ?? ''"
                            @update:model-value="
                                (value) =>
                                    (choice[row.worker_id] =
                                        value as Resolution)
                            "
                        >
                            <SelectTrigger
                                class="h-8 w-40"
                                data-test="resolve-choice"
                            >
                                <SelectValue
                                    :placeholder="
                                        t('jobOrders.close.resolve.choose')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in resolutions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ t(`workerCaseStatus.${option}`) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </li>
                </ul>
            </template>

            <template v-else>
                <ul class="space-y-1 text-sm" data-test="resolve-review">
                    <li
                        v-for="row in openApplications"
                        :key="row.worker_id"
                        class="flex justify-between gap-2"
                    >
                        <span class="wrap-break-word">{{ row.name }}</span>
                        <span class="font-medium">
                            {{ t(`workerCaseStatus.${row.status}`) }} →
                            {{ t(`workerCaseStatus.${choice[row.worker_id]}`) }}
                        </span>
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('jobOrders.close.resolve.summary', {
                            hired: count('hired'),
                            rejected: count('rejected'),
                            cancelled: count('cancelled'),
                        })
                    }}
                </p>

                <div
                    v-if="hiredCount"
                    class="space-y-2 rounded-xl border p-3 text-sm"
                    data-test="resolve-hired-warning"
                >
                    <ul class="list-disc space-y-1 pl-5">
                        <li>
                            {{ t('jobOrders.workers.hiredConfirm.complete') }}
                        </li>
                        <li>
                            {{ t('jobOrders.workers.hiredConfirm.noReassign') }}
                        </li>
                        <li class="font-semibold">
                            {{
                                t('jobOrders.workers.hiredConfirm.irreversible')
                            }}
                        </li>
                    </ul>
                    <label class="flex items-start gap-2">
                        <Checkbox
                            v-model="understood"
                            class="mt-0.5"
                            data-test="resolve-understood"
                        />
                        <span>{{
                            t('jobOrders.close.resolve.understood', {
                                count: hiredCount,
                            })
                        }}</span>
                    </label>
                </div>

                <p class="text-sm text-muted-foreground">
                    {{ t('jobOrders.close.final') }}
                </p>
                <InputError :message="form.errors.confirm_hired" />
            </template>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="pillOutline"
                    @click="
                        step === 'review' ? (step = 'choose') : (open = false)
                    "
                >
                    {{
                        step === 'review'
                            ? t('common.back')
                            : t('common.cancel')
                    }}
                </Button>
                <Button
                    v-if="step === 'choose'"
                    type="button"
                    variant="pill"
                    class="rounded-full px-6"
                    :disabled="!allChosen"
                    data-test="resolve-review-button"
                    @click="step = 'review'"
                >
                    {{ t('jobOrders.close.resolve.review') }}
                </Button>
                <Button
                    v-else
                    type="button"
                    :variant="status === 'done' ? 'pill' : 'destructive'"
                    class="rounded-full px-6"
                    :disabled="
                        form.processing || (hiredCount > 0 && !understood)
                    "
                    data-test="resolve-confirm-close-button"
                    @click="confirm"
                >
                    {{ t('jobOrders.close.resolve.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
