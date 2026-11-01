<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
</script>

<template>
    <div class="flex min-h-svh flex-col bg-brand-dark px-4 py-4">
        <div class="flex justify-end">
            <LanguageSwitcher tone="dark" />
        </div>

        <main
            class="mx-auto flex w-full max-w-sm flex-1 flex-col items-center gap-6 pt-8 pb-12 sm:justify-center sm:pt-0"
        >
            <AppLogoIcon variant="white" class="size-20 rounded-2xl" />
            <slot />
        </main>
    </div>
</template>
