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
    <ul class="divide-y divide-slate-100 rounded border border-slate-200">
        <li v-for="event in events" :key="event.id" class="p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3
                    class="font-medium"
                    :class="event.cancelled ? 'text-slate-500 line-through' : ''"
                >
                    <a v-if="linkTo" class="underline" :href="linkTo(event)">{{ event.title }}</a>
                    <template v-else>{{ event.title }}</template>
                </h3>
                <span
                    v-if="event.cancelled"
                    class="rounded bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
                    >Cancelled</span
                >
                <span
                    v-else-if="showStatus"
                    class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
                    >{{ EVENT_STATUS_LABELS[event.status] ?? event.status }}</span
                >
            </div>
            <p class="mt-1 text-sm text-slate-700">{{ eventWhen(event) }}</p>
            <p class="text-xs text-slate-500">
                <span v-if="event.location">{{ event.location }} · </span>{{ event.audienceLabel }}
            </p>
            <p v-if="!linkTo" class="mt-2 text-sm whitespace-pre-wrap text-slate-700">
                {{ event.body }}
            </p>
        </li>
    </ul>
</template>
