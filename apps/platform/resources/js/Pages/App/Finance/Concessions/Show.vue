<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import { formatMoney } from '../../../../money';

interface Concession {
    id: string;
    studentName: string | null;
    studentNumber: string | null;
    academicYearName: string | null;
    category: string;
    scope: string;
    chargeId: string | null;
    chargeDescription: string | null;
    feeHeadLabel: string | null;
    validFrom: string | null;
    validTo: string | null;
    kind: string;
    fixedAmount: string | null;
    percentage: string | null;
    currency: string;
    status: string;
    requestedByName: string | null;
    decidedByName: string | null;
    decidedAt: string | null;
    withdrawnAt: string | null;
    revokedAt: string | null;
    createdAt: string;
}

interface Adjustment {
    id: string;
    chargeId: string;
    chargeDescription: string | null;
    amount: string;
    currency: string;
    postedAt: string;
    cancelledAt: string | null;
    journalEntryId: string;
}

interface Props {
    concession: Concession;
    adjustments: Adjustment[];
    can: {
        decide: boolean;
        withdraw: boolean;
        revoke: boolean;
        cancelAdjustments: boolean;
        isRequester: boolean;
    };
    hasMfaFactor: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);
const base = `/app/finance/concessions/${props.concession.id}`;
// SR.4 (ADR 0071 §26.7): approve, revoke and adjustment cancellation need a fresh code.
const mfaCode = ref('');
const mfaError = computed(() => (page.props.errors as Record<string, string>).mfa_code ?? null);
const needsCode = computed(
    () => props.can.decide || props.can.revoke || props.can.cancelAdjustments,
);
const stepUpActions = ['approve', 'revoke'];

function act(action: string, question: string): void {
    if (window.confirm(question)) {
        const data = stepUpActions.includes(action) ? { mfa_code: mfaCode.value } : {};
        router.post(`${base}/${action}`, data, {
            preserveScroll: true,
            onFinish: () => (mfaCode.value = ''),
        });
    }
}

function cancelAdjustment(a: Adjustment): void {
    if (
        window.confirm(
            'Cancel this adjustment? A reversing journal entry is posted; the amount is owed again.',
        )
    ) {
        router.post(
            `/app/finance/fee-adjustments/${a.id}/cancel`,
            { mfa_code: mfaCode.value },
            { preserveScroll: true, onFinish: () => (mfaCode.value = '') },
        );
    }
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/concessions">← Concessions</a>
        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold capitalize">{{ concession.category }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ concession.studentName ?? 'Student' }}
                    <span v-if="concession.studentNumber">({{ concession.studentNumber }})</span>
                    · {{ concession.academicYearName }}
                </p>
            </div>
            <span
                class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium capitalize"
                >{{ concession.status }}</span
            >
        </div>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-slate-500">Value</dt>
                <dd class="font-mono">
                    {{
                        concession.kind === 'fixed'
                            ? formatMoney(concession.fixedAmount ?? '0.00', concession.currency)
                            : `${concession.percentage}% of each charge`
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Applies to</dt>
                <dd v-if="concession.scope === 'targeted'">
                    <a class="underline" :href="`/app/finance/charges/${concession.chargeId}`">{{
                        concession.chargeDescription ?? 'Charge'
                    }}</a>
                </dd>
                <dd v-else>
                    {{ concession.feeHeadLabel ?? 'Every fee head' }}, {{ concession.validFrom }} to
                    {{ concession.validTo }}
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Requested by</dt>
                <dd>{{ concession.requestedByName ?? '—' }} · {{ concession.createdAt }}</dd>
            </div>
            <div v-if="concession.decidedAt">
                <dt class="text-slate-500">Decided by</dt>
                <dd>{{ concession.decidedByName ?? '—' }} · {{ concession.decidedAt }}</dd>
            </div>
            <div v-if="concession.revokedAt">
                <dt class="text-slate-500">Revoked</dt>
                <dd>{{ concession.revokedAt }}</dd>
            </div>
        </dl>

        <section v-if="needsCode" class="mt-8 border-t border-slate-200 pt-6">
            <MfaCodeField
                id="concession-mfa"
                v-model="mfaCode"
                :has-mfa-factor="hasMfaFactor"
                action="Approving, revoking or cancelling a concession adjustment"
                :error="mfaError"
            />
        </section>

        <section
            v-if="concession.status === 'pending'"
            class="mt-8 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-6"
        >
            <template v-if="can.decide">
                <button
                    type="button"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white"
                    @click="
                        act(
                            'approve',
                            'Approve this concession? A one-charge concession is posted now.',
                        )
                    "
                >
                    Approve
                </button>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-1.5 text-sm"
                    @click="act('reject', 'Reject this concession?')"
                >
                    Reject
                </button>
            </template>
            <p v-else-if="can.isRequester" class="text-sm text-slate-500">
                You requested this concession, so another person must approve or reject it.
            </p>
            <button
                v-if="can.withdraw"
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm"
                @click="act('withdraw', 'Withdraw your request?')"
            >
                Withdraw
            </button>
        </section>

        <section v-if="can.revoke" class="mt-8 border-t border-slate-200 pt-6">
            <p class="text-sm text-slate-500">
                Revoking stops this concession applying to future charges. Adjustments already
                posted stay until each is cancelled.
            </p>
            <button
                type="button"
                class="mt-2 rounded border border-red-300 px-3 py-1.5 text-sm text-red-700"
                @click="act('revoke', 'Revoke this standing concession?')"
            >
                Revoke
            </button>
        </section>

        <section class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium">Posted adjustments</h2>
            <p v-if="adjustments.length === 0" class="mt-1 text-sm text-slate-500">None yet.</p>
            <ul v-else class="mt-2 divide-y divide-slate-100 text-sm">
                <li
                    v-for="a in adjustments"
                    :key="a.id"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <a class="underline" :href="`/app/finance/charges/${a.chargeId}`">{{
                        a.chargeDescription ?? 'Charge'
                    }}</a>
                    <span
                        class="ml-auto font-mono"
                        :class="{ 'text-slate-400 line-through': a.cancelledAt }"
                        >{{ formatMoney(a.amount, a.currency) }}</span
                    >
                    <button
                        v-if="can.cancelAdjustments && !a.cancelledAt"
                        type="button"
                        class="text-xs text-red-700 underline"
                        @click="cancelAdjustment(a)"
                    >
                        Cancel
                    </button>
                    <span v-else-if="a.cancelledAt" class="text-xs text-slate-500">Cancelled</span>
                </li>
            </ul>
        </section>
    </main>
</template>
