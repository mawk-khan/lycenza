<script setup lang="ts">
// ORG.12E (ADR 0080 §10.7): one person's effective access in THIS School --
// derived from the canonical resolver, read-only. For each capability: where
// it comes from (role — whole School or Campus) and, when it is not
// effective, every layer that stops it. Never another School, never Group or
// platform authority.
import { computed, ref } from 'vue';
import type { AssignmentInfo } from '../../../accessCenter';
import { reasonLabels } from '../../../accessCenter';

interface CapabilityAccess {
    key: string;
    label: string;
    effective: 'whole_school' | 'campus' | 'not_effective';
    campuses: string[];
    sources: string[];
    reasons: string[];
}

const props = defineProps<{
    person: {
        membershipId: string;
        name: string | null;
        email: string | null;
        membershipStatus: string;
        accountDisabled: boolean;
    };
    assignments: AssignmentInfo[];
    modules: Array<{
        key: string;
        label: string;
        enabled: boolean;
        capabilities: CapabilityAccess[];
    }>;
    summary: { wholeSchool: number; campus: number; notEffective: number };
    sensitiveFamilies: string[];
}>();
const showNotEffective = ref(true);
const visibleModules = computed(() =>
    props.modules
        .map((m) => ({
            ...m,
            capabilities: m.capabilities.filter(
                (c) => showNotEffective.value || c.effective !== 'not_effective',
            ),
        }))
        .filter((m) => m.capabilities.length),
);
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/settings/access/users"
            >← Users &amp; effective access</a
        >
        <h1 class="mt-2 text-xl font-semibold">
            Effective access — {{ person.name ?? 'Former user' }}
        </h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ person.email }} ·
            {{
                person.accountDisabled
                    ? 'Account disabled'
                    : person.membershipStatus === 'active'
                      ? 'Active in this School'
                      : 'School access suspended'
            }}
            · This School only
        </p>

        <section aria-labelledby="assign-heading" class="mt-6">
            <h2 id="assign-heading" class="text-base font-semibold">Assignments</h2>
            <ul class="mt-2 space-y-1 text-sm" data-testid="effective-assignments">
                <li v-for="(a, i) in assignments" :key="i">
                    {{ a.roleName }} — {{ a.scope
                    }}<span v-if="!a.effective" class="text-amber-800"> (inactive Campus)</span>
                </li>
                <li v-if="!assignments.length" class="text-slate-600">No active role.</li>
            </ul>
            <p class="mt-2 text-sm" role="status">
                {{ summary.wholeSchool }} whole-School · {{ summary.campus }} Campus-only ·
                {{ summary.notEffective }} not currently effective
            </p>
            <p v-if="sensitiveFamilies.length" class="mt-1 text-sm">
                Sensitive data reachable: {{ sensitiveFamilies.length }} famil{{
                    sensitiveFamilies.length === 1 ? 'y' : 'ies'
                }}
            </p>
        </section>

        <label class="mt-6 flex items-center gap-2 text-sm">
            <input v-model="showNotEffective" type="checkbox" /> Show capabilities that are not
            currently effective
        </label>

        <section
            v-for="m in visibleModules"
            :key="m.key"
            class="mt-5"
            :aria-labelledby="`mod-${m.key}`"
        >
            <h2 :id="`mod-${m.key}`" class="text-base font-semibold">
                {{ m.label }}
                <span v-if="!m.enabled" class="text-xs font-normal text-amber-800"
                    >Module not enabled</span
                >
            </h2>
            <ul class="mt-2 divide-y divide-slate-100 rounded border border-slate-200 text-sm">
                <li v-for="c in m.capabilities" :key="c.key" class="px-3 py-2">
                    <span class="font-medium">{{ c.label }}</span>
                    <span
                        class="ml-2 text-xs"
                        :class="
                            c.effective === 'not_effective' ? 'text-amber-800' : 'text-emerald-800'
                        "
                    >
                        {{
                            c.effective === 'whole_school'
                                ? 'Effective — whole School'
                                : c.effective === 'campus'
                                  ? `Effective — ${c.campuses.join(', ')}`
                                  : 'Not currently effective'
                        }}
                    </span>
                    <span class="block text-xs text-slate-600"
                        >From: {{ c.sources.join('; ') }}</span
                    >
                    <span v-if="c.reasons.length" class="block text-xs text-slate-600">
                        Why not: {{ c.reasons.map((r) => reasonLabels[r] ?? r).join('; ') }}
                    </span>
                </li>
            </ul>
        </section>
    </main>
</template>
