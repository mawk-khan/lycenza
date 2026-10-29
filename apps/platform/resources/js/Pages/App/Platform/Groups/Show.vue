<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 5): one School Group, platform governance.
// Schools are added by exact UUID or verified domain, people by exact email
// or UUID -- no search. The grant list (Sensitive) is present only for
// holders of platform.school_group_grants.manage.
// ORG.12D (ADR 0080 §9): the network structure -- Regions, Zones and each
// member School's relationship classification, placement and network
// reference (NetworkGovernanceWorkspace). Adding, removing or reclassifying a
// School needs a reason and a fresh authentication code; moving it between
// Regions/Zones needs a reason. None of it gives anyone access to a School.
// ORG.12E (ADR 0080 §10): the platform also appoints Network Managers and
// read-only Network Viewers (whole Group, one Region or one Zone) -- a
// reason and a fresh authentication code. Group Admin grants are unchanged.
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import GroupOverviewPanel from '../../../../Components/Groups/GroupOverviewPanel.vue';
import NetworkGovernanceWorkspace from '../../../../Components/Groups/NetworkGovernanceWorkspace.vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import type { DirectoryRow, GroupOverview, Option, Paginated } from '../../../../groupNetwork';

interface Grant {
    id: string;
    userName: string;
    userEmail: string;
    role: string;
    roleName: string;
    scope: string;
    grantedAt: string;
}

const props = defineProps<{
    group: { id: string; name: string; slug: string; status: string };
    overview: GroupOverview;
    directory: Paginated<DirectoryRow>;
    filters: Record<string, string | undefined>;
    relationships: Option[];
    reasons: Option[];
    grantReasons: Option[];
    groupRoles: Option[];
    grants: Grant[] | null;
    canManage: boolean;
    canManageGrants: boolean;
    hasMfaFactor: boolean;
}>();

