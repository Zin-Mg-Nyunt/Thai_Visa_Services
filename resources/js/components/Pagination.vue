<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import type { PaginatedClients } from '@/types';

defineProps<{
    meta: PaginatedClients['meta'];
    links: PaginatedClients['links'];
}>();

const { t } = useI18n();
</script>

<template>
    <div
        v-if="meta.total > 0"
        class="flex flex-col items-center justify-between gap-3 text-sm text-muted-foreground sm:flex-row"
    >
        <span>
            {{
                t('clients.pagination.showing', {
                    from: meta.from,
                    to: meta.to,
                    total: meta.total,
                })
            }}
        </span>
        <div class="flex flex-wrap items-center justify-center gap-2">
            <Button
                v-if="links.prev"
                as-child
                variant="pillOutline"
                size="sm"
                class="cursor-pointer"
                preserve-scroll
            >
                <Link
                    :href="links.prev"
                    :aria-label="t('clients.pagination.previous')"
                    preserve-scroll
                >
                    <ChevronLeft class="size-4" />
                    <span class="hidden sm:inline">{{
                        t('clients.pagination.previous')
                    }}</span>
                </Link>
            </Button>
            <Button
                v-else
                variant="pillOutline"
                size="sm"
                disabled
                type="button"
                :aria-label="t('clients.pagination.previous')"
            >
                <ChevronLeft class="size-4" />
                <span class="hidden sm:inline">{{
                    t('clients.pagination.previous')
                }}</span>
            </Button>
            <span>
                {{
                    t('clients.pagination.page', {
                        current: meta.current_page,
                        last: meta.last_page,
                    })
                }}
            </span>
            <Button v-if="links.next" as-child variant="pillOutline" size="sm">
                <Link
                    :href="links.next"
                    class="cursor-pointer"
                    :aria-label="t('clients.pagination.next')"
                    preserve-scroll
                >
                    <span class="hidden sm:inline">{{
                        t('clients.pagination.next')
                    }}</span>
                    <ChevronRight class="size-4" />
                </Link>
            </Button>
            <Button
                v-else
                variant="pillOutline"
                size="sm"
                disabled
                type="button"
                :aria-label="t('clients.pagination.next')"
            >
                <span class="hidden sm:inline">{{
                    t('clients.pagination.next')
                }}</span>
                <ChevronRight class="size-4" />
            </Button>
        </div>
    </div>
</template>
