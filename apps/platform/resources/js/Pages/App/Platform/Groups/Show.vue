<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 5): one School Group, platform governance.
// Schools are added by exact UUID or verified domain, people by exact email
// or UUID -- no search. The grant list (Sensitive) is present only for
// holders of platform.school_group_grants.manage.
// ORG.12D (ADR 0080 §9): the network structure -- Regions, Zones and each
// member School's relationship classification, placement and network
// reference. Adding, removing or reclassifying a School needs a reason and a
// fresh authentication code; moving it between Regions/Zones needs a reason.
// None of it gives anyone access to a School.
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GroupDirectory from '../../../../Components/Groups/GroupDirectory.vue';
import GroupOverviewPanel from '../../../../Components/Groups/GroupOverviewPanel.vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import type {
    DirectoryRow,
    GroupOverview,
    Option,
    Paginated,
    StructureItem,
} from '../../../../groupNetwork';

interface Grant {
    id: string;
    userName: string;
    userEmail: string;
    role: string;
    grantedAt: string;
}

const props = defineProps<{
    group: { id: string; name: string; slug: string; status: string };
    overview: GroupOverview;
    directory: Paginated<DirectoryRow>;
    filters: Record<string, string | undefined>;
    relationships: Option[];
    reasons: Option[];
    grants: Grant[] | null;
    canManage: boolean;
    canManageGrants: boolean;
    hasMfaFactor: boolean;
}>();

const base = `/app/platform/groups/${props.group.id}`;
const editable = computed(() => props.canManage && props.group.status === 'active');
const renameForm = useForm({ name: props.group.name });
const grantForm = useForm({ user: '' });
const addForm = useForm({
    school: '',
    relationship_type: '',
    network_reference: '',
    reason_code: 'network_join',
    mfa_code: '',
});
const regionForm = useForm({ code: '', name: '', description: '', sort_order: 0 });
const zoneForm = useForm({ code: '', name: '', description: '', sort_order: 0, region_id: '' });

// One membership panel at a time: classification (MFA), placement or removal (MFA).
const selected = ref<DirectoryRow | null>(null);
const mode = ref<'classify' | 'place' | 'remove' | null>(null);
const classifyForm = useForm({
    relationship_type: '',
    network_reference: '',
    expected_relationship_type: '' as string | null,
    expected_network_reference: '' as string | null,
    reason_code: '',
    mfa_code: '',
});
const placeForm = useForm({
    region_id: '',
    zone_id: '',
    expected_region_id: null as string | null,
    expected_zone_id: null as string | null,
    reason_code: '',
});
const removeForm = useForm({ reason_code: 'network_exit', mfa_code: '' });

function open(school: DirectoryRow, next: 'classify' | 'place' | 'remove') {
    selected.value = school;
    mode.value = next;
    classifyForm.reset();
    classifyForm.clearErrors();
    classifyForm.relationship_type = school.relationship ?? '';
    classifyForm.network_reference = school.networkReference ?? '';
    classifyForm.expected_relationship_type = school.relationship;
    classifyForm.expected_network_reference = school.networkReference;
    placeForm.reset();
    placeForm.clearErrors();
    placeForm.region_id = school.region?.id ?? '';
    placeForm.zone_id = school.zone?.id ?? '';
    placeForm.expected_region_id = school.region?.id ?? null;
    placeForm.expected_zone_id = school.zone?.id ?? null;
    removeForm.reset();
    removeForm.clearErrors();
}
function close() {
    selected.value = null;
    mode.value = null;
}
const done = { preserveScroll: true, onSuccess: () => close() };

