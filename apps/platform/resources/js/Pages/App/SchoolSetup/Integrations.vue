<script setup lang="ts">
import { computed } from 'vue';
import {
    type IntegrationHub,
    integrationStatusPresentation,
    overviewOrder,
} from '../../../integrationReadiness';
import { statusPresentation } from '../../../setupReadiness';

/**
 * ORG.12G (ADR 0080 §12): School setup -> Integrations & devices. Everything
 * shown comes from the server, recomputed on each visit from each owning
 * module's own records. Nothing here connects, tests or changes an
 * integration, and no credential is ever shown.
 */
const props = defineProps<{ hub: IntegrationHub }>();

const counts = computed(() =>
    overviewOrder
        .map((status) => ({ status, count: props.hub.overview.counts[status] ?? 0 }))
        .filter((entry) => entry.count > 0),
);
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>

        <h1 class="mt-2 text-xl font-semibold">Integrations &amp; devices</h1>
        <p class="mt-1 text-sm text-slate-600">
            Which integrations {{ hub.school.name }} uses and how they are doing, read from each
            module's own records each time you open this page. Nothing here connects to a provider
            or device, and no credential is ever shown. Each integration is managed in its own
            module, or by Lycenza.
        </p>

        <!-- Overview -->
        <section
            aria-labelledby="overview-heading"
            class="mt-6 rounded border border-slate-200 p-4"
        >
            <h2 id="overview-heading" class="text-base font-semibold">Overview</h2>
            <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <li v-for="entry in counts" :key="entry.status">
                    <span aria-hidden="true">{{
                        integrationStatusPresentation[entry.status].icon
                    }}</span>
                    {{ entry.count }}
                    {{ integrationStatusPresentation[entry.status].label.toLowerCase() }}
                </li>
            </ul>
            <div
                v-if="hub.overview.setupArea"
                class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium">
                        Setup area: {{ hub.overview.setupArea.title }}
                    </p>
                    <p class="text-xs text-slate-600">{{ hub.overview.setupArea.explanation }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span
                        v-if="hub.overview.setupArea.status"
                        class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                        :class="statusPresentation[hub.overview.setupArea.status].classes"
                    >
                        <span aria-hidden="true">{{
                            statusPresentation[hub.overview.setupArea.status].icon
                        }}</span>
                        <span class="sr-only">Setup status: </span>
                        {{ statusPresentation[hub.overview.setupArea.status].label }}
                    </span>
                    <a
                        v-if="hub.overview.setupArea.href"
                        class="text-sm font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                        :href="hub.overview.setupArea.href"
                        >Setup &amp; readiness</a
                    >
                </div>
            </div>
            <div
                v-if="hub.overview.verificationRequired.length > 0"
                class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                <p>Verify your sign-in (multi-factor authentication) to see the status of:</p>
                <ul class="mt-1 list-inside list-disc">
                    <li v-for="item in hub.overview.verificationRequired" :key="item.key">
                        {{ item.title }}
                        <a
                            v-if="item.href"
                            class="font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600"
                            :href="item.href"
                            :aria-label="`Verify to view ${item.title}`"
                            >Verify</a
                        >
                    </li>
                </ul>
            </div>
            <p v-if="hub.overview.hiddenFamilies > 0" class="mt-3 text-xs text-slate-500">
                {{ hub.overview.hiddenFamilies }}
                {{ hub.overview.hiddenFamilies === 1 ? 'integration is' : 'integrations are' }} not
                shown because they need access you do not have.
            </p>
        </section>

        <!-- Sections -->
        <section
            v-for="section in hub.sections"
            :key="section.key"
            :aria-labelledby="`section-${section.key}`"
            class="mt-8"
        >
            <h2 :id="`section-${section.key}`" class="text-base font-semibold">
                {{ section.label }}
            </h2>
            <ul class="mt-3 divide-y divide-slate-100 rounded border border-slate-200">
                <li
                    v-for="card in section.items"
                    :key="card.key"
                    class="flex flex-wrap items-start justify-between gap-3 px-4 py-4"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-slate-500">
                            {{ card.owner }}
                            <template v-if="card.status !== 'not_available'">
                                ·
                                {{
                                    card.managedBy === 'platform'
                                        ? 'Managed by Lycenza'
                                        : 'Managed by the School'
                                }}
                            </template>
                        </p>
                        <h3 class="font-medium">{{ card.title }}</h3>
                        <p class="mt-0.5 text-sm text-slate-600">{{ card.description }}</p>
                        <p class="mt-2 text-sm">{{ card.explanation }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span
                            class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                            :class="integrationStatusPresentation[card.status].classes"
                        >
                            <span aria-hidden="true">{{
                                integrationStatusPresentation[card.status].icon
                            }}</span>
                            <span class="sr-only">Status: </span>
                            {{ integrationStatusPresentation[card.status].label }}
                        </span>
                        <a
                            v-if="card.action.kind !== 'none' && card.action.href"
                            class="text-sm font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                            :href="card.action.href"
                            :aria-label="`${card.action.kind === 'configure' ? 'Manage' : 'View'} ${card.title}`"
                            >{{ card.action.kind === 'configure' ? 'Manage' : 'View' }}</a
                        >
                    </div>
                </li>
            </ul>
        </section>
    </main>
</template>
