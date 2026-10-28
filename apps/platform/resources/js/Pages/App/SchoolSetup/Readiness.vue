<script setup lang="ts">
import { computed } from 'vue';
import {
    overallStateLabels,
    type SetupCard,
    type SetupCenter,
    statusPresentation,
} from '../../../setupReadiness';

/**
 * ORG.12B (ADR 0080 §7): Organization -> Setup & readiness. Everything shown
 * comes from the server, recomputed on each visit: which setup areas exist,
 * their status, prerequisites and the one action this viewer may take.
 * Nothing here changes data -- each area is configured in its own module.
 */
const props = defineProps<{ center: SetupCenter }>();

const overview = computed(() => props.center.overview);

function percent(part: { configured: number; total: number }): number {
    return part.total === 0 ? 0 : Math.round((part.configured / part.total) * 100);
}

function checks(card: SetupCard): string | null {
    return card.totalChecks === null
        ? null
        : `${card.completedChecks} of ${card.totalChecks} checks done`;
}

// Open the categories that still need work; fully configured ones start closed.
function needsWork(items: SetupCard[]): boolean {
    return items.some((item) => item.status !== 'configured');
}
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>

        <h1 class="mt-2 text-xl font-semibold">Setup &amp; readiness</h1>
        <p class="mt-1 text-sm text-slate-600">
            How {{ center.school.name }} is configured, worked out from each module's own records
            each time you open this page. Status here never gives access: each area is configured in
            its own module, by people allowed to do so.
        </p>

        <!-- Overview -->
        <section
            aria-labelledby="overview-heading"
            class="mt-6 rounded border border-slate-200 p-4"
        >
            <h2 id="overview-heading" class="text-base font-semibold">Overview</h2>
            <p class="mt-1 text-sm" role="status">
                <span class="font-medium">{{ overallStateLabels[overview.state] }}</span>
                <span class="text-slate-500"> · School: {{ center.school.name }}</span>
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <p id="core-progress-label" class="text-sm font-medium">Core setup</p>
                    <progress
                        class="mt-1 h-2 w-full"
                        aria-labelledby="core-progress-label"
                        :value="overview.core.configured"
                        :max="Math.max(overview.core.total, 1)"
                    >
                        {{ percent(overview.core) }}%
                    </progress>
                    <p class="mt-1 text-xs text-slate-600">
                        {{ overview.core.configured }} of {{ overview.core.total }} core areas
                        configured
                    </p>
                </div>
                <div>
                    <p id="optional-progress-label" class="text-sm font-medium">Enabled modules</p>
                    <template v-if="overview.optional.total > 0">
                        <progress
                            class="mt-1 h-2 w-full"
                            aria-labelledby="optional-progress-label"
                            :value="overview.optional.configured"
                            :max="overview.optional.total"
                        >
                            {{ percent(overview.optional) }}%
                        </progress>
                        <p class="mt-1 text-xs text-slate-600">
                            {{ overview.optional.configured }} of
                            {{ overview.optional.total }} enabled module areas configured
                        </p>
                    </template>
                    <p v-else class="mt-1 text-xs text-slate-600">
                        No optional modules you can see are enabled.
                    </p>
                </div>
            </div>

            <ul class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                <li>
                    <span aria-hidden="true">{{ statusPresentation.attention_required.icon }}</span>
                    {{ overview.attention }} need attention
                </li>
                <li>
                    <span aria-hidden="true">{{ statusPresentation.blocked.icon }}</span>
                    {{ overview.blocked }} blocked
                </li>
                <li>
                    <span aria-hidden="true">{{ statusPresentation.not_applicable.icon }}</span>
                    {{ overview.notEnabled }} optional modules not enabled
                </li>
            </ul>
            <p v-if="overview.hiddenAreas > 0" class="mt-3 text-xs text-slate-500">
                {{ overview.hiddenAreas }} setup
                {{ overview.hiddenAreas === 1 ? 'area is' : 'areas are' }} not shown because they
                need access you do not have.
            </p>
        </section>

        <!-- Next actions -->
        <section aria-labelledby="next-heading" class="mt-8">
            <h2 id="next-heading" class="text-base font-semibold">Next actions</h2>
            <ol v-if="center.nextActions.length > 0" class="mt-3 space-y-2">
                <li
                    v-for="(next, index) in center.nextActions"
                    :key="next.key"
                    class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-200 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ index + 1 }}. {{ next.title }}</p>
                        <p class="text-xs text-slate-600">{{ next.reason }}</p>
                    </div>
                    <a
                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2"
                        :href="next.href"
                        :aria-label="`Configure ${next.title}`"
                        >Configure</a
                    >
                </li>
            </ol>
            <p v-else class="mt-2 text-sm text-slate-600">
                Nothing you can configure needs doing right now.
            </p>
        </section>

        <!-- Setup areas by category -->
        <section aria-labelledby="areas-heading" class="mt-8">
            <h2 id="areas-heading" class="text-base font-semibold">Setup areas</h2>
            <details
                v-for="category in center.categories"
                :key="category.key"
                class="mt-4 rounded border border-slate-200"
                :open="needsWork(category.items)"
            >
                <summary
                    class="cursor-pointer px-4 py-3 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                >
                    <h3 class="inline">{{ category.label }}</h3>
                    <span class="font-normal text-slate-500">
                        ·
                        {{ category.items.filter((i) => i.status === 'configured').length }} of
                        {{ category.items.length }} configured</span
                    >
                </summary>
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    <li
                        v-for="card in category.items"
                        :key="card.key"
                        class="flex flex-wrap items-start justify-between gap-3 px-4 py-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-slate-500">
                                {{ card.owner }} · {{ card.scope }}
                                <span v-if="!card.core"> · Optional module</span>
                            </p>
                            <h4 class="font-medium">{{ card.title }}</h4>
                            <p class="mt-0.5 text-sm text-slate-600">{{ card.description }}</p>
                            <p class="mt-2 text-sm">{{ card.explanation }}</p>
                            <p v-if="checks(card)" class="mt-1 text-xs text-slate-500">
                                {{ checks(card) }}
                            </p>
                            <p
                                v-if="card.prerequisites.length > 0"
                                class="mt-1 text-xs text-slate-700"
                            >
                                Complete
                                {{ card.prerequisites.map((p) => p.title).join(', ') }} first.
                            </p>
                            <p v-if="card.hiddenPrerequisite" class="mt-1 text-xs text-slate-700">
                                A prerequisite setup area must be completed first.
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <span
                                class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="statusPresentation[card.status].classes"
                            >
                                <span aria-hidden="true">{{
                                    statusPresentation[card.status].icon
                                }}</span>
                                <span class="sr-only">Status: </span>
                                {{ statusPresentation[card.status].label }}
                            </span>
                            <a
                                v-if="card.action.kind !== 'none' && card.action.href"
                                class="text-sm font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                :href="card.action.href"
                                :aria-label="`${card.action.kind === 'configure' ? 'Configure' : 'View'} ${card.title}`"
                                >{{ card.action.kind === 'configure' ? 'Configure' : 'View' }}</a
                            >
                            <span v-else class="text-xs text-slate-500">
                                Managed in its own module by people with access.
                            </span>
                        </div>
                    </li>
                </ul>
            </details>
        </section>

        <!-- Optional modules not enabled -->
        <section aria-labelledby="optional-heading" class="mt-8">
            <h2 id="optional-heading" class="text-base font-semibold">
                Optional modules not enabled
            </h2>
            <p class="mt-1 text-sm text-slate-600">
                These modules are not enabled for the School, so they do not count towards setup
                progress. Module availability is managed by Lycenza, not on this page.
            </p>
            <ul
                v-if="center.notEnabled.length > 0"
                class="mt-3 divide-y divide-slate-100 rounded border border-slate-200"
            >
                <li
                    v-for="module in center.notEnabled"
                    :key="module.key"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ module.title }}</p>
                        <p class="text-xs text-slate-500">{{ module.owner }}</p>
                    </div>
                    <span
                        class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                        :class="statusPresentation.not_applicable.classes"
                    >
                        <span aria-hidden="true">{{ statusPresentation.not_applicable.icon }}</span>
                        {{ statusPresentation.not_applicable.label }}
                    </span>
                </li>
            </ul>
            <p v-else class="mt-2 text-sm text-slate-600">Every optional module is enabled.</p>
        </section>
    </main>
</template>
