<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
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

const props = defineProps<{
    title: string;
    description: string;
    processing?: boolean;
    /** When set, the admin must type this value before confirming. */
    requiredText?: string;
    requiredTextLabel?: string;
    error?: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    confirm: [typedText: string];
}>();

const { t } = useI18n();

const typedText = ref('');

const normalise = (value: string): string => value.replace(/[\s\-().]/g, '');

const isConfirmed = computed(
    () =>
        !props.requiredText ||
        normalise(typedText.value) === normalise(props.requiredText),
);

watch(open, () => {
    typedText.value = '';
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <div v-if="requiredText" class="grid gap-2">
                <Label for="confirm-text">{{ requiredTextLabel }}</Label>
                <Input
                    id="confirm-text"
                    v-model="typedText"
                    autocomplete="off"
                    data-test="confirm-dialog-input"
                    :aria-invalid="!!error"
                />
                <InputError :message="error" />
            </div>

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
                    variant="destructive"
                    class="rounded-full px-6"
                    :disabled="processing || !isConfirmed"
                    data-test="confirm-dialog-button"
                    @click="emit('confirm', typedText)"
                >
                    {{ t('common.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
