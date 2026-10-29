<script setup lang="ts">
// ORG.12E (ADR 0080 §10.6): Network Access for ONE Group, in Group context.
// - Network roles: the code-owned Group roles and what each may do (labels,
//   never authority; no role grants any School access).
// - Your governance access: the viewer's own Group roles and read scope.
// - Network assignments (Network Manager / platform only, Sensitive): the
//   Group's active Network roles; a Network Manager grants and revokes the
//   read-only Network Viewer -- whole Group, one Region or one Zone -- with a
//   reason and a fresh authentication code. Never a manager, never oneself.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';
import type { GovernanceScope, Option, StructureItem } from '../../../groupNetwork';

interface GroupRoleCard {
    key: string;
    name: string;
    capabilities: string[];
    scopable: boolean;
    grantsSchoolAccess: boolean;
}
interface Assignment {
    id: string;
    role: string;
    roleName: string;
    userName: string;
    userEmail: string;
    scope: string;
    effective: boolean;
    grantedAt: string;
}

const props = defineProps<{
    group: { id: string; name: string; status: string };
    roles: GroupRoleCard[];
    own: {
        scope: GovernanceScope;
        roles: Array<{ roleName: string; scope: string }>;
        canManage: boolean;
    };
    assignments: Assignment[] | null;
    canManageOperators: boolean;
    regions: StructureItem[];
    zones: StructureItem[];
    reasons: Option[];
    hasMfaFactor: boolean;
}>();

const base = `/app/groups/${props.group.id}`;
const grantForm = useForm({ user: '', region_id: '', zone_id: '', reason_code: '', mfa_code: '' });
const revokeForm = useForm({ reason_code: '', mfa_code: '' });
const revokeError = computed(() => (revokeForm.errors as Record<string, string | undefined>).grant);

