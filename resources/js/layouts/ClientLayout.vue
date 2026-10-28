<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
</script>

<template>
    <div class="min-h-svh bg-background">
        <header class="bg-brand-dark pb-20">
            <div
                class="mx-auto flex max-w-2xl items-center justify-between px-4 py-4"
            >
                <AppLogoIcon variant="white" class="size-10 rounded-lg" />
                <LanguageSwitcher tone="dark" />
            </div>
        </header>

        <main class="mx-auto -mt-16 max-w-2xl px-4 pb-10">
            <slot />
        </main>
    </div>
</template>
