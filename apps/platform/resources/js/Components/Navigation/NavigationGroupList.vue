<script setup lang="ts">
// SWC.1 (ADR 0073 sections 6.3, 6.5, 8): one region's groups. Group labels
// are not headings (the page's own <h1> comes later in the document); each
// list is named by its label. Consecutive items sharing a `section` (e.g.
// Transport) are nested under that section's name. The active entry is the
// server's decision (`active`), announced with aria-current="page".
import { Link as InertiaLink } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { NavigationGroup, NavigationGroupItem } from '../../types/navigation';

const props = defineProps<{ groups: NavigationGroup[] }>();

interface Block {
    section: string | null;
    items: NavigationGroupItem[];
}

const blocksByGroup = computed(() =>
    props.groups.map((group) => {
        const blocks: Block[] = [];
        for (const item of group.items) {
            const last = blocks[blocks.length - 1];
            if (last && last.section === item.section) {
                last.items.push(item);
            } else {
                blocks.push({ section: item.section, items: [item] });
            }
        }
        return { group, blocks };
    }),
);

const linkClass = (active: boolean) => [
    'block rounded px-2 py-1 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-amber-500',
    active ? 'bg-slate-900 font-medium text-white' : 'text-slate-700 hover:bg-slate-200',
];
</script>

<template>
    <div v-for="{ group, blocks } in blocksByGroup" :key="group.key" class="mt-4 first:mt-0">
        <p
            :id="`nav-group-${group.key}`"
            class="px-2 text-xs font-semibold tracking-wide text-slate-500 uppercase"
        >
            {{ group.label }}
        </p>
        <ul :aria-labelledby="`nav-group-${group.key}`" class="mt-1 space-y-0.5">
            <template v-for="(block, index) in blocks" :key="`${group.key}-${index}`">
                <li v-if="block.section">
                    <span
                        :id="`nav-section-${group.key}-${index}`"
                        class="mt-2 block px-2 text-xs font-medium text-slate-500"
                        >{{ block.section }}</span
                    >
                    <ul
                        :aria-labelledby="`nav-section-${group.key}-${index}`"
                        class="mt-0.5 space-y-0.5 pl-2"
                    >
                        <li v-for="item in block.items" :key="item.key">
                            <InertiaLink
                                :href="item.href"
                                :class="linkClass(item.active)"
                                :aria-current="item.active ? 'page' : undefined"
                                :data-nav-key="item.key"
                                >{{ item.label }}</InertiaLink
                            >
                        </li>
                    </ul>
                </li>
                <template v-else>
                    <li v-for="item in block.items" :key="item.key">
                        <InertiaLink
                            :href="item.href"
                            :class="linkClass(item.active)"
                            :aria-current="item.active ? 'page' : undefined"
                            :data-nav-key="item.key"
                            >{{ item.label }}</InertiaLink
                        >
                    </li>
                </template>
            </template>
        </ul>
    </div>
</template>
