<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useAdminNavItems } from '@/composables/useAdminNavItems';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';

const { t } = useI18n();
const items = useAdminNavItems();
const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <nav
        class="pointer-events-none fixed inset-x-0 bottom-0 z-30 flex items-center justify-center gap-3 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] lg:hidden"
        :aria-label="t('nav.dashboard')"
        data-test="bottom-nav"
    >
        <Link
            v-for="item in items"
            :key="String(item.title)"
            :href="item.href"
            :aria-label="item.title"
            :title="item.title"
            :class="
                cn(
                    'pointer-events-auto flex size-12 shrink-0 items-center justify-center rounded-full shadow-lg ring-1 ring-black/10 transition-colors',
                    isCurrentOrParentUrl(item.href)
                        ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                        : 'bg-sidebar text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                )
            "
        >
            <component :is="item.icon" class="size-5" />
        </Link>
    </nav>
</template>
