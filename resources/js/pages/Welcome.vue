<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';

const { t } = useI18n();
const name = usePage().props.name;
</script>

<template>
    <Head :title="name" />

    <section class="bg-brand-dark text-white">
        <div class="mx-auto max-w-6xl px-4 py-20 md:px-8 md:py-28">
            <h1 class="text-4xl font-bold md:text-5xl">{{ name }}</h1>
            <div class="mt-8 flex flex-wrap gap-3">
                <Button as-child class="rounded-full font-semibold">
                    <a href="#services">{{ t('landing.ourServices') }}</a>
                </Button>
                <Button
                    as-child
                    variant="outline"
                    class="rounded-full border-white bg-transparent font-semibold text-white hover:bg-white/10 hover:text-white"
                >
                    <a href="#contact">{{ t('landing.contactUs') }}</a>
                </Button>
            </div>
        </div>
    </section>
</template>
