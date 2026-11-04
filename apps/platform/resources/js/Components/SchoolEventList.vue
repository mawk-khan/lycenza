<script setup lang="ts">
// ORG.9D-3 (ADR 0076 §75): one list of School calendar Events. Every value
// is rendered as text ({{ }}), so a title, location or description can never
// become markup. A cancelled Event stays listed and is clearly marked.
import { EVENT_STATUS_LABELS, eventWhen, type SchoolEvent } from '../schoolEvents';

defineProps<{
    events: SchoolEvent[];
    showStatus?: boolean;
    linkTo?: (event: SchoolEvent) => string;
}>();
</script>

<template>
    <ul class="divide-y divide-line rounded-xs border border-line">
        <li v-for="event in events" :key="event.id" class="p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3
                    class="font-medium"
                    :class="event.cancelled ? 'text-ink-muted line-through' : ''"
                >
                    <a v-if="linkTo" class="underline" :href="linkTo(event)">{{ event.title }}</a>
                    <template v-else>{{ event.title }}</template>
                </h3>
                <span v-if="event.cancelled" class="tag tag-attention">Cancelled</span>
                <span
                    v-else-if="showStatus"
                    class="rounded-xs bg-graphite-200 px-2 py-0.5 text-xs text-ink"
                    >{{ EVENT_STATUS_LABELS[event.status] ?? event.status }}</span
                >
            </div>
            <p class="mt-1 text-sm text-ink">{{ eventWhen(event) }}</p>
            <p class="text-xs text-ink-muted">
                <span v-if="event.location">{{ event.location }} · </span>{{ event.audienceLabel }}
            </p>
            <p v-if="!linkTo" class="mt-2 text-sm whitespace-pre-wrap text-ink">
                {{ event.body }}
            </p>
        </li>
    </ul>
</template>
