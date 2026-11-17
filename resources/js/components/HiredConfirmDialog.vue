<script setup lang="ts">
import { UserCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineProps<{
    /** How many applications are about to become Hired. */
    count: number;
    processing: boolean;
}>();

const emit = defineEmits<{ confirm: [] }>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="hired-confirm-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    t('jobOrders.workers.hiredConfirm.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ t('jobOrders.workers.hiredConfirm.intro', { count }) }}
                </DialogDescription>
            </DialogHeader>

            <ul class="list-disc space-y-1 pl-5 text-sm">
                <li>{{ t('jobOrders.workers.hiredConfirm.complete') }}</li>
                <li>{{ t('jobOrders.workers.hiredConfirm.noReassign') }}</li>
                <li class="font-semibold">
                    {{ t('jobOrders.workers.hiredConfirm.irreversible') }}
                </li>
            </ul>

            <DialogFooter>
                <Button
                    type="button"
                    variant="pillOutline"
                    @click="open = false"
                >
                    {{ t('common.cancel') }}
                </Button>
                <Button
                    type="button"
                    variant="pill"
                    :disabled="processing"
                    data-test="confirm-hired-button"
                    @click="emit('confirm')"
                >
                    <UserCheck class="size-4" />
                    {{ t('jobOrders.workers.hiredConfirm.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
