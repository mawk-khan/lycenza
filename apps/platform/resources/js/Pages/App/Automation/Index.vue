<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0L.6 — Automation (ADR 0043). One code-registered rule type per
 * catalog entry; a School can only enable, disable or take ownership of
 * it. Informational only: a review item is a pointer to look at something,
 * it changes nothing and sends nothing. Authorization (`automation.view`
 * to see, `automation.manage` to change) and the School opt-in are
 * enforced on the server.
 */
interface RuleInstance {
    id: string;
    status: 'enabled' | 'disabled' | 'suspended';
    ownerUserId: string | null;
    ownerName: string | null;
    enabledAt: string | null;
    suspendedAt: string | null;
    suspensionReason: string | null;
}

interface Rule {
    key: string;
    label: string;
    description: string;
    triggerEventType: string;
    tier: number;
    requiredCapabilities: string[];
    instance: RuleInstance | null;
}

interface Execution {
    id: string;
    ruleType: string | null;
    triggerEventType: string;
    subjectType: string;
    subjectId: string;
    status: string;
    outcomeCode: string | null;
    attempts: number;
    createdAt: string;
    completedAt: string | null;
}

interface ReviewItem {
    id: string;
    ruleType: string | null;
    itemType: string;
    subjectType: string;
    subjectId: string;
    executionId: string;
    createdAt: string;
}

const props = defineProps<{
    automation: {
        schoolEnabled: boolean;
        canManage: boolean;
        viewerUserId: string;
        rules: Rule[];
        executions: Execution[];
        reviewItems: ReviewItem[];
    };
}>();

const suspensionText: Record<string, string> = {
    owner_missing: 'its owner no longer exists',
    owner_disabled: "its owner's account is disabled",
    owner_no_school_authority: 'its owner is no longer an active member of this School',
    owner_capability_missing: 'its owner no longer holds a capability it needs',
    execution_cap_exceeded: 'it ran more often than its daily limit',
};

function formatTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function act(rule: Rule, action: 'enable' | 'disable' | 'take-ownership'): void {
    router.post(`/app/automation/rules/${encodeURIComponent(rule.key)}/${action}`);
}

