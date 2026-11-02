<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{
    name: string;
    photoUrl?: string | null;
}>();

const hasFailed = ref(false);

// A new link (after an upload) gets a fresh try, an expired one falls back to the initial.
watch(
    () => props.photoUrl,
    () => (hasFailed.value = false),
);
</script>

<template>
    <div
        class="relative flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-mint font-bold text-brand-dark"
        :aria-hidden="photoUrl && !hasFailed ? undefined : true"
    >
        <img
            v-if="photoUrl && !hasFailed"
            :src="photoUrl"
            :alt="name"
            class="size-full object-cover"
            data-test="client-photo"
            @error="hasFailed = true"
        />
        <template v-else>{{ name.charAt(0).toUpperCase() }}</template>
    </div>
</template>
