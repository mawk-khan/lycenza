<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 5): one School Group, platform governance.
// Schools are added by exact UUID or verified domain, people by exact email
// or UUID -- no search. The grant list (Sensitive) is present only for
// holders of platform.school_group_grants.manage.
import { router, useForm } from '@inertiajs/vue3';

interface Grant {
    id: string;
    userName: string;
    userEmail: string;
    role: string;
    grantedAt: string;
}

const props = defineProps<{
    group: { id: string; name: string; slug: string; status: string };
    schools: { id: string; name: string; status: string }[];
    grants: Grant[] | null;
    canManage: boolean;
    canManageGrants: boolean;
}>();

const base = `/app/platform/groups/${props.group.id}`;
const renameForm = useForm({ name: props.group.name });
const schoolForm = useForm({ school: '' });
const grantForm = useForm({ user: '' });

function rename() {
    renameForm.put(base);
}
function addSchool() {
    schoolForm.post(`${base}/schools`, { onSuccess: () => schoolForm.reset() });
}
function removeSchool(id: string) {
    if (
        confirm(
            'Remove this School from the Group? Group-derived elevated access into it ends now.',
        )
    ) {
        router.delete(`${base}/schools/${id}`);
    }
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
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold" data-testid="group-name">{{ group.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ group.slug }} · {{ group.status }}</p>

        <form
            v-if="canManage && group.status === 'active'"
            class="mt-4 flex gap-2"
            @submit.prevent="rename"
        >
            <input
                v-model="renameForm.name"
                aria-label="Group name"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm">
                Rename
            </button>
        </form>
        <p v-if="renameForm.errors.name" class="mt-1 text-sm text-red-700">
            {{ renameForm.errors.name }}
        </p>

        <h2 class="mt-8 text-sm font-medium text-slate-500">Member Schools</h2>
        <ul class="mt-2 space-y-1 text-sm" data-testid="group-schools">
            <li v-for="s in schools" :key="s.id" class="flex justify-between gap-4">
                <span
                    >{{ s.name }} <span class="text-slate-500">({{ s.status }})</span></span
                >
                <button v-if="canManage" class="underline" @click="removeSchool(s.id)">
                    Remove
                </button>
            </li>
        </ul>
        <p v-if="!schools.length" class="mt-2 text-sm text-slate-600">No member Schools.</p>
        <form
            v-if="canManage && group.status === 'active'"
            class="mt-3 flex gap-2"
            @submit.prevent="addSchool"
        >
            <input
                v-model="schoolForm.school"
                aria-label="School identifier"
                placeholder="Exact School id or verified domain"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm">
                Add School
            </button>
        </form>
        <p v-if="schoolForm.errors.school" class="mt-1 text-sm text-red-700">
            {{ schoolForm.errors.school }}
        </p>

        <template v-if="grants !== null">
            <h2 class="mt-8 text-sm font-medium text-slate-500">Group grants</h2>
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
                <input
                    v-model="grantForm.user"
                    aria-label="Person to grant"
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

        <p v-if="canManage && group.status === 'active'" class="mt-8">
            <button class="text-sm text-red-800 underline" @click="archive">
                Archive this Group
            </button>
        </p>
        <p class="mt-6 text-sm"><a class="underline" href="/app/platform/groups">All Groups</a></p>
    </main>
</template>
