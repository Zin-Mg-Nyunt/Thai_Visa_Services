<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
        <div class="flex items-center gap-2">
            <Button
                v-if="links.prev"
                as-child
                variant="pillOutline"
                size="sm"
                class="cursor-pointer"
                preserve-scroll
            >
                <Link :href="links.prev" preserve-scroll>{{
                    t('clients.pagination.previous')
                }}</Link>
            </Button>
            <Button
                v-else
                variant="pillOutline"
                size="sm"
                disabled
                type="button"
            >
                {{ t('clients.pagination.previous') }}
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
                    preserve-scroll
                    >{{ t('clients.pagination.next') }}</Link
                >
            </Button>
            <Button
                v-else
                variant="pillOutline"
                size="sm"
                disabled
                type="button"
            >
                {{ t('clients.pagination.next') }}
            </Button>
        </div>
    </div>
</template>
