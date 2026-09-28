<script setup lang="ts">
// Phase 0O.12B (ADR 0059 section 23, owner amendment): the selected School's
// staff accounts -- who has staff access, with which School roles, and the
// invitations still pending. Every change needs a current authentication
// code. Off-boarding SUSPENDS this School's access (the person's account,
// their other Schools and any HR record are untouched); reactivation grants
// newly chosen roles -- earlier roles never come back by themselves.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { postJson } from '../../../csrf';

interface RoleOption {
    key: string;
    name: string;
    grantable: boolean;
}

interface StaffMember {
    membershipId: string;
    userId: string;
    name: string | null;
    email: string | null;
    status: string;
    roles: { key: string; name: string }[];
    joinedAt: string | null;
}

interface Invitation {
    id: string;
    email: string;
    status: string;
    roles: string[];
    expiresAt: string;
    createdAt: string;
}

const props = defineProps<{
    staff: StaffMember[];
    invitations: Invitation[];
    roleCatalog: RoleOption[];
    canInvite: boolean;
    canManageMembers: boolean;
    canManageRoles: boolean;
    hasMfaFactor: boolean;
    emailAvailable: boolean;
    currentUserId: string;
}>();

const code = ref('');
const error = ref('');
const notice = ref('');
const busy = ref(false);
const inviteEmail = ref('');
const inviteRoles = ref<string[]>([]);
const grantChoice = reactive<Record<string, string>>({});
const reactivateRoles = reactive<Record<string, string[]>>({});

const grantable = computed(() => props.roleCatalog.filter((r) => r.grantable));
const active = computed(() => props.staff.filter((s) => s.status === 'active'));
const suspended = computed(() => props.staff.filter((s) => s.status === 'suspended'));
const pending = computed(() =>
    props.invitations.filter((i) => i.status === 'pending' || i.status === 'expired'),
);
const canMutate = computed(
    () => (props.canInvite || props.canManageMembers || props.canManageRoles) && props.hasMfaFactor,
);

const statusLabel: Record<string, string> = {
    pending: 'Pending',
    expired: 'Expired',
    accepted: 'Accepted',
    revoked: 'Revoked',
};

