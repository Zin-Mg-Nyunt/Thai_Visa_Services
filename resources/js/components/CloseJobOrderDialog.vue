<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { close } from '@/actions/App/Http/Controllers/Admin/JobOrderController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    jobOrderId: number;
    companyName: string;
    status: 'done' | 'cancelled';
    /** Ids of the open applications of deleted people: the close cancels them, and the server checks they are still the same. */
    deletedOpen: number[];
    /** Set on the edit page: a closed order's edit page is refused, so the server sends the admin to the list. */
    fromEdit?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();

const processing = ref(false);

const confirm = () => {
    router.post(
        close.url(props.jobOrderId),
        {
            status: props.status,
            from: props.fromEdit ? 'edit' : undefined,
            // Nothing for the admin to resolve; this is what the screen showed.
            expected: { open: [], deleted_open: props.deletedOpen },
        },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (open.value = false),
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    t(`jobOrders.close.${status}.title`)
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(`jobOrders.close.${status}.description`, {
                            company: companyName,
                        })
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

            <p class="text-sm text-muted-foreground">
                {{ t('jobOrders.close.final') }}
            </p>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="pillOutline"
                    @click="open = false"
                >
                    {{ t('common.cancel') }}
                </Button>
                <Button
                    type="button"
                    :variant="status === 'done' ? 'pill' : 'destructive'"
                    class="rounded-full px-6"
                    :disabled="processing"
                    :data-test="`confirm-close-${status}-button`"
                    @click="confirm"
                >
                    {{ t(`jobOrders.actions.${status}`) }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
