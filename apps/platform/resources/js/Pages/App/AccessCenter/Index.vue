<script setup lang="ts">
// ORG.12E (ADR 0080 §10.7): the School Access Center -- read-only. Lycenza's
// School roles are code-owned templates; a role is assigned for the whole
// School or one Campus (scope is never a separate role); menus never grant
// anything. Nothing on this page changes access: roles are granted and
// revoked on Staff accounts.
import { computed, ref } from 'vue';
import type { ModuleInfo, RoleTemplate, SensitiveFamily } from '../../../accessCenter';

const props = defineProps<{
    roles: RoleTemplate[];
    modules: ModuleInfo[];
    matrix: Array<{ role: string; name: string; cells: Array<'none' | 'read' | 'manage'> }>;
    sensitive: { families: SensitiveFamily[]; neverExposed: string[]; notStored: string[] };
    canViewAssignments: boolean;
}>();

const tab = ref<'roles' | 'matrix' | 'capabilities' | 'sensitive'>('roles');
const tabs = [
    { key: 'roles', label: 'Role templates' },
    { key: 'matrix', label: 'Role matrix' },
    { key: 'capabilities', label: 'Capabilities' },
    { key: 'sensitive', label: 'Sensitive data' },
] as const;
const openRole = ref<string | null>(null);

// Capability explorer filters (bounded, no query builder).
const moduleFilter = ref('');
const kindFilter = ref<'' | 'read' | 'manage'>('');
const sensitiveOnly = ref(false);
const campusOnly = ref(false);
const filteredModules = computed(() =>
    props.modules
        .filter((m) => moduleFilter.value === '' || m.key === moduleFilter.value)
        .map((m) => ({
            ...m,
            capabilities: m.capabilities.filter(
                (c) =>
                    (kindFilter.value === '' || (kindFilter.value === 'read' ? c.read : !c.read)) &&
                    (!sensitiveOnly.value || c.sensitive) &&
                    (!campusOnly.value || c.campus),
            ),
        }))
        .filter((m) => m.capabilities.length > 0),
);
const familyLabel = (key: string) =>
    props.sensitive.families.find((f) => f.key === key)?.label ?? key;
