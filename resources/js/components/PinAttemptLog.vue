<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, LockKeyholeOpen } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { unlock } from '@/actions/App/Http/Controllers/Admin/ClientController';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import type { PinAttempt } from '@/types';

const props = defineProps<{
    clientId: number;
    attempts: PinAttempt[];
    lockedMinutes: number;
}>();

const { t } = useI18n();

const isUnlocking = ref(false);

// The log is collapsed by default; a lock warning stays visible either way.
const isOpen = ref(false);

const unlockLink = () => {
    router.post(
        unlock.url(props.clientId),
        {},
        {
            preserveScroll: true,
            onStart: () => (isUnlocking.value = true),
            onFinish: () => (isUnlocking.value = false),
        },
    );
};
</script>

<template>
    <section
        class="mt-6 max-w-3xl rounded-2xl border bg-card p-4 shadow-xs sm:p-6"
        data-test="pin-log"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-brand-dark">
                {{ t('clients.pinLog.title') }}
                <span class="text-sm font-normal text-muted-foreground">
                    ({{ attempts.length }})
                </span>
            </h2>
            <Button
                type="button"
                variant="pillOutline"
                size="sm"
                :aria-expanded="isOpen"
                aria-controls="pin-log-details"
                data-test="pin-log-toggle"
                @click="isOpen = !isOpen"
            >
                {{
                    isOpen ? t('clients.pinLog.hide') : t('clients.pinLog.show')
                }}
                <ChevronDown
                    class="size-4 transition-transform"
                    :class="{ 'rotate-180': isOpen }"
                />
            </Button>
        </div>

        <div
            v-if="lockedMinutes > 0"
            class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-3"
            role="status"
            data-test="pin-log-locked"
        >
            <p class="text-sm font-medium text-destructive">
                {{ t('clients.pinLog.locked', { minutes: lockedMinutes }) }}
            </p>
            <Button
                type="button"
                variant="pill"
                :disabled="isUnlocking"
                data-test="unlock-client-button"
                @click="unlockLink"
            >
                <LockKeyholeOpen class="size-4" />
                {{ t('clients.pinLog.unlock') }}
            </Button>
        </div>

        <div v-show="isOpen" id="pin-log-details">
            <p class="mt-3 text-sm text-muted-foreground">
                {{ t('clients.pinLog.description') }}
            </p>

            <p
                v-if="attempts.length === 0"
                class="mt-4 text-sm text-muted-foreground"
                data-test="pin-log-empty"
            >
                {{ t('clients.pinLog.empty') }}
            </p>

            <div v-else class="mt-4 overflow-x-auto">
                <table
                    class="w-full text-left text-sm"
                    data-test="pin-log-table"
                >
                    <thead class="text-xs text-muted-foreground uppercase">
                        <tr class="border-b">
                            <th class="py-2 pr-3 font-medium">
                                {{ t('clients.pinLog.time') }}
                            </th>
                            <th class="py-2 pr-3 font-medium">
                                {{ t('clients.pinLog.ip') }}
                            </th>
                            <th class="py-2 pr-3 font-medium">
                                {{ t('clients.pinLog.browser') }}
                            </th>
                            <th class="py-2 font-medium">
                                {{ t('clients.pinLog.result') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="attempt in attempts" :key="attempt.id">
                            <td class="py-2 pr-3 whitespace-nowrap">
                                {{ formatDateTime(attempt.created_at) }}
                            </td>
                            <td class="py-2 pr-3 whitespace-nowrap">
                                {{ attempt.ip_address }}
                            </td>
                            <td
                                class="max-w-48 truncate py-2 pr-3"
                                :title="attempt.user_agent ?? ''"
                            >
                                {{ attempt.user_agent }}
                            </td>
                            <td class="py-2 whitespace-nowrap">
                                <span
                                    :class="
                                        attempt.caused_lock
                                            ? 'font-semibold text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        attempt.caused_lock
                                            ? t('clients.pinLog.lockedResult')
                                            : t('clients.pinLog.wrong')
                                    }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-3 text-xs text-muted-foreground">
                    {{ t('clients.pinLog.latest', { count: attempts.length }) }}
                </p>
            </div>
        </div>
    </section>
</template>