function rename() {
    renameForm.put(base);
}
function addSchool() {
    addForm.post(`${base}/schools`, {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
        onFinish: () => addForm.reset('mfa_code'),
    });
}
function submitClassify() {
    classifyForm.put(`${base}/schools/${selected.value?.id}/classification`, {
        ...done,
        onFinish: () => classifyForm.reset('mfa_code'),
    });
}
function submitPlace() {
    placeForm.put(`${base}/schools/${selected.value?.id}/placement`, done);
}
function submitRemove() {
    removeForm.delete(`${base}/schools/${selected.value?.id}`, {
        ...done,
        onFinish: () => removeForm.reset('mfa_code'),
    });
}
function addRegion() {
    regionForm.post(`${base}/regions`, {
        preserveScroll: true,
        onSuccess: () => regionForm.reset(),
    });
}
function addZone() {
    zoneForm.post(`${base}/zones`, { preserveScroll: true, onSuccess: () => zoneForm.reset() });
}
function setStatus(kind: 'regions' | 'zones', item: StructureItem, status: 'active' | 'inactive') {
    router.put(`${base}/${kind}/${item.id}`, { status }, { preserveScroll: true });
}
function grant() {
    grantForm.post(`${base}/grants`, { onSuccess: () => grantForm.reset() });
}
function revoke(id: string) {
    if (confirm('Revoke this Group grant? Any elevated access it authorized ends now.')) {
        router.post(`${base}/grants/${id}/revoke`);
    }
}
function archive() {
    if (confirm('Archive this Group? All its grants are revoked and its elevated access ends.')) {
        router.post(`${base}/archive`);
    }
}
// Server errors not tied to a form field (stale form, unknown School).
const err = (form: { errors: object }, key: string) =>
    (form.errors as Record<string, string | undefined>)[key];
const regionName = (id: string | null | undefined) =>
    props.overview.regions.find((r) => r.id === id)?.name ?? '—';
