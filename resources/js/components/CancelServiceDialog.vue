<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { cancel } from '@/actions/App/Http/Controllers/Admin/ClientServiceController';
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
    /** Money kept so far (received minus any refund from fee changes), a decimal string. The cancel refund cannot be more. */
    kept: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();

const form = useForm({
    cancel_refund_amount: '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

const submit = () => {
    const target = cancel({
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
                    <DialogTitle>{{
                        t('clientServices.cancel.title')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ serviceName }} ·
                        {{
                            t('clientServices.cancel.kept', {
                                amount: formatMoney(kept),
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>

                <p class="text-sm text-muted-foreground">
                    {{ t('clientServices.cancel.description') }}
                </p>

                <div class="grid gap-2">
                    <Label for="refund-amount">{{
                        t('clientServices.cancel.refund')
                    }}</Label>
                    <Input
                        id="refund-amount"
                        v-model="form.cancel_refund_amount"
                        type="number"
                        min="0"
                        :max="kept"
                        step="0.01"
                        inputmode="decimal"
                        placeholder="0"
                        :aria-invalid="!!form.errors.cancel_refund_amount"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t('clientServices.cancel.refundHint', {
                                amount: formatMoney(kept),
                            })
                        }}
                    </p>
                    <InputError :message="form.errors.cancel_refund_amount" />
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).status
                        "
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="pillOutline"
                        @click="open = false"
                    >
                        {{ t('clientServices.cancel.keep') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="pill"
                        class="bg-destructive text-white hover:bg-destructive/90"
                        :disabled="form.processing"
                        data-test="confirm-cancel-service-button"
                    >
                        <Ban class="size-4" />
                        {{ t('clientServices.cancel.confirm') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
