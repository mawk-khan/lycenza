<script setup lang="ts">
// ORG.12D (ADR 0080 §9): the member-School directory of one Group -- name,
// code, relationship, Region, Zone and lifecycle; paginated and filtered on
// the server. Row actions come from the page through the `actions` slot.
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Pagination from '../Pagination.vue';
import type { DirectoryRow, Option, Paginated, StructureItem } from '../../groupNetwork';

const props = defineProps<{
    baseUrl: string;
    directory: Paginated<DirectoryRow>;
    filters: Record<string, string | undefined>;
    relationships: Option[];
    regions: StructureItem[];
    zones: StructureItem[];
    // ORG.12H (ADR 0080 §13.3): the Network report adds the attention filter and a closed sort.
    sorts?: string[];
    withAttention?: boolean;
}>();

const sortLabels: Record<string, string> = {
    name: 'School name',
    relationship: 'Relationship',
    region: 'Region',
    zone: 'Zone',
    status: 'Lifecycle',
};

const state = reactive({
    q: props.filters.q ?? '',
    relationship: props.filters.relationship ?? '',
    region_id: props.filters.region_id ?? '',
    zone_id: props.filters.zone_id ?? '',
    status: props.filters.status ?? '',
    attention: props.filters.attention ?? '',
    sort: props.filters.sort ?? 'name',
});

function apply() {
    // 'name' is the default order, so it is never sent.
    const query = Object.fromEntries(
        Object.entries(state).filter(([k, v]) => v !== '' && !(k === 'sort' && v === 'name')),
    );
    router.get(props.baseUrl, query, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <section aria-labelledby="directory-heading" class="mt-8">
        <h2 id="directory-heading" class="text-base font-semibold">Member Schools</h2>
        <form class="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6" @submit.prevent="apply">
            <div class="sm:col-span-2">
                <label class="block text-sm text-ink" for="directory-q">{{
                    withAttention ? 'School name, code or Network reference' : 'School name or code'
                }}</label>
                <input
                    id="directory-q"
                    v-model="state.q"
                    type="search"
                    maxlength="100"
                    class="input mt-1"
                />
            </div>
            <div>
                <label class="block text-sm text-ink" for="directory-relationship"
                    >Relationship</label
                >
                <select id="directory-relationship" v-model="state.relationship" class="input mt-1">
                    <option value="">Any</option>
                    <option v-for="r in relationships" :key="r.code" :value="r.code">
                        {{ r.label }}
                    </option>
                    <option value="unclassified">Unclassified</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink" for="directory-region">Region</label>
                <select id="directory-region" v-model="state.region_id" class="input mt-1">
                    <option value="">Any</option>
                    <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink" for="directory-zone">Zone</label>
                <select id="directory-zone" v-model="state.zone_id" class="input mt-1">
                    <option value="">Any</option>
                    <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink" for="directory-status">Lifecycle</label>
                <select id="directory-status" v-model="state.status" class="input mt-1">
                    <option value="">Any</option>
                    <option value="active">Active</option>
                    <option value="provisioning">Provisioning</option>
                    <option value="suspended">Suspended</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
            <div v-if="withAttention">
                <label class="block text-sm text-ink" for="directory-attention"
                    >Governance attention</label
                >
                <select id="directory-attention" v-model="state.attention" class="input mt-1">
                    <option value="">Any</option>
                    <option value="any">Needs attention</option>
                    <option value="unclassified">Unclassified</option>
                    <option value="inactive_structure">Inactive Region or Zone</option>
                    <option value="not_active">School not active</option>
                </select>
            </div>
            <div v-if="sorts && sorts.length">
                <label class="block text-sm text-ink" for="directory-sort">Sort by</label>
                <select id="directory-sort" v-model="state.sort" class="input mt-1">
                    <option v-for="key in sorts" :key="key" :value="key">
                        {{ sortLabels[key] ?? key }}
                    </option>
                </select>
            </div>
            <div class="sm:col-span-3 lg:col-span-6">
                <button type="submit" class="btn btn-secondary">Apply filters</button>
            </div>
        </form>

        <div
            class="relative mt-4 overflow-x-auto"
            tabindex="0"
            role="region"
            aria-label="Member Schools table (scrolls sideways on small screens)"
        >
            <table class="w-full text-left text-sm" data-testid="group-schools">
                <caption class="sr-only">
                    Member Schools of this Group
                </caption>
                <thead class="border-b border-control-line text-ink-muted">
                    <tr>
                        <th scope="col" class="py-2 pr-3">School</th>
                        <th scope="col" class="py-2 pr-3">Code</th>
                        <th scope="col" class="py-2 pr-3">Relationship</th>
                        <th scope="col" class="py-2 pr-3">Region</th>
                        <th scope="col" class="py-2 pr-3">Zone</th>
                        <th scope="col" class="py-2 pr-3">Lifecycle</th>
                        <th scope="col" class="py-2 pr-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="s in directory.data" :key="s.id">
                        <td class="py-2 pr-3 font-medium">
                            {{ s.name }}
                            <span
                                v-if="s.networkReference"
                                class="block text-xs font-normal text-ink-muted"
                                >{{ s.networkReference }}</span
                            >
                        </td>
                        <td class="py-2 pr-3">{{ s.code ?? '—' }}</td>
                        <td class="py-2 pr-3">
                            <span
                                class="rounded-xs px-1.5 py-0.5 text-xs ring-1 ring-inset"
                                :class="
                                    s.relationship
                                        ? 'ring-control-line'
                                        : 'bg-accent-100 text-ink ring-accent-300'
                                "
                                >{{ s.relationshipLabel }}</span
                            >
                        </td>
                        <td class="py-2 pr-3">
                            {{ s.region?.name ?? '—'
                            }}<span v-if="s.region?.status === 'inactive'" class="text-ink">
                                (inactive)</span
                            >
                        </td>
                        <td class="py-2 pr-3">
                            {{ s.zone?.name ?? '—'
                            }}<span v-if="s.zone?.status === 'inactive'" class="text-ink">
                                (inactive)</span
                            >
                        </td>
                        <td class="py-2 pr-3 capitalize">{{ s.status }}</td>
                        <td class="py-2 pr-3 text-right"><slot name="actions" :school="s" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="!directory.data.length" class="mt-2 text-sm text-ink-muted">
            No member Schools match.
        </p>
        <Pagination
            :links="directory.links"
            :from="directory.from"
            :to="directory.to"
            :total="directory.total"
        />
    </section>
</template>
