<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** A labelled web address field with its error, for the website content form. */
defineProps<{
    id: string;
    label: string;
    error?: string;
}>();

const value = defineModel<string>({ required: true });
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <Input
            :id="id"
            v-model="value"
            type="url"
            inputmode="url"
            autocomplete="off"
            placeholder="https://"
            :aria-invalid="!!error"
        />
        <InputError :message="error" />
    </div>
</template>
