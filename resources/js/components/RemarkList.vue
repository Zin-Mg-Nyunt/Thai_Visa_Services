<script setup lang="ts">
import { Ban, ReceiptText } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { ClientServiceRemark } from '@/types';

defineProps<{
    /** Newest first, as the server sends them. */
    remarks: ClientServiceRemark[];
}>();

const { t } = useI18n();

// The app's notes are written from their numbers, so they read in the selected language.
const textOf = (remark: ClientServiceRemark) => {
    if (remark.kind === 'note') {
        return remark.body ?? '';
    }

    if (remark.kind === 'cancel') {
        const { received, refund, cancel_refund, kept } = remark.details;
        const amounts = {
            received: formatMoney(received),
            refund: formatMoney(refund),
            cancelRefund: formatMoney(cancel_refund),
            kept: formatMoney(kept),
        };

        return refund !== '0.00'
            ? t('clientServices.remarks.cancelledWithRefund', amounts)
            : t('clientServices.remarks.cancelled', amounts);
    }

    const { from_fee, to_fee, received, refund } = remark.details;
    const amounts = {
        from: formatMoney(from_fee),
        to: formatMoney(to_fee),
        received: formatMoney(received),
        refund: formatMoney(refund),
    };

    // The server always sends the refund with two decimals, so no refund is exactly "0.00".
    return refund !== '0.00'
        ? t('clientServices.remarks.refundAdded', amounts)
        : t('clientServices.remarks.refundCleared', amounts);
};

const byline = (remark: ClientServiceRemark) =>
    [
        remark.created_at ? formatDateTime(remark.created_at) : '',
        remark.kind !== 'note'
            ? t('clientServices.remarks.automatic')
            : (remark.author ?? t('clientServices.remarks.unknownAuthor')),
    ]
        .filter(Boolean)
        .join(' · ');
</script>

<template>
    <ul class="divide-y" data-test="remark-list">
        <li
            v-for="remark in remarks"
            :key="remark.id"
            class="py-2"
            :data-test="`remark-${remark.kind}`"
        >
            <p
                class="flex items-start gap-1.5 leading-relaxed wrap-break-word whitespace-pre-line text-brand-dark"
                :class="{ 'font-medium': remark.kind !== 'note' }"
            >
                <Ban
                    v-if="remark.kind === 'cancel'"
                    class="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <ReceiptText
                    v-else-if="remark.kind === 'refund'"
                    class="mt-0.5 size-4 shrink-0 text-brand"
                />
                <span class="min-w-0">{{ textOf(remark) }}</span>
            </p>
            <p class="mt-0.5 text-xs text-muted-foreground">
                {{ byline(remark) }}
            </p>
        </li>
    </ul>
</template>