function grant() {
    grantForm.post(`${base}/access/operators`, {
        preserveScroll: true,
        onSuccess: () => grantForm.reset(),
        onFinish: () => grantForm.reset('mfa_code'),
    });
}
function revoke(a: Assignment) {
    revokeForm.post(`${base}/access/operators/${a.id}/revoke`, {
        preserveScroll: true,
        onFinish: () => revokeForm.reset('mfa_code'),
    });
}
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <h1 class="text-xl font-semibold">Network access — {{ group.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">
            Who can see and govern this network. Network roles never give access to a School's
            records; School access is managed by each School.
        </p>

        <section aria-labelledby="own-heading" class="mt-6">
            <h2 id="own-heading" class="text-base font-semibold">Your governance access</h2>
            <ul class="mt-2 space-y-1 text-sm" data-testid="own-roles">
                <li v-for="(r, i) in own.roles" :key="i">{{ r.roleName }} — {{ r.scope }}</li>
            </ul>
            <p class="mt-2 text-sm text-slate-600">
                {{
                    own.canManage
                        ? 'You can manage this network’s Regions, Zones and member-School classification.'
                        : 'Read-only: you can see the network directory within your scope.'
                }}
                No School capability comes from a Network role.
            </p>
        </section>

        <section aria-labelledby="roles-heading" class="mt-8">
            <h2 id="roles-heading" class="text-base font-semibold">Network roles</h2>
            <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                <li
                    v-for="r in roles"
                    :key="r.key"
                    class="rounded border border-slate-200 p-3 text-sm"
                    :data-testid="`group-role-${r.key}`"
                >
                    <h3 class="font-medium">{{ r.name }}</h3>
                    <ul class="mt-1 list-disc pl-5 text-slate-700">
                        <li v-for="c in r.capabilities" :key="c">{{ c }}</li>
                    </ul>
                    <p class="mt-2 text-xs text-slate-600">
                        Scope:
                        {{ r.scopable ? 'whole Group, one Region or one Zone' : 'whole Group' }}
                        · School capabilities: none
                    </p>
                </li>
            </ul>
        </section>

        <section v-if="assignments !== null" aria-labelledby="assignments-heading" class="mt-8">
            <h2 id="assignments-heading" class="text-base font-semibold">Network assignments</h2>
            <div
                class="relative mt-3 overflow-x-auto"
                tabindex="0"
                role="region"
                aria-label="Network assignments table (scrolls sideways on small screens)"
            >
                <table class="w-full text-left text-sm" data-testid="network-assignments">
                    <caption class="sr-only">
                        Active Network roles in this Group
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="py-2 pr-3">Person</th>
                            <th scope="col" class="py-2 pr-3">Role</th>
                            <th scope="col" class="py-2 pr-3">Scope</th>
                            <th scope="col" class="py-2 pr-3">Status</th>
                            <th scope="col" class="py-2 pr-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="a in assignments" :key="a.id">
                            <td class="py-2 pr-3">
                                {{ a.userName }}
                                <span class="block text-xs text-slate-500">{{ a.userEmail }}</span>
                            </td>
                            <td class="py-2 pr-3">{{ a.roleName }}</td>
                            <td class="py-2 pr-3">{{ a.scope }}</td>
                            <td class="py-2 pr-3">
                                {{ a.effective ? 'Effective' : 'Not effective (account disabled)' }}
                            </td>
                            <td class="py-2 pr-3 text-right">
                                <button
                                    v-if="canManageOperators && a.role === 'network_viewer'"
                                    type="button"
                                    class="underline"
                                    :disabled="revokeForm.processing || !hasMfaFactor"
                                    @click="revoke(a)"
                                >
                                    Revoke<span class="sr-only"> {{ a.userName }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!assignments.length" class="mt-2 text-sm text-slate-600">
                No Network roles yet.
            </p>
            <div v-if="canManageOperators" class="mt-3 grid gap-3 sm:grid-cols-2">
                <p class="text-sm text-slate-600 sm:col-span-2">
                    Revoking a Network Viewer needs a reason and a fresh authentication code.
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
                        <option v-for="r in reasons" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                </div>
                <MfaCodeField
                    id="revoke-mfa"
                    v-model="revokeForm.mfa_code"
                    :has-mfa-factor="hasMfaFactor"
                    action="revoke a Network Viewer"
                    :error="revokeForm.errors.mfa_code"
                />
                <p
                    v-if="revokeError || revokeForm.errors.reason_code"
                    class="text-sm text-red-700 sm:col-span-2"
                    role="alert"
                >
                    {{ revokeError ?? revokeForm.errors.reason_code }}
                </p>
            </div>
        </section>

        <section
            v-if="canManageOperators && group.status === 'active'"
            aria-labelledby="grant-heading"
            class="mt-8"
        >
            <h2 id="grant-heading" class="text-base font-semibold">Add a Network Viewer</h2>
            <p class="mt-1 text-sm text-slate-600">
                Read-only access to the network directory — the whole Group, one Region or one Zone.
                It never opens a School.
            </p>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="grant">
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="operator-user"
                        >Person (exact email)</label
                    >
                    <input
                        id="operator-user"
                        v-model="grantForm.user"
                        type="email"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="grantForm.errors.user" class="mt-1 text-sm text-red-700">
                        {{ grantForm.errors.user }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="operator-region"
                        >Region (optional)</label
                    >
                    <select
                        id="operator-region"
                        v-model="grantForm.region_id"
                        :disabled="grantForm.zone_id !== ''"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="">Whole Group</option>
                        <option
                            v-for="r in regions.filter((x) => x.status === 'active')"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.name }}
                        </option>
                    </select>
                    <p v-if="grantForm.errors.region_id" class="mt-1 text-sm text-red-700">
                        {{ grantForm.errors.region_id }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="operator-zone"
                        >Zone (optional)</label
                    >
                    <select
                        id="operator-zone"
                        v-model="grantForm.zone_id"
                        :disabled="grantForm.region_id !== ''"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="">Whole Group</option>
                        <option
                            v-for="z in zones.filter((x) => x.status === 'active')"
                            :key="z.id"
                            :value="z.id"
                        >
                            {{ z.name }}
                        </option>
                    </select>
                    <p v-if="grantForm.errors.zone_id" class="mt-1 text-sm text-red-700">
                        {{ grantForm.errors.zone_id }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="operator-reason">Reason</label>
                    <select
                        id="operator-reason"
                        v-model="grantForm.reason_code"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="r in reasons" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                    <p v-if="grantForm.errors.reason_code" class="mt-1 text-sm text-red-700">
                        {{ grantForm.errors.reason_code }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <MfaCodeField
                        id="operator-mfa"
                        v-model="grantForm.mfa_code"
                        :has-mfa-factor="hasMfaFactor"
                        action="add a Network Viewer"
                        :error="grantForm.errors.mfa_code"
                    />
                </div>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="grantForm.processing || !hasMfaFactor"
                        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Add Network Viewer
                    </button>
                </div>
            </form>
        </section>

        <p class="mt-8 text-sm">
            <a class="underline" :href="base">Back to the network</a>
        </p>
    </main>
</template>
