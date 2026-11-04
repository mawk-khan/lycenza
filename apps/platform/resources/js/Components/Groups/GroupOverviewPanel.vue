<script setup lang="ts">
// ORG.12D (ADR 0080 §9): a School Group's structural summary -- counts of its
// member Schools, Regions and Zones only, never of anything inside a School.
import type { GroupOverview } from '../../groupNetwork';

defineProps<{ overview: GroupOverview }>();
</script>

<template>
    <section aria-labelledby="overview-heading" class="mt-6 rounded-xs border border-line p-4">
        <h2 id="overview-heading" class="text-base font-semibold">Network overview</h2>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-ink-muted">Member Schools</dt>
                <dd class="text-lg font-semibold">{{ overview.schools.total }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Active</dt>
                <dd class="text-lg font-semibold">{{ overview.schools.active }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Regions</dt>
                <dd class="text-lg font-semibold">{{ overview.regions.length }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Zones</dt>
                <dd class="text-lg font-semibold">{{ overview.zones.length }}</dd>
            </div>
        </dl>
        <h3 class="mt-4 text-sm font-medium">Relationships</h3>
        <ul class="mt-1 flex flex-wrap gap-x-5 gap-y-1 text-sm">
            <li>Owned: {{ overview.relationships.owned }}</li>
            <li>Franchise: {{ overview.relationships.franchise }}</li>
            <li>Managed: {{ overview.relationships.managed }}</li>
            <li>Affiliate: {{ overview.relationships.affiliate }}</li>
            <li>Unclassified: {{ overview.relationships.unclassified }}</li>
        </ul>
        <div
            v-if="
                overview.attention.unclassified ||
                overview.attention.inInactiveStructure ||
                overview.attention.schoolsNotActive
            "
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 px-3 py-2 text-sm text-ink"
            role="status"
        >
            <p class="font-medium"><span aria-hidden="true">⚠ </span>Needs governance attention</p>
            <ul class="mt-1 list-inside list-disc">
                <li v-if="overview.attention.unclassified">
                    {{ overview.attention.unclassified }} School(s) without a relationship
                    classification
                </li>
                <li v-if="overview.attention.inInactiveStructure">
                    {{ overview.attention.inInactiveStructure }} School(s) placed in an inactive
                    Region or Zone
                </li>
                <li v-if="overview.attention.schoolsNotActive">
                    {{ overview.attention.schoolsNotActive }} School(s) not active
                </li>
            </ul>
        </div>
        <p class="mt-3 text-xs text-ink-muted">
            Regions, Zones and relationships organise the network. They never give anyone access to
            a School.
        </p>
    </section>
</template>
