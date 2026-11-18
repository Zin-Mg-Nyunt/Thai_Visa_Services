<script setup lang="ts">
import { UserX } from '@lucide/vue';
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
    /** The hired person whose application is about to be cancelled. */
    name: string;
    processing: boolean;
}>();

const emit = defineEmits<{ confirm: [] }>();

const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="cancel-hired-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    t('jobOrders.workers.cancelHired.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ t('jobOrders.workers.cancelHired.intro', { name }) }}
                </DialogDescription>
            </DialogHeader>

            <ul class="list-disc space-y-1 pl-5 text-sm">
                <li>{{ t('jobOrders.workers.cancelHired.application') }}</li>
                <li>{{ t('jobOrders.workers.cancelHired.service') }}</li>
                <li>{{ t('jobOrders.workers.cancelHired.reassign') }}</li>
                <li>{{ t('jobOrders.workers.cancelHired.money') }}</li>
                <li>{{ t('jobOrders.workers.cancelHired.unassigned') }}</li>
                <li class="font-semibold">
                    {{ t('jobOrders.workers.cancelHired.closed') }}
                </li>
            </ul>

            <DialogFooter>
                <Button
                    type="button"
                    variant="pillOutline"
                    data-test="dismiss-cancel-hired-button"
                    @click="open = false"
                >
                    {{ t('common.cancel') }}
                </Button>
                <Button
                    type="button"
                    variant="pill"
                    :disabled="processing"
                    data-test="confirm-cancel-hired-button"
                    @click="emit('confirm')"
                >
                    <UserX class="size-4" />
                    {{ t('jobOrders.workers.cancelHired.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