const cellText = { none: '—', read: 'Read', manage: 'Manage' };
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Access Center</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            How access works in this School. Roles are fixed templates; each assignment is for the
            whole School or one Campus. Menus never grant access — every page checks the capability
            itself.
            <a class="underline" href="/app/settings/staff">Staff accounts</a> is where roles are
            granted and revoked.
        </p>
        <p v-if="canViewAssignments" class="mt-2 text-sm">
            <a class="underline" href="/app/settings/access/users">Users &amp; effective access</a>
        </p>

        <div role="tablist" aria-label="Access Center sections" class="mt-6 flex flex-wrap gap-2">
            <button
                v-for="t in tabs"
                :id="`tab-${t.key}`"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="tab === t.key"
                :aria-controls="`panel-${t.key}`"
                class="rounded border px-3 py-1.5 text-sm"
                :class="
                    tab === t.key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300'
                "
                @click="tab = t.key"
            >
                {{ t.label }}
            </button>
        </div>

        <section
            v-show="tab === 'roles'"
            id="panel-roles"
            role="tabpanel"
            aria-labelledby="tab-roles"
            class="mt-4"
        >
            <ul class="grid gap-3 md:grid-cols-2">
                <li
                    v-for="r in roles"
                    :key="r.key"
                    class="rounded border border-slate-200 p-4 text-sm"
                    :data-testid="`role-${r.key}`"
                >
                    <h2 class="text-base font-semibold">
                        {{ r.name }}
                        <span v-if="r.addOn" class="text-xs font-normal text-slate-600"
                            >(add-on)</span
                        >
                    </h2>
                    <p class="text-xs text-slate-500">{{ r.group }}</p>
                    <p v-if="r.purpose" class="mt-1 text-slate-700">{{ r.purpose }}</p>
                    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                        <dt class="text-slate-500">Capabilities</dt>
                        <dd>{{ r.capabilityCount }}</dd>
                        <dt class="text-slate-500">Assignment scope</dt>
                        <dd>
                            {{
                                r.campusScopable
                                    ? 'Whole School or one Campus'
                                    : 'Whole School only'
                            }}
                        </dd>
                        <dt class="text-slate-500">Access administration</dt>
                        <dd>{{ r.adminAuthority ? 'Yes' : 'No' }}</dd>
                        <dt class="text-slate-500">Modules</dt>
                        <dd>{{ r.modules.join(', ') || '—' }}</dd>
                    </dl>
                    <p v-if="r.sensitivity.length" class="mt-2 text-xs">
                        <span class="text-slate-500">Reaches:</span> {{ r.sensitivity.join(', ') }}
                    </p>
                    <button
                        type="button"
                        class="mt-2 text-sm underline"
                        :aria-expanded="openRole === r.key"
                        :aria-controls="`role-details-${r.key}`"
                        @click="openRole = openRole === r.key ? null : r.key"
                    >
                        {{ openRole === r.key ? 'Hide capabilities' : 'Show capabilities'
                        }}<span class="sr-only"> of {{ r.name }}</span>
                    </button>
                    <div v-if="openRole === r.key" :id="`role-details-${r.key}`" class="mt-2">
                        <div v-for="g in r.capabilities" :key="g.module" class="mt-2">
                            <h3 class="text-xs font-semibold uppercase text-slate-600">
                                {{ g.module }}
                            </h3>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="c in g.capabilities" :key="c.key">
                                    {{ c.label }}
                                    <span class="text-xs text-slate-500">
                                        · {{ c.read ? 'Read' : 'Manage' }}
                                        <template v-if="c.sensitive"> · Sensitive</template>
                                        <template v-if="c.approval"> · Approval</template>
                                        <template v-if="c.assurance"> · Fresh MFA</template>
                                        <template v-if="c.campus"> · Campus-scopable</template>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <p v-if="r.sensitiveFamilies.length" class="mt-2 text-xs">
                            <span class="text-slate-500">Sensitive data reached:</span>
                            {{ r.sensitiveFamilies.map(familyLabel).join(', ') }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <section
            v-show="tab === 'matrix'"
            id="panel-matrix"
            role="tabpanel"
            aria-labelledby="tab-matrix"
            class="mt-4"
        >
            <p class="text-sm text-slate-600">
                Each cell summarises what a role can do in a module: Read, Manage or nothing.
            </p>
            <div
                class="relative mt-3 overflow-x-auto"
                tabindex="0"
                role="region"
                aria-label="Role matrix (scrolls sideways)"
            >
                <table class="min-w-full text-left text-xs" data-testid="role-matrix">
                    <caption class="sr-only">
                        Role templates by module
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="sticky left-0 bg-white py-2 pr-3">Role</th>
                            <th
                                v-for="m in modules"
                                :key="m.key"
                                scope="col"
                                class="whitespace-nowrap px-2 py-2"
                            >
                                {{ m.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="row in matrix" :key="row.role">
                            <th
                                scope="row"
                                class="sticky left-0 whitespace-nowrap bg-white py-2 pr-3 font-medium"
                            >
                                {{ row.name }}
                            </th>
                            <td
                                v-for="(cell, i) in row.cells"
                                :key="i"
                                class="px-2 py-2"
                                :class="
                                    cell === 'manage'
                                        ? 'font-semibold'
                                        : cell === 'none'
                                          ? 'text-slate-400'
                                          : ''
                                "
                            >
                                {{ cellText[cell] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-show="tab === 'capabilities'"
            id="panel-capabilities"
            role="tabpanel"
            aria-labelledby="tab-capabilities"
            class="mt-4"
        >
            <form class="grid gap-3 sm:grid-cols-4" @submit.prevent>
                <div>
                    <label class="block text-sm text-slate-700" for="cap-module">Module</label>
                    <select
                        id="cap-module"
                        v-model="moduleFilter"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="">All modules</option>
                        <option v-for="m in modules" :key="m.key" :value="m.key">
                            {{ m.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="cap-kind">Kind</label>
                    <select
                        id="cap-kind"
                        v-model="kindFilter"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="">Read and manage</option>
                        <option value="read">Read</option>
                        <option value="manage">Manage</option>
                    </select>
                </div>
                <label class="mt-6 flex items-center gap-2 text-sm">
                    <input v-model="sensitiveOnly" type="checkbox" /> Sensitive only
                </label>
                <label class="mt-6 flex items-center gap-2 text-sm">
                    <input v-model="campusOnly" type="checkbox" /> Campus-scopable only
                </label>
            </form>
            <div v-for="m in filteredModules" :key="m.key" class="mt-5">
                <h2 class="text-base font-semibold">
                    {{ m.label }}
                    <span class="text-xs font-normal text-slate-500">{{
                        m.core ? 'Core' : 'Optional module'
                    }}</span>
                </h2>
                <p class="text-xs text-slate-600">{{ m.description }}</p>
                <ul class="mt-2 divide-y divide-slate-100 rounded border border-slate-200 text-sm">
                    <li v-for="c in m.capabilities" :key="c.key" class="px-3 py-2">
                        {{ c.label }}
                        <span class="block text-xs text-slate-500">
                            {{ c.read ? 'Read' : 'Manage' }}
                            <template v-if="c.sensitive"> · Sensitive</template>
                            <template v-if="c.approval"> · Approval decision</template>
                            <template v-if="c.assurance">
                                · Fresh MFA on consequential actions</template
                            >
                            <template v-if="c.campus"> · Can be limited to a Campus</template>
                        </span>
                    </li>
                </ul>
            </div>
            <p v-if="!filteredModules.length" class="mt-4 text-sm text-slate-600">
                No capability matches these filters.
            </p>
        </section>

        <section
            v-show="tab === 'sensitive'"
            id="panel-sensitive"
            role="tabpanel"
            aria-labelledby="tab-sensitive"
            class="mt-4"
        >
            <p class="max-w-3xl text-sm text-slate-600">
                The sensitive personal data Lycenza holds, and the capability that already protects
                each. A School-specific restriction can only ever narrow access, and is offered only
                once every page, API, search and export showing the data enforces it on the server —
                none qualifies yet, so nothing here is switchable today.
            </p>
            <ul class="mt-3 grid gap-3 md:grid-cols-2">
                <li
                    v-for="f in sensitive.families"
                    :key="f.key"
                    class="rounded border border-slate-200 p-4 text-sm"
                    :data-testid="`family-${f.key}`"
                >
                    <h2 class="font-semibold">{{ f.label }}</h2>
                    <p class="text-xs text-slate-500">{{ f.domain }} · {{ f.classification }}</p>
                    <p class="mt-1">{{ f.fields.join(', ') }}</p>
                    <p class="mt-2 text-xs">
                        <span class="font-medium">School restriction:</span>
                        {{ f.configurable ? 'Available' : 'Not available yet' }} — {{ f.reason }}
                    </p>
                </li>
            </ul>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <h2 class="text-sm font-semibold">Never shown anywhere</h2>
                    <ul class="mt-1 list-disc pl-5 text-sm text-slate-700">
                        <li v-for="x in sensitive.neverExposed" :key="x">{{ x }}</li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">Not stored by Lycenza</h2>
                    <ul class="mt-1 list-disc pl-5 text-sm text-slate-700">
                        <li v-for="x in sensitive.notStored" :key="x">{{ x }}</li>
                    </ul>
                </div>
            </div>
        </section>
    </main>
</template>
