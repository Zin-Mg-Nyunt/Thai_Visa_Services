<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, Copy, Download, QrCode } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import QRCode from 'qrcode';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { renderTrackingCard } from '@/lib/trackingCard';

const props = defineProps<{
    clientName: string;
    url: string;
}>();

const { t } = useI18n();
const siteName = usePage().props.name;

const { copy, copied } = useClipboard({ copiedDuring: 2000 });

const isOpen = ref(false);
const qrDataUrl = ref('');

const generateQr = async () => {
    qrDataUrl.value = await QRCode.toDataURL(props.url, {
        width: 512,
        margin: 2,
    });
    isOpen.value = true;
};

const headerRef = ref<HTMLElement | null>(null);

const downloadCard = async () => {
    const blob = await renderTrackingCard({
        siteName: siteName as string,
        clientName: props.clientName,
        hint: t('clients.tracking.scanHint'),
        qrDataUrl: qrDataUrl.value,
        logo: headerRef.value?.querySelector('svg') ?? null,
    });

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${props.clientName}-card.png`;
    link.click();
    URL.revokeObjectURL(link.href);
};
</script>

<template>
    <Button
        type="button"
        variant="pillOutline"
        :aria-label="t('clients.tracking.generateQr')"
        :title="t('clients.tracking.generateQr')"
        data-test="generate-qr-button"
        @click="generateQr"
    >
        <QrCode class="size-4" />
        <span class="hidden sm:inline">{{
            t('clients.tracking.generateQr')
        }}</span>
    </Button>

    <Dialog v-model:open="isOpen">
        <DialogContent
            class="max-w-[calc(100%-3rem)] gap-0 overflow-y-auto border-0 p-0 *:data-[slot=dialog-close]:text-white sm:max-w-sm sm:p-0 [&::-webkit-scrollbar]:hidden"
            style="scrollbar-width: none"
            data-test="tracking-qr-dialog"
        >
            <DialogTitle class="sr-only">{{
                t('clients.tracking.title')
            }}</DialogTitle>
            <DialogDescription class="sr-only">{{
                t('clients.tracking.description')
            }}</DialogDescription>

            <div
                ref="headerRef"
                class="bg-brand-dark px-4 pt-4 pb-12 sm:px-5 sm:pt-5 sm:pb-14"
            >
                <div class="flex items-center gap-3 pr-6">
                    <AppLogoIcon
                        variant="white"
                        class="size-8 rounded-lg sm:size-9"
                    />
                    <span class="truncate text-base font-bold text-white">{{
                        siteName
                    }}</span>
                </div>
            </div>

            <div
                class="-mt-8 flex flex-col items-center px-4 text-center sm:-mt-10 sm:px-5"
                data-test="tracking-client"
            >
                <div
                    class="flex size-16 items-center justify-center rounded-full bg-mint text-2xl font-bold text-brand-dark ring-4 ring-background sm:size-20 sm:text-3xl"
                    aria-hidden="true"
                >
                    {{ clientName.charAt(0).toUpperCase() }}
                </div>
                <p
                    class="mt-2 max-w-full text-lg font-bold wrap-break-word text-brand-dark sm:mt-3 sm:text-xl"
                >
                    {{ clientName }}
                </p>
            </div>

            <div class="px-4 pt-4 sm:px-5 sm:pt-5">
                <div class="rounded-2xl bg-mint p-2 sm:p-3">
                    <img
                        :src="qrDataUrl"
                        :alt="t('clients.tracking.qrAlt')"
                        class="mx-auto aspect-square w-full max-w-40 rounded-xl bg-white p-2 sm:max-w-52"
                        data-test="tracking-qr"
                    />
                </div>
                <p
                    class="mt-2 text-center text-[11px] text-muted-foreground sm:mt-3 sm:text-xs"
                >
                    {{ t('clients.tracking.scanHint') }}
                </p>
            </div>

            <div class="grid gap-2 p-4 sm:gap-3 sm:p-5">
                <div class="flex gap-2">
                    <Input
                        :model-value="url"
                        readonly
                        class="rounded-full text-xs"
                        :aria-label="t('clients.tracking.linkLabel')"
                        data-test="tracking-url"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button
                        type="button"
                        variant="pillOutline"
                        :aria-label="t('clients.tracking.copy')"
                        :title="t('clients.tracking.copy')"
                        data-test="copy-tracking-link"
                        @click="copy(url)"
                    >
                        <component :is="copied ? Check : Copy" class="size-4" />
                        <span class="hidden sm:inline">{{
                            copied
                                ? t('clients.tracking.copied')
                                : t('clients.tracking.copy')
                        }}</span>
                    </Button>
                </div>
                <Button
                    type="button"
                    variant="pill"
                    class="w-full"
                    data-test="download-card"
                    @click="downloadCard"
                >
                    <Download class="size-4" />
                    {{ t('clients.tracking.downloadCard') }}
                </Button>
            </div>

            <div class="h-1.5 bg-brand" aria-hidden="true" />
        </DialogContent>
    </Dialog>
</template>
