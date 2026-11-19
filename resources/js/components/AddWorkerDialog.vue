<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { Search, TriangleAlert, UserPlus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    lookup as lookupRoute,
    store,
} from '@/actions/App/Http/Controllers/Admin/JobOrderWorkerController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format';
import type { WorkerLookup } from '@/types';

const props = defineProps<{
    jobOrderId: number;
    companyName: string;
    /** The Job Search default fee, a decimal string, or null when none is set. */
    defaultFee: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();

const search = useHttp<{ phone: string }, WorkerLookup>({ phone: '' });
const result = ref<WorkerLookup | null>(null);

const form = useForm({
    client_id: null as number | null,
    name: '',
    phone: '',
    birthday: '',
    gender: '',
    fee: '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        search.reset();
        search.clearErrors();
        result.value = null;
        form.reset();
        form.clearErrors();
        form.fee = props.defaultFee ?? '';
    }
});

// A different phone number is a different person: the earlier result no longer applies.
watch(
    () => search.phone,
    () => {
        result.value = null;
        form.client_id = null;
    },
);

const find = async () => {
    search.clearErrors();

    try {
        const response = await search.post(lookupRoute.url(props.jobOrderId), {
            // Too many lookups in a minute (the server's rate limit): say so under the field instead of failing silently.
            onHttpException: (failed) => {
                if (failed.status === 429) {
                    search.setError(
                        'phone',
                        t('jobOrders.workers.tooManyLookups'),
                    );
                }
            },
        });

        result.value = response;
        form.phone = response.phone;
        form.client_id = response.client?.id ?? null;
        // A reused record keeps its own fee unless the admin changes it; otherwise the default fee is offered.
        form.fee =
            response.engagement?.state === 'reusable'
                ? (response.engagement.fee ?? '')
                : (props.defaultFee ?? '');
        form.clearErrors();
    } catch {
        // A phone number that is not valid, or too many lookups, shows as an error under the field.
    }
};

// A person with an open application, or a record in an unexpected state, cannot be added.
const isBlocked = computed(
    () =>
        result.value?.engagement?.state === 'open_application' ||
        result.value?.engagement?.state === 'inconsistent',
);

const submit = () => {
    form.transform((data) =>
        data.client_id
            ? { client_id: data.client_id, fee: data.fee }
            : {
                  name: data.name,
                  phone: data.phone,
                  birthday: data.birthday,
                  gender: data.gender,
                  fee: data.fee,
              },
    ).post(store.url(props.jobOrderId), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form class="grid gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ t('jobOrders.workers.add') }}</DialogTitle>
                    <DialogDescription>
                        {{ companyName }} ·
                        {{ t('jobOrders.workers.searchFirst') }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="worker-phone">{{
                        t('jobOrders.workers.phone')
                    }}</Label>
                    <div class="flex gap-2">
                        <Input
                            id="worker-phone"
                            v-model="search.phone"
                            type="tel"
                            autocomplete="off"
                            placeholder="+66 81 234 5678"
                            :aria-invalid="!!search.errors.phone"
                            data-test="worker-phone-input"
                            @keydown.enter.prevent="find"
                        />
                        <Button
                            type="button"
                            variant="pillOutline"
                            :disabled="search.processing || !search.phone"
                            data-test="worker-search-button"
                            @click="find"
                        >
                            <Search class="size-4" />
                            {{ t('common.search') }}
                        </Button>
                    </div>
                    <InputError :message="search.errors.phone" />
                </div>

                <template v-if="result">
                    <p
                        v-if="result.on_this_order"
                        class="flex items-start gap-2 rounded-xl bg-warning px-3 py-2 text-sm text-warning-foreground"
                        role="status"
                        data-test="worker-already-added"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        {{ t('jobOrders.workers.alreadyOnOrder') }}
                    </p>

                    <template v-else>
                        <p
                            v-if="isBlocked"
                            class="flex items-start gap-2 rounded-xl bg-warning px-3 py-2 text-sm text-warning-foreground"
                            role="status"
                            data-test="worker-engagement-blocked"
                        >
                            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                            {{
                                result.engagement?.state === 'open_application'
                                    ? t('jobOrders.workers.openApplication')
                                    : t('jobOrders.workers.inconsistentRecord')
                            }}
                        </p>

                        <div
                            v-if="result.client"
                            class="rounded-xl border bg-muted/40 p-3 text-sm"
                            data-test="worker-existing-person"
                        >
                            <p class="font-semibold text-brand-dark">
                                {{ result.client.name }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ result.client.phone }} ·
                                {{ formatDate(result.client.birthday) }} ·
                                {{
                                    t(`clients.gender.${result.client.gender}`)
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ t('jobOrders.workers.existingPerson') }}
                            </p>
                            <p
                                v-if="result.engagement?.state === 'reusable'"
                                class="mt-1 text-xs text-muted-foreground"
                                data-test="worker-reuse-record"
                            >
                                {{ t('jobOrders.workers.reuseRecord') }}
                            </p>
                        </div>

                        <div
                            v-else
                            class="grid gap-4"
                            data-test="worker-new-person"
                        >
                            <p class="text-sm text-muted-foreground">
                                {{ t('jobOrders.workers.newPerson') }}
                            </p>
                            <div class="grid gap-2">
                                <Label for="worker-name"
                                    >{{ t('clients.form.name') }} *</Label
                                >
                                <Input
                                    id="worker-name"
                                    v-model="form.name"
                                    autocomplete="off"
                                    :aria-invalid="!!form.errors.name"
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="worker-birthday"
                                    >{{ t('clients.form.birthday') }} *</Label
                                >
                                <Input
                                    id="worker-birthday"
                                    v-model="form.birthday"
                                    type="date"
                                    :aria-invalid="!!form.errors.birthday"
                                />
                                <InputError :message="form.errors.birthday" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="worker-gender"
                                    >{{ t('clients.form.gender') }} *</Label
                                >
                                <Select v-model="form.gender">
                                    <SelectTrigger
                                        id="worker-gender"
                                        class="w-full"
                                        :aria-invalid="!!form.errors.gender"
                                    >
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'clients.form.genderPlaceholder',
                                                )
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="male">{{
                                            t('clients.gender.male')
                                        }}</SelectItem>
                                        <SelectItem value="female">{{
                                            t('clients.gender.female')
                                        }}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.gender" />
                            </div>
                            <InputError :message="form.errors.phone" />
                        </div>

                        <div v-if="!isBlocked" class="grid gap-2">
                            <Label for="worker-fee"
                                >{{ t('jobOrders.workers.fee') }} *</Label
                            >
                            <Input
                                id="worker-fee"
                                v-model="form.fee"
                                inputmode="decimal"
                                autocomplete="off"
                                :aria-invalid="!!form.errors.fee"
                                data-test="worker-fee-input"
                            />
                            <InputError :message="form.errors.fee" />
                        </div>
                        <InputError :message="form.errors.client_id" />
                    </template>
                </template>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="pillOutline"
                        @click="open = false"
                    >
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="pill"
                        :disabled="
                            form.processing ||
                            !result ||
                            result.on_this_order ||
                            isBlocked
                        "
                        data-test="save-worker-button"
                    >
                        <UserPlus class="size-4" />
                        {{ t('jobOrders.workers.add') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
