<script setup lang="ts">
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import { type CheckDefinition, kindLabels, severityClasses, severityLabels } from './severity';

/**
 * V11.5 (ADR 0075 section 10): one check's findings. Each row links only
 * to existing pages the viewer may open -- to look at the record, or to
 * correct it in its own workflow. Nothing here changes data.
 */
interface FindingLink {
    label: string;
    url: string;
    isRepair: boolean;
}

interface Finding {
    entityType: string;
    entityId: string;
    label: string;
    detail: string;
    links: FindingLink[];
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

defineProps<{
    check: CheckDefinition;
    findings: {
        data: Finding[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
}>();
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/data-health">← Data health</a>

        <p class="mt-2 text-xs text-slate-500">{{ check.area }} · {{ kindLabels[check.kind] }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">{{ check.title }}</h1>
            <span
                class="inline-flex rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                :class="severityClasses[check.severity]"
                >{{ severityLabels[check.severity] }}</span
            >
        </div>

        <dl class="mt-4 grid gap-3 text-sm">
            <div>
                <dt class="font-medium">What this check looks for</dt>
                <dd class="mt-0.5 text-slate-600">{{ check.condition }}</dd>
            </div>
            <div>
                <dt class="font-medium">Why it matters</dt>
                <dd class="mt-0.5 text-slate-600">{{ check.whyItMatters }}</dd>
            </div>
            <div>
                <dt class="font-medium">How to fix it</dt>
                <dd class="mt-0.5 text-slate-600">{{ check.repair }}</dd>
            </div>
        </dl>

        <EmptyState
            v-if="findings.data.length === 0"
            class="mt-6"
            title="No problems found"
            description="Nothing currently matches this check."
        />

        <template v-else>
            <ul class="mt-6 divide-y divide-slate-100 border-y border-slate-100">
                <li
                    v-for="finding in findings.data"
                    :key="finding.entityId"
                    class="flex flex-wrap gap-x-6 gap-y-2 py-3 text-sm"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ finding.label }}</p>
                        <p class="mt-0.5 text-slate-500">{{ finding.detail }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <a
                            v-for="link in finding.links"
                            :key="link.label"
                            class="underline"
                            :class="link.isRepair ? 'font-medium' : 'text-slate-600'"
                            :href="link.url"
                            >{{ link.label }}</a
                        >
                    </div>
                </li>
            </ul>
            <Pagination
                :links="findings.links"
                :from="findings.from"
                :to="findings.to"
                :total="findings.total"
            />
        </template>
    </main>
</template>