const zonesForRegion = computed(() =>
    props.overview.zones.filter(
        (z) =>
            placeForm.region_id === '' || z.regionId === placeForm.region_id || z.regionId === null,
    ),
);
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <h1 class="text-xl font-semibold" data-testid="group-name">{{ group.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ group.slug }} · {{ group.status }} · School Group (network)
        </p>

        <form v-if="editable" class="mt-4 flex gap-2" @submit.prevent="rename">
            <label class="sr-only" for="group-name-input">Group name</label>
            <input
                id="group-name-input"
                v-model="renameForm.name"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm">
                Rename
            </button>
        </form>
        <p v-if="renameForm.errors.name" class="mt-1 text-sm text-red-700">
            {{ renameForm.errors.name }}
        </p>

        <GroupOverviewPanel :overview="overview" />

        <section aria-labelledby="structure-heading" class="mt-8">
            <h2 id="structure-heading" class="text-base font-semibold">Regions and Zones</h2>
            <p class="mt-1 text-sm text-slate-600">
                Optional groupings for running the network. Inactivating one moves no School.
            </p>
            <div class="mt-3 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-sm font-medium">Regions</h3>
                    <ul
                        class="mt-2 divide-y divide-slate-100 rounded border border-slate-200 text-sm"
                    >
                        <li
                            v-for="r in overview.regions"
                            :key="r.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span
                                >{{ r.name }}
                                <span class="text-slate-500"
                                    >· {{ r.code }} · {{ r.schools }} School(s)</span
                                >
                                <span v-if="r.status === 'inactive'" class="text-amber-800">
                                    · inactive</span
                                ></span
                            >
                            <button
                                v-if="editable"
                                type="button"
                                class="underline"
                                @click="
                                    setStatus(
                                        'regions',
                                        r,
                                        r.status === 'active' ? 'inactive' : 'active',
                                    )
                                "
                            >
                                {{ r.status === 'active' ? 'Inactivate' : 'Reactivate'
                                }}<span class="sr-only"> {{ r.name }}</span>
                            </button>
                        </li>
                        <li v-if="!overview.regions.length" class="px-3 py-2 text-slate-600">
                            No Regions.
                        </li>
                    </ul>
                    <form
                        v-if="editable"
                        class="mt-3 grid gap-2 sm:grid-cols-2"
                        @submit.prevent="addRegion"
                    >
                        <div>
                            <label class="block text-sm text-slate-700" for="region-code"
                                >Code</label
                            >
                            <input
                                id="region-code"
                                v-model="regionForm.code"
                                maxlength="32"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                                :aria-invalid="!!regionForm.errors.code"
                                aria-describedby="region-code-error"
                            />
                            <p
                                v-if="regionForm.errors.code"
                                id="region-code-error"
                                class="mt-1 text-sm text-red-700"
                            >
                                {{ regionForm.errors.code }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-slate-700" for="region-name"
                                >Name</label
                            >
                            <input
                                id="region-name"
                                v-model="regionForm.name"
                                maxlength="150"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                            />
                            <p v-if="regionForm.errors.name" class="mt-1 text-sm text-red-700">
                                {{ regionForm.errors.name }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <button
                                type="submit"
                                class="rounded border border-slate-300 px-3 py-2 text-sm"
                            >
                                Add Region
                            </button>
                        </div>
                    </form>
                </div>
                <div>
                    <h3 class="text-sm font-medium">Zones</h3>
                    <ul
                        class="mt-2 divide-y divide-slate-100 rounded border border-slate-200 text-sm"
                    >
                        <li
                            v-for="z in overview.zones"
                            :key="z.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span
                                >{{ z.name }}
                                <span class="text-slate-500"
                                    >· {{ z.code }} · {{ regionName(z.regionId) }} ·
                                    {{ z.schools }} School(s)</span
                                >
                                <span v-if="z.status === 'inactive'" class="text-amber-800">
                                    · inactive</span
                                ></span
                            >
                            <button
                                v-if="editable"
                                type="button"
                                class="underline"
                                @click="
                                    setStatus(
                                        'zones',
                                        z,
                                        z.status === 'active' ? 'inactive' : 'active',
                                    )
                                "
                            >
                                {{ z.status === 'active' ? 'Inactivate' : 'Reactivate'
                                }}<span class="sr-only"> {{ z.name }}</span>
                            </button>
                        </li>
                        <li v-if="!overview.zones.length" class="px-3 py-2 text-slate-600">
                            No Zones.
                        </li>
                    </ul>
                    <form
                        v-if="editable"
                        class="mt-3 grid gap-2 sm:grid-cols-2"
                        @submit.prevent="addZone"
                    >
                        <div>
                            <label class="block text-sm text-slate-700" for="zone-code">Code</label>
                            <input
                                id="zone-code"
                                v-model="zoneForm.code"
                                maxlength="32"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                            />
                            <p v-if="zoneForm.errors.code" class="mt-1 text-sm text-red-700">
                                {{ zoneForm.errors.code }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-slate-700" for="zone-name">Name</label>
                            <input
                                id="zone-name"
                                v-model="zoneForm.name"
                                maxlength="150"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                            />
                            <p v-if="zoneForm.errors.name" class="mt-1 text-sm text-red-700">
                                {{ zoneForm.errors.name }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm text-slate-700" for="zone-region"
                                >Region (optional)</label
                            >
                            <select
                                id="zone-region"
                                v-model="zoneForm.region_id"
                                class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                            >
                                <option value="">No Region</option>
                                <option
                                    v-for="r in overview.regions.filter(
                                        (x) => x.status === 'active',
                                    )"
                                    :key="r.id"
                                    :value="r.id"
                                >
                                    {{ r.name }}
                                </option>
                            </select>
                            <p v-if="zoneForm.errors.region_id" class="mt-1 text-sm text-red-700">
                                {{ zoneForm.errors.region_id }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <button
                                type="submit"
                                class="rounded border border-slate-300 px-3 py-2 text-sm"
                            >
                                Add Zone
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <GroupDirectory
            :base-url="base"
            :directory="directory"
            :filters="filters"
            :relationships="relationships"
            :regions="overview.regions"
            :zones="overview.zones"
        >
            <template #actions="{ school }">
                <span v-if="editable" class="flex flex-wrap justify-end gap-3">
                    <button type="button" class="underline" @click="open(school, 'classify')">
                        Classify<span class="sr-only"> {{ school.name }}</span>
                    </button>
                    <button type="button" class="underline" @click="open(school, 'place')">
                        Place<span class="sr-only"> {{ school.name }}</span>
                    </button>
                    <button
                        type="button"
                        class="text-red-800 underline"
                        @click="open(school, 'remove')"
                    >
                        Remove<span class="sr-only"> {{ school.name }}</span>
                    </button>
                </span>
            </template>
        </GroupDirectory>

        <section
            v-if="selected && mode"
            aria-labelledby="membership-heading"
            class="mt-6 rounded border border-slate-300 p-4"
        >
            <h2 id="membership-heading" class="text-base font-semibold">
                {{
                    mode === 'classify'
                        ? 'Change classification'
                        : mode === 'place'
                          ? 'Change Region / Zone'
                          : 'Remove from the Group'
                }}
                — {{ selected.name }}
            </h2>
            <p class="mt-1 text-sm text-slate-600">
                Current: {{ selected.relationshipLabel }} ·
                {{ selected.region?.name ?? 'No Region' }} · {{ selected.zone?.name ?? 'No Zone' }}
            </p>
            <form
                v-if="mode === 'classify'"
                class="mt-3 grid gap-3 sm:grid-cols-2"
                @submit.prevent="submitClassify"
            >
                <div>
                    <label class="block text-sm text-slate-700" for="classify-relationship"
                        >New relationship</label
                    >
                    <select
                        id="classify-relationship"
                        v-model="classifyForm.relationship_type"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="r in relationships" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                    <p
                        v-if="classifyForm.errors.relationship_type"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ classifyForm.errors.relationship_type }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="classify-reference"
                        >Network reference (optional)</label
                    >
                    <input
                        id="classify-reference"
                        v-model="classifyForm.network_reference"
                        maxlength="40"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p
                        v-if="classifyForm.errors.network_reference"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ classifyForm.errors.network_reference }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="classify-reason">Reason</label>
                    <select
                        id="classify-reason"
                        v-model="classifyForm.reason_code"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="r in reasons" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                    <p v-if="classifyForm.errors.reason_code" class="mt-1 text-sm text-red-700">
                        {{ classifyForm.errors.reason_code }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <MfaCodeField
                        id="classify-mfa"
                        v-model="classifyForm.mfa_code"
                        :has-mfa-factor="hasMfaFactor"
                        action="change this School's classification"
                        :error="classifyForm.errors.mfa_code"
                    />
                </div>
                <p
                    v-if="err(classifyForm, 'membership')"
                    class="text-sm text-red-700 sm:col-span-2"
                    role="alert"
                >
                    {{ err(classifyForm, 'membership') }}
                </p>
                <div class="flex gap-3 sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="classifyForm.processing || !hasMfaFactor"
                        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Save classification
                    </button>
                    <button
                        type="button"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                        @click="close"
                    >
                        Cancel
                    </button>
                </div>
            </form>
            <form
                v-else-if="mode === 'place'"
                class="mt-3 grid gap-3 sm:grid-cols-2"
                @submit.prevent="submitPlace"
            >
                <div>
                    <label class="block text-sm text-slate-700" for="place-region">Region</label>
                    <select
                        id="place-region"
                        v-model="placeForm.region_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="">No Region</option>
                        <option v-for="r in overview.regions" :key="r.id" :value="r.id">
                            {{ r.name }}{{ r.status === 'inactive' ? ' (inactive)' : '' }}
                        </option>
                    </select>
                    <p v-if="placeForm.errors.region_id" class="mt-1 text-sm text-red-700">
                        {{ placeForm.errors.region_id }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="place-zone">Zone</label>
                    <select
                        id="place-zone"
                        v-model="placeForm.zone_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="">No Zone</option>
                        <option v-for="z in zonesForRegion" :key="z.id" :value="z.id">
                            {{ z.name }}{{ z.status === 'inactive' ? ' (inactive)' : '' }}
                        </option>
                    </select>
                    <p v-if="placeForm.errors.zone_id" class="mt-1 text-sm text-red-700">
                        {{ placeForm.errors.zone_id }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="place-reason">Reason</label>
                    <select
                        id="place-reason"
                        v-model="placeForm.reason_code"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="r in reasons" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                    <p v-if="placeForm.errors.reason_code" class="mt-1 text-sm text-red-700">
                        {{ placeForm.errors.reason_code }}
                    </p>
                </div>
                <p
                    v-if="err(placeForm, 'membership')"
                    class="text-sm text-red-700 sm:col-span-2"
                    role="alert"
                >
                    {{ err(placeForm, 'membership') }}
                </p>
                <div class="flex gap-3 sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="placeForm.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Save placement
                    </button>
                    <button
                        type="button"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                        @click="close"
                    >
                        Cancel
                    </button>
                </div>
            </form>
            <form v-else class="mt-3 grid gap-3" @submit.prevent="submitRemove">
                <p class="text-sm">
                    The School leaves this Group only. Its data, Campuses and other Groups are
                    untouched; Group-derived elevated access into it ends now.
                </p>
                <div>
                    <label class="block text-sm text-slate-700" for="remove-reason">Reason</label>
                    <select
                        id="remove-reason"
                        v-model="removeForm.reason_code"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option v-for="r in reasons" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                </div>
                <MfaCodeField
                    id="remove-mfa"
                    v-model="removeForm.mfa_code"
                    :has-mfa-factor="hasMfaFactor"
                    action="remove this School from the Group"
                    :error="removeForm.errors.mfa_code"
                />
                <p v-if="err(removeForm, 'school')" class="text-sm text-red-700" role="alert">
                    {{ err(removeForm, 'school') }}
                </p>
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="removeForm.processing || !hasMfaFactor"
                        class="rounded bg-red-800 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Remove from Group
                    </button>
                    <button
                        type="button"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                        @click="close"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <section v-if="editable" aria-labelledby="add-school-heading" class="mt-8">
            <h2 id="add-school-heading" class="text-base font-semibold">Add a School</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="addSchool">
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="add-school">School</label>
                    <input
                        id="add-school"
                        v-model="addForm.school"
                        placeholder="Exact School id or verified domain"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="addForm.errors.school" class="mt-1 text-sm text-red-700">
                        {{ addForm.errors.school }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="add-relationship"
                        >Relationship</label
                    >
                    <select
                        id="add-relationship"
                        v-model="addForm.relationship_type"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="r in relationships" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                    <p v-if="addForm.errors.relationship_type" class="mt-1 text-sm text-red-700">
                        {{ addForm.errors.relationship_type }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="add-reference"
                        >Network reference (optional)</label
                    >
                    <input
                        id="add-reference"
                        v-model="addForm.network_reference"
                        maxlength="40"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="addForm.errors.network_reference" class="mt-1 text-sm text-red-700">
                        {{ addForm.errors.network_reference }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <MfaCodeField
                        id="add-mfa"
                        v-model="addForm.mfa_code"
                        :has-mfa-factor="hasMfaFactor"
                        action="add a School to the Group"
                        :error="addForm.errors.mfa_code"
                    />
                </div>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="addForm.processing || !hasMfaFactor"
                        class="rounded border border-slate-300 px-3 py-2 text-sm disabled:opacity-50"
                    >
                        Add School
                    </button>
                </div>
            </form>
        </section>

        <template v-if="grants !== null">
            <h2 class="mt-8 text-base font-semibold">Group grants</h2>
            <ul class="mt-2 space-y-1 text-sm" data-testid="group-grants">
                <li v-for="g in grants" :key="g.id" class="flex justify-between gap-4">
                    <span>{{ g.userName }} ({{ g.userEmail }}) — {{ g.role }}</span>
                    <button v-if="canManageGrants" class="underline" @click="revoke(g.id)">
                        Revoke
                    </button>
                </li>
            </ul>
            <p v-if="!grants.length" class="mt-2 text-sm text-slate-600">No active grants.</p>
            <form
                v-if="canManageGrants && group.status === 'active'"
                class="mt-3 flex gap-2"
                @submit.prevent="grant"
            >
                <label class="sr-only" for="grant-user">Person to grant</label>
                <input
                    id="grant-user"
                    v-model="grantForm.user"
                    placeholder="Exact email or account id"
                    class="w-full rounded border border-slate-300 px-3 py-2"
                />
                <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm">
                    Grant Group Admin
                </button>
            </form>
            <p v-if="grantForm.errors.user" class="mt-1 text-sm text-red-700">
                {{ grantForm.errors.user }}
            </p>
        </template>

        <p v-if="editable" class="mt-8">
            <button class="text-sm text-red-800 underline" @click="archive">
                Archive this Group
            </button>
        </p>
        <p class="mt-6 text-sm"><a class="underline" href="/app/platform/groups">All Groups</a></p>
    </main>
</template>
