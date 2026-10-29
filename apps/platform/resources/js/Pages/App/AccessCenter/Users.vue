<script setup lang="ts">
// ORG.12E (ADR 0080 §10.7): this School's staff and their role assignments
// (whole School or one Campus) -- read-only, paginated, searched by name or
// address within this School only. Open a person to see their effective
// access and why.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../../Components/Pagination.vue';
import type { AssignmentInfo } from '../../../accessCenter';

interface Member {
    membershipId: string;
    name: string | null;
    email: string | null;
    membershipStatus: string;
    accountDisabled: boolean;
    assignments: AssignmentInfo[];
}

const props = defineProps<{
    filters: { q: string };
    members: {
        data: Member[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
}>();
const q = ref(props.filters.q);
function search() {
    router.get('/app/settings/access/users', q.value ? { q: q.value } : {}, {
        preserveState: true,
    });
}
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/settings/access">← Access Center</a>
        <h1 class="mt-2 text-xl font-semibold">Users &amp; effective access</h1>
        <p class="mt-1 text-sm text-slate-600">
            Staff of this School and their role assignments. Roles are granted on
            <a class="underline" href="/app/settings/staff">Staff accounts</a>.
        </p>
        <form class="mt-4 flex gap-2" role="search" @submit.prevent="search">
            <label class="sr-only" for="access-q">Search by name or email</label>
            <input
                id="access-q"
                v-model="q"
                type="search"
                maxlength="100"
                placeholder="Name or email"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm">
                Search
            </button>
        </form>
        <div
            class="relative mt-4 overflow-x-auto"
            tabindex="0"
            role="region"
            aria-label="Staff assignments table (scrolls sideways on small screens)"
        >
            <table class="w-full text-left text-sm" data-testid="access-users">
                <caption class="sr-only">
                    Staff role assignments in this School
                </caption>
                <thead class="border-b border-slate-300 text-slate-600">
                    <tr>
                        <th scope="col" class="py-2 pr-3">Person</th>
                        <th scope="col" class="py-2 pr-3">Assignments</th>
                        <th scope="col" class="py-2 pr-3">Status</th>
                        <th scope="col" class="py-2 pr-3"><span class="sr-only">Details</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="m in members.data" :key="m.membershipId">
                        <td class="py-2 pr-3">
                            {{ m.name ?? 'Former user' }}
                            <span v-if="m.email" class="block text-xs text-slate-500">{{
                                m.email
                            }}</span>
                        </td>
                        <td class="py-2 pr-3">
                            <ul>
                                <li v-for="(a, i) in m.assignments" :key="i">
                                    {{ a.roleName }} — {{ a.scope
                                    }}<span v-if="!a.effective" class="text-amber-800">
                                        (inactive Campus)</span
                                    >
                                </li>
                                <li v-if="!m.assignments.length" class="text-slate-500">
                                    No active role
                                </li>
                            </ul>
                        </td>
                        <td class="py-2 pr-3">
                            {{
                                m.accountDisabled
                                    ? 'Account disabled'
                                    : m.membershipStatus === 'active'
                                      ? 'Active'
                                      : 'School access suspended'
                            }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            <a
                                class="underline"
                                :href="`/app/settings/access/users/${m.membershipId}`"
                                >Effective access<span class="sr-only"> of {{ m.name }}</span></a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="!members.data.length" class="mt-2 text-sm text-slate-600">Nobody matches.</p>
        <Pagination
            :links="members.links"
            :from="members.from"
            :to="members.to"
            :total="members.total"
        />
    </main>
</template>