async function run(action: () => Promise<unknown>, done: string) {
    error.value = '';
    notice.value = '';
    busy.value = true;
    try {
        await action();
        notice.value = done;
        code.value = '';
        router.reload({ only: ['staff', 'invitations'] });
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}

function invite() {
    return run(async () => {
        await postJson('/app/settings/staff/invitations', {
            email: inviteEmail.value,
            roles: inviteRoles.value,
            mfa_code: code.value,
        });
        inviteEmail.value = '';
        inviteRoles.value = [];
    }, 'Invitation sent. It expires in 7 days.');
}

function resend(invitation: Invitation) {
    return run(
        () =>
            postJson(`/app/settings/staff/invitations/${invitation.id}/resend`, {
                mfa_code: code.value,
            }),
        `A new invitation was sent to ${invitation.email}; the previous link no longer works.`,
    );
}

function revokeInvitation(invitation: Invitation) {
    if (!confirm(`Revoke the invitation for ${invitation.email}? Its link stops working.`)) {
        return;
    }
    return run(
        () =>
            postJson(`/app/settings/staff/invitations/${invitation.id}/revoke`, {
                mfa_code: code.value,
            }),
        'Invitation revoked.',
    );
}

function suspend(member: StaffMember) {
    if (
        !confirm(
            `Remove ${member.name ?? 'this person'}'s staff access to this School?\n\n` +
                'Their access ends immediately and every School role they hold here is revoked. ' +
                'Their account, their access to other Schools and any HR record are not changed. ' +
                'You can reactivate them later with newly chosen roles.',
        )
    ) {
        return;
    }
    return run(
        () =>
            postJson(`/app/settings/staff/members/${member.membershipId}/suspend`, {
                mfa_code: code.value,
            }),
        `${member.name ?? 'The staff member'}'s access to this School was suspended.`,
    );
}

function reactivate(member: StaffMember) {
    return run(
        () =>
            postJson(`/app/settings/staff/members/${member.membershipId}/reactivate`, {
                roles: reactivateRoles[member.membershipId] ?? [],
                mfa_code: code.value,
            }),
        `${member.name ?? 'The staff member'}'s access was reactivated with the chosen roles.`,
    );
}

function grantRole(member: StaffMember) {
    return run(
        () =>
            postJson(`/app/settings/staff/members/${member.membershipId}/roles`, {
                role: grantChoice[member.membershipId] ?? '',
                mfa_code: code.value,
            }),
        'Role granted.',
    );
}

function revokeRole(member: StaffMember, role: { key: string; name: string }) {
    if (!confirm(`Revoke the ${role.name} role from ${member.name ?? 'this person'}?`)) {
        return;
    }
    return run(
        () =>
            postJson(
                `/app/settings/staff/members/${member.membershipId}/roles/${role.key}/revoke`,
                {
                    mfa_code: code.value,
                },
            ),
        `${role.name} was revoked.`,
    );
}

function day(value: string | null): string {
    return value ? value.slice(0, 16).replace('T', ' ') : '—';
}

function isSelf(member: StaffMember): boolean {
    return member.userId === props.currentUserId;
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Staff accounts</h1>
        <p class="mt-1 text-sm text-slate-600">
            Who has staff access to this School and with which roles. Staff join by invitation.
            Removing someone suspends their access to this School only; it never deletes their
            account or changes HR records.
        </p>

        <section
            v-if="canInvite || canManageMembers || canManageRoles"
            class="mt-4 rounded border border-slate-200 p-4"
        >
            <p v-if="!hasMfaFactor" class="text-sm" data-testid="staff-mfa-required">
                Changing staff access needs multi-factor authentication.
                <a class="underline" href="/app/account/security"
                    >Set it up under Account security</a
                >.
            </p>
            <template v-else>
                <label class="block text-sm text-slate-600" for="mfa_code"
                    >Authentication code (needed for every change)</label
                >
                <input
                    id="mfa_code"
                    v-model="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2"
                />
            </template>
            <p
                v-if="error"
                role="alert"
                class="mt-2 text-sm text-red-700"
                data-testid="staff-error"
            >
                {{ error }}
            </p>
            <p v-if="notice" class="mt-2 text-sm text-green-800" data-testid="staff-notice">
                {{ notice }}
            </p>
        </section>

        <section v-if="canInvite" class="mt-6">
            <h2 class="font-medium">Invite a staff member</h2>
            <p v-if="!emailAvailable" class="mt-1 text-sm" data-testid="staff-email-unavailable">
                Invitations cannot be sent yet: this deployment has no working email delivery.
            </p>
            <form v-else class="mt-2 space-y-3" @submit.prevent="invite">
                <input
                    v-model="inviteEmail"
                    type="email"
                    placeholder="name@example.org"
                    class="w-full max-w-md rounded border border-slate-300 px-3 py-2"
                    aria-label="Email address"
                />
                <fieldset>
                    <legend class="text-sm text-slate-600">Roles</legend>
                    <label v-for="role in grantable" :key="role.key" class="mr-4 text-sm">
                        <input v-model="inviteRoles" type="checkbox" :value="role.key" />
                        {{ role.name }}
                    </label>
                </fieldset>
                <button
                    type="submit"
                    :disabled="busy || !canMutate"
                    class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                >
                    Send invitation
                </button>
            </form>
        </section>

        <section class="mt-8">
            <h2 class="font-medium">Pending invitations</h2>
            <p v-if="pending.length === 0" class="mt-1 text-sm text-slate-600">None.</p>
            <table v-else class="mt-2 w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-600">
                        <th class="py-1">Email</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="invitation in pending" :key="invitation.id" class="border-t">
                        <td class="py-1">{{ invitation.email }}</td>
                        <td>{{ invitation.roles.join(', ') }}</td>
                        <td>{{ statusLabel[invitation.status] ?? invitation.status }}</td>
                        <td>{{ day(invitation.expiresAt) }}</td>
                        <td class="space-x-2 text-right">
                            <button
                                v-if="canManageMembers"
                                class="underline disabled:opacity-50"
                                :disabled="busy || !canMutate || !emailAvailable"
                                @click="resend(invitation)"
                            >
                                Resend
                            </button>
                            <button
                                v-if="canManageMembers && invitation.status === 'pending'"
                                class="underline disabled:opacity-50"
                                :disabled="busy || !canMutate"
                                @click="revokeInvitation(invitation)"
                            >
                                Revoke
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="mt-8">
            <h2 class="font-medium">Active staff</h2>
            <p v-if="active.length === 0" class="mt-1 text-sm text-slate-600">None.</p>
            <ul v-else class="mt-2 divide-y">
                <li
                    v-for="member in active"
                    :key="member.membershipId"
                    class="py-2"
                    data-testid="staff-active"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span
                            ><strong>{{ member.name }}</strong>
                            <span class="ml-2 text-sm text-slate-600">{{ member.email }}</span>
                            <span v-if="isSelf(member)" class="text-sm text-slate-600">
                                (you)</span
                            ></span
                        >
                        <button
                            v-if="canInvite && !isSelf(member)"
                            class="text-sm underline disabled:opacity-50"
                            :disabled="busy || !canMutate"
                            @click="suspend(member)"
                        >
                            Remove access (suspend)
                        </button>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                        <span v-if="member.roles.length === 0" class="text-slate-600">No role</span>
                        <span
                            v-for="role in member.roles"
                            :key="role.key"
                            class="rounded bg-slate-100 px-2 py-0.5"
                        >
                            {{ role.name }}
                            <button
                                v-if="canManageRoles && !isSelf(member)"
                                class="ml-1 underline disabled:opacity-50"
                                :disabled="busy || !canMutate"
                                :aria-label="`Revoke ${role.name}`"
                                @click="revokeRole(member, role)"
                            >
                                ×
                            </button>
                        </span>
                        <template v-if="canManageRoles && !isSelf(member)">
                            <select
                                :value="grantChoice[member.membershipId] ?? ''"
                                class="rounded border border-slate-300 px-2 py-1"
                                aria-label="Role to grant"
                                @change="
                                    grantChoice[member.membershipId] = (
                                        $event.target as HTMLSelectElement
                                    ).value
                                "
                            >
                                <option value="">Add a role…</option>
                                <option
                                    v-for="role in grantable.filter(
                                        (r) => !member.roles.some((m) => m.key === r.key),
                                    )"
                                    :key="role.key"
                                    :value="role.key"
                                >
                                    {{ role.name }}
                                </option>
                            </select>
                            <button
                                class="underline disabled:opacity-50"
                                :disabled="busy || !canMutate || !grantChoice[member.membershipId]"
                                @click="grantRole(member)"
                            >
                                Grant
                            </button>
                        </template>
                    </div>
                </li>
            </ul>
        </section>

        <section class="mt-8">
            <h2 class="font-medium">Suspended staff</h2>
            <p v-if="suspended.length === 0" class="mt-1 text-sm text-slate-600">None.</p>
            <ul v-else class="mt-2 divide-y">
                <li
                    v-for="member in suspended"
                    :key="member.membershipId"
                    class="py-2"
                    data-testid="staff-suspended"
                >
                    <strong>{{ member.name }}</strong>
                    <span class="ml-2 text-sm text-slate-600">{{ member.email }}</span>
                    <div v-if="canInvite && !isSelf(member)" class="mt-1 text-sm">
                        <span class="text-slate-600">Reactivate with roles: </span>
                        <label v-for="role in grantable" :key="role.key" class="mr-3">
                            <input
                                type="checkbox"
                                :value="role.key"
                                :checked="
                                    (reactivateRoles[member.membershipId] ?? []).includes(role.key)
                                "
                                @change="
                                    reactivateRoles[member.membershipId] = (
                                        $event.target as HTMLInputElement
                                    ).checked
                                        ? [
                                              ...(reactivateRoles[member.membershipId] ?? []),
                                              role.key,
                                          ]
                                        : (reactivateRoles[member.membershipId] ?? []).filter(
                                              (k) => k !== role.key,
                                          )
                                "
                            />
                            {{ role.name }}
                        </label>
                        <button
                            class="underline disabled:opacity-50"
                            :disabled="
                                busy ||
                                !canMutate ||
                                (reactivateRoles[member.membershipId] ?? []).length === 0
                            "
                            @click="reactivate(member)"
                        >
                            Reactivate
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </main>
</template>
