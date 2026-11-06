<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { store } from '@/actions/App/Http/Controllers/Admin/PaymentController';
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
import { formatMoney } from '@/lib/format';

const props = defineProps<{
    clientId: number;
    clientServiceId: number;
    serviceName: string;
    /** The balance still to pay, a decimal string. */
    remaining: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();

const today = () => new Date().toLocaleDateString('en-CA');

const form = useForm({
    amount: '',
    paid_at: today(),
    method: 'cash',
    note: '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        form.paid_at = today();
    }
});

const submit = () => {
    const target = store({
        client: props.clientId,
        clientService: props.clientServiceId,
    });

    form.submit(target.method, target.url, {
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
                    <DialogTitle>{{ t('payments.dialog.title') }}</DialogTitle>
                    <DialogDescription>
                        {{ serviceName }} ·
                        {{
                            t('payments.dialog.remaining', {
                                amount: formatMoney(remaining),
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="payment-amount">{{
                        t('payments.amount')
                    }}</Label>
                    <Input
                        id="payment-amount"
                        v-model="form.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        inputmode="decimal"
                        :aria-invalid="!!form.errors.amount"
                    />
                    <InputError :message="form.errors.amount" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="payment-date">{{
                            t('payments.date')
                        }}</Label>
                        <Input
                            id="payment-date"
                            v-model="form.paid_at"
                            type="date"
                            :max="today()"
                            :aria-invalid="!!form.errors.paid_at"
                        />
                        <InputError :message="form.errors.paid_at" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="payment-method">{{
                            t('payments.method')
                        }}</Label>
                        <Input
                            id="payment-method"
                            :model-value="t('payments.methods.cash')"
                            readonly
                        />
                        <InputError :message="form.errors.method" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="payment-note">{{ t('payments.note') }}</Label>
                    <Input
                        id="payment-note"
                        v-model="form.note"
                        :placeholder="t('payments.notePlaceholder')"
                        :aria-invalid="!!form.errors.note"
                    />
                    <InputError :message="form.errors.note" />
                </div>

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
                        :disabled="form.processing"
                        data-test="save-payment-button"
                    >
                        <Save class="size-4" />
                        {{ t('common.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
