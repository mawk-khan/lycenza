<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import {
    type CheckDefinition,
    kindLabels,
    severityClasses,
    severityHelp,
    severityLabels,
    severityOrder,
} from './severity';

/**
 * V11.5 (ADR 0075 section 10): the Data Health overview. Each check the
 * viewer may see says only whether it found anything -- no counts here (a
 * count of people would be Analytics). Nothing on this page changes data:
 * every correction happens in the record's own page.
 */
interface CheckRow {
    definition: CheckDefinition;
    hasFindings: boolean;
}

const props = defineProps<{
    checks: CheckRow[];
    can: Record<string, boolean>;
}>();

const groups = computed(() =>
    severityOrder
        .map((severity) => ({
            severity,
            checks: props.checks.filter((c) => c.definition.severity === severity),
        }))
        .filter((g) => g.checks.length > 0),
);

const needsAttention = computed(() => props.checks.filter((c) => c.hasFindings).length > 0);
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Home</a>

        <h1 class="mt-2 text-xl font-semibold">Data health</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Checks of your School's records for problems that get in the way of day-to-day work.
            This page only reports; each problem is fixed in the record's own page. You see only the
            checks for information you are allowed to view.
        </p>

        <EmptyState
            v-if="checks.length === 0"
            class="mt-6"
            title="No checks available to you"
            description="None of the checks cover information your role can view."
        />

        <template v-else>
            <p
                class="mt-6 rounded-xs border px-4 py-3 text-sm"
                :class="
                    needsAttention
                        ? 'border-accent-300 bg-accent-100 text-ink'
                        : 'border-graphite-300 bg-graphite-100 text-ink'
                "
                role="status"
            >
                {{
                    needsAttention
                        ? 'Some checks found records that need attention. Open a check to see them.'
                        : 'No problems found in any of the checks you can see.'
                }}
            </p>

            <section v-for="group in groups" :key="group.severity" class="mt-8">
                <h2 class="text-sm font-semibold">
                    {{ severityLabels[group.severity] }}
                    <span class="font-normal text-ink-muted"
                        >— {{ severityHelp[group.severity] }}</span
                    >
                </h2>
                <ul class="mt-3 divide-y divide-line border-y border-line">
                    <li
                        v-for="check in group.checks"
                        :key="check.definition.code"
                        class="flex flex-wrap items-start justify-between gap-3 py-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-ink-muted">
                                {{ check.definition.area }} ·
                                {{ kindLabels[check.definition.kind] }}
                            </p>
                            <a
                                class="font-medium underline"
                                :href="`/app/data-health/${check.definition.code}`"
                                >{{ check.definition.title }}</a
                            >
                            <p class="mt-1 text-sm text-ink-muted">
                                {{ check.definition.condition }}
                            </p>
                        </div>
                        <span
                            class="inline-flex shrink-0 rounded-xs px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                            :class="
                                check.hasFindings
                                    ? severityClasses[check.definition.severity]
                                    : 'bg-graphite-100 text-ink ring-graphite-300'
                            "
                        >
                            {{ check.hasFindings ? 'Needs attention' : 'No problems found' }}
                        </span>
                    </li>
                </ul>
            </section>
        </template>
    </main>
</template>