function ruleLabel(key: string | null): string {
    return props.automation.rules.find((r) => r.key === key)?.label ?? key ?? '—';
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Automation</h1>
        <p class="mt-1 text-sm text-slate-500">
            Rules that react to events in this School. Each rule is defined by School OS, not
            written here, and runs as its accountable owner — never with more access than that
            person has at the time. Rules here only add review items; they change nothing and send
            nothing.
        </p>

        <p
            class="mt-3 rounded border px-3 py-2 text-sm"
            :class="
                automation.schoolEnabled
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                    : 'border-amber-300 bg-amber-50 text-amber-900'
            "
            data-testid="school-automation-state"
        >
            <template v-if="automation.schoolEnabled">
                Automation is switched on for this School.
            </template>
            <template v-else>
                Automation is switched off for this School, so no rule runs, whatever its own
                setting. It is switched on by the platform operator.
            </template>
        </p>

        <section class="mt-6">
            <h2 class="text-base font-semibold">Rules</h2>
            <ul class="mt-2 space-y-3">
                <li
                    v-for="rule in automation.rules"
                    :key="rule.key"
                    class="rounded border border-slate-200 p-4"
                    data-testid="automation-rule"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-medium">{{ rule.label }}</h3>
                        <span class="text-sm" data-testid="rule-status">
                            {{ rule.instance ? rule.instance.status : 'not configured' }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-600">{{ rule.description }}</p>
                    <p class="mt-1 font-mono text-xs text-slate-500">
                        Trigger: {{ rule.triggerEventType }} · Needs:
                        {{ rule.requiredCapabilities.join(', ') }}
                    </p>
                    <p v-if="rule.instance" class="mt-2 text-sm">
                        Accountable owner: {{ rule.instance.ownerName ?? '—' }}
                        <span v-if="rule.instance.status === 'enabled'" class="text-slate-500">
                            · enabled {{ formatTime(rule.instance.enabledAt) }}
                        </span>
                    </p>
                    <p
                        v-if="rule.instance?.status === 'suspended'"
                        class="mt-2 text-sm text-red-700"
                        data-testid="rule-suspended"
                    >
                        Suspended {{ formatTime(rule.instance.suspendedAt) }} because
                        {{
                            suspensionText[rule.instance.suspensionReason ?? ''] ??
                            rule.instance.suspensionReason
                        }}. Re-enable it to become its owner.
                    </p>

                    <div v-if="automation.canManage" class="mt-3 flex gap-2 text-sm">
                        <button
                            v-if="!rule.instance || rule.instance.status !== 'enabled'"
                            type="button"
                            class="rounded bg-slate-900 px-3 py-1 text-white"
                            data-testid="rule-enable"
                            @click="act(rule, 'enable')"
                        >
                            {{ rule.instance ? 'Re-enable' : 'Enable' }} (you become owner)
                        </button>
                        <button
                            v-if="rule.instance?.status === 'enabled'"
                            type="button"
                            class="rounded border border-slate-300 px-3 py-1"
                            data-testid="rule-disable"
                            @click="act(rule, 'disable')"
                        >
                            Disable
                        </button>
                        <button
                            v-if="
                                rule.instance?.status === 'enabled' &&
                                rule.instance.ownerUserId !== automation.viewerUserId
                            "
                            type="button"
                            class="rounded border border-slate-300 px-3 py-1"
                            data-testid="rule-take-ownership"
                            @click="act(rule, 'take-ownership')"
                        >
                            Take ownership
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <section class="mt-8">
            <h2 class="text-base font-semibold">Review items</h2>
            <p class="mt-1 text-sm text-slate-500">
                Informational. Reviewing an item is up to you; nothing was set up automatically.
            </p>
            <EmptyState
                v-if="automation.reviewItems.length === 0"
                class="mt-3"
                title="No review items"
                description="Items appear here when an enabled rule's event happens."
            />
            <table v-else class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 pr-4 font-medium">Created</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Rule</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Review</th>
                        <th scope="col" class="py-2 font-medium">Record</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr
                        v-for="item in automation.reviewItems"
                        :key="item.id"
                        data-testid="review-item"
                    >
                        <td class="py-2 pr-4 whitespace-nowrap">
                            {{ formatTime(item.createdAt) }}
                        </td>
                        <td class="py-2 pr-4">{{ ruleLabel(item.ruleType) }}</td>
                        <td class="py-2 pr-4">
                            <template v-if="item.itemType === 'academic_year_setup_review'">
                                An academic year became active: review its set-up (terms, sections,
                                timetable, subject offerings) in
                                <a class="underline" href="/app/school-setup">School setup</a>.
                            </template>
                            <template v-else>{{ item.itemType }}</template>
                        </td>
                        <td class="py-2 font-mono text-xs">
                            {{ item.subjectType }}<br />{{ item.subjectId }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="mt-8">
            <h2 class="text-base font-semibold">Recent executions</h2>
            <EmptyState
                v-if="automation.executions.length === 0"
                class="mt-3"
                title="No executions yet"
                description="An execution is recorded each time an enabled rule's event happens."
            />
            <table v-else class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 pr-4 font-medium">Created</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Rule</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Status</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Outcome</th>
                        <th scope="col" class="py-2 font-medium">Attempts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr
                        v-for="execution in automation.executions"
                        :key="execution.id"
                        data-testid="execution"
                    >
                        <td class="py-2 pr-4 whitespace-nowrap">
                            {{ formatTime(execution.createdAt) }}
                        </td>
                        <td class="py-2 pr-4">{{ ruleLabel(execution.ruleType) }}</td>
                        <td class="py-2 pr-4">{{ execution.status }}</td>
                        <td class="py-2 pr-4 font-mono text-xs">
                            {{ execution.outcomeCode ?? '—' }}
                        </td>
                        <td class="py-2">{{ execution.attempts }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </main>
</template>