const base = `/app/platform/groups/${props.group.id}`;
const editable = computed(() => props.canManage && props.group.status === 'active');
const renameForm = useForm({ name: props.group.name });
const grantForm = useForm({
    user: '',
    role: 'group_admin',
    region_id: '',
    zone_id: '',
    reason_code: '',
    mfa_code: '',
});
const networkRole = computed(() => grantForm.role !== 'group_admin');
const revokeForm = useForm({ reason_code: '', mfa_code: '' });
// The server reports an already-revoked grant under `grant`.
const grantError = computed(() => (revokeForm.errors as Record<string, string | undefined>).grant);
const addForm = useForm({
    school: '',
    relationship_type: '',
    network_reference: '',
    reason_code: 'network_join',
    mfa_code: '',
});

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
function grant() {
    grantForm.post(`${base}/grants`, {
        preserveScroll: true,
        onSuccess: () => grantForm.reset(),
        onFinish: () => grantForm.reset('mfa_code'),
    });
}
function revoke(g: Grant) {
    if (g.role === 'group_admin') {
        if (confirm('Revoke this Group grant? Any elevated access it authorized ends now.')) {
            router.post(`${base}/grants/${g.id}/revoke`);
        }
        return;
    }
    revokeForm.post(`${base}/grants/${g.id}/revoke`, {
        preserveScroll: true,
        onFinish: () => revokeForm.reset('mfa_code'),
    });
}
function archive() {
    if (confirm('Archive this Group? All its grants are revoked and its elevated access ends.')) {
        router.post(`${base}/archive`);
    }
}
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

        <NetworkGovernanceWorkspace
            :page-url="base"
            :action-base="base"
            :overview="overview"
            :directory="directory"
            :filters="filters"
            :relationships="relationships"
            :reasons="reasons"
            :has-mfa-factor="hasMfaFactor"
            :editable="editable"
        />

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

        <section v-if="grants !== null" aria-labelledby="grants-heading" class="mt-8">
            <h2 id="grants-heading" class="text-base font-semibold">Group grants</h2>
            <ul class="mt-2 space-y-1 text-sm" data-testid="group-grants">
                <li v-for="g in grants" :key="g.id" class="flex flex-wrap justify-between gap-4">
                    <span
                        >{{ g.userName }} ({{ g.userEmail }}) — {{ g.roleName }} ·
                        {{ g.scope }}</span
                    >
                    <button v-if="canManageGrants" class="underline" @click="revoke(g)">
                        Revoke<span class="sr-only"> {{ g.userName }}</span>
                    </button>
                </li>
            </ul>
            <p v-if="!grants.length" class="mt-2 text-sm text-slate-600">No active grants.</p>
            <div
                v-if="canManageGrants && grants.some((g) => g.role !== 'group_admin')"
                class="mt-3 grid gap-3 sm:grid-cols-2"
            >
                <p class="text-sm text-slate-600 sm:col-span-2">
                    Revoking a Network role needs a reason and a fresh authentication code.
                </p>
                <div>
                    <label class="block text-sm text-slate-700" for="revoke-reason"
                        >Revocation reason</label
                    >
                    <select
                        id="revoke-reason"
                        v-model="revokeForm.reason_code"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="r in grantReasons" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                </div>
                <MfaCodeField
                    id="revoke-mfa"
                    v-model="revokeForm.mfa_code"
                    :has-mfa-factor="hasMfaFactor"
                    action="revoke a Network role"
                    :error="revokeForm.errors.mfa_code"
                />
                <p
                    v-if="grantError || revokeForm.errors.reason_code"
                    class="text-sm text-red-700 sm:col-span-2"
                    role="alert"
                >
                    {{ grantError ?? revokeForm.errors.reason_code }}
                </p>
            </div>
            <form
                v-if="canManageGrants && group.status === 'active'"
                class="mt-4 grid gap-3 sm:grid-cols-2"
                @submit.prevent="grant"
            >
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="grant-user"
                        >Person to grant</label
                    >
                    <input
                        id="grant-user"
                        v-model="grantForm.user"
                        placeholder="Exact email or account id"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="grantForm.errors.user" class="mt-1 text-sm text-red-700">
                        {{ grantForm.errors.user }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="grant-role">Group role</label>
                    <select
                        id="grant-role"
                        v-model="grantForm.role"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option v-for="r in groupRoles" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                    <p v-if="grantForm.errors.role" class="mt-1 text-sm text-red-700">
                        {{ grantForm.errors.role }}
                    </p>
                </div>
                <template v-if="grantForm.role === 'network_viewer'">
                    <div>
                        <label class="block text-sm text-slate-700" for="grant-region"
                            >Region scope (optional)</label
                        >
                        <select
                            id="grant-region"
                            v-model="grantForm.region_id"
                            :disabled="grantForm.zone_id !== ''"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                        >
                            <option value="">Whole Group</option>
                            <option v-for="r in overview.regions" :key="r.id" :value="r.id">
                                {{ r.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-700" for="grant-zone"
                            >Zone scope (optional)</label
                        >
                        <select
                            id="grant-zone"
                            v-model="grantForm.zone_id"
                            :disabled="grantForm.region_id !== ''"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                        >
                            <option value="">Whole Group</option>
                            <option v-for="z in overview.zones" :key="z.id" :value="z.id">
                                {{ z.name }}
                            </option>
                        </select>
                        <p
                            v-if="grantForm.errors.zone_id || grantForm.errors.region_id"
                            class="mt-1 text-sm text-red-700"
                        >
                            {{ grantForm.errors.zone_id ?? grantForm.errors.region_id }}
                        </p>
                    </div>
                </template>
                <template v-if="networkRole">
                    <div class="sm:col-span-2">
                        <label class="block text-sm text-slate-700" for="grant-reason"
                            >Reason</label
                        >
                        <select
                            id="grant-reason"
                            v-model="grantForm.reason_code"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                        >
                            <option value="" disabled>Choose…</option>
                            <option v-for="r in grantReasons" :key="r.code" :value="r.code">
                                {{ r.label }}
                            </option>
                        </select>
                        <p v-if="grantForm.errors.reason_code" class="mt-1 text-sm text-red-700">
                            {{ grantForm.errors.reason_code }}
                        </p>
                    </div>
                    <div class="sm:col-span-2">
                        <MfaCodeField
                            id="grant-mfa"
                            v-model="grantForm.mfa_code"
                            :has-mfa-factor="hasMfaFactor"
                            action="grant a Network role"
                            :error="grantForm.errors.mfa_code"
                        />
                    </div>
                </template>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="grantForm.processing || (networkRole && !hasMfaFactor)"
                        class="rounded border border-slate-300 px-3 py-2 text-sm disabled:opacity-50"
                    >
                        Grant role
                    </button>
                </div>
            </form>
        </section>

        <p v-if="editable" class="mt-8">
            <button class="text-sm text-red-800 underline" @click="archive">
                Archive this Group
            </button>
        </p>
        <p class="mt-6 text-sm"><a class="underline" href="/app/platform/groups">All Groups</a></p>
    </main>
</template>
