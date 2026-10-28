<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { statusTones, toneClasses } from '@/lib/status';
import type {
    JobOrderStatus,
    PaymentStatus,
    ServiceStatus,
} from '@/lib/status';

type Props =
    | { kind: 'serviceStatus'; status: ServiceStatus }
    | { kind: 'paymentStatus'; status: PaymentStatus }
    | { kind: 'jobOrderStatus'; status: JobOrderStatus };

const props = defineProps<Props>();

const { t } = useI18n();

const tone = computed(() => statusTones[props.kind][props.status] ?? 'neutral');
</script>

<template>
    <Badge variant="outline" :class="toneClasses[tone]">
        {{ t(`${kind}.${status}`) }}
    </Badge>
</template>
