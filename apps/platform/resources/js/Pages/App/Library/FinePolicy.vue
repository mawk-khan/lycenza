<script setup lang="ts">
import { formatDate } from '../../../datetime';
// SWC.3 (ADR 0073 sections 19.4): the Library fine policy (OPF.4) -- the
// current version, every published version, and publishing a new one.
// Versions are immutable; publishing adds the next one. The form carries
// the version it was based on, so a repeated or stale publish is refused by
// the server (X5). Amounts are the stored values; the browser computes none.
// Production use of Library fines stays gated by E34 (ADR 0058).
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import SelectField from '../../../Components/Form/SelectField.vue';
import TextField from '../../../Components/Form/TextField.vue';
import { feeHeadText, type FeeHeadLabel } from '../../../support/feeIntent';

interface Policy {
    id: string;
    version: number;
    status: string;
    feeHead: FeeHeadLabel | null;
    dailyRate: string | null;
    graceDays: number | null;
    maxAmount: string | null;
    currency: string;
    publishedAt: string | null;
}

const props = defineProps<{
    current: Policy | null;
    versions: Policy[];
    options: FeeHeadLabel[];
    canManage: boolean;
}>();

const feeHeadOptions = computed(() =>
    props.options.map((h) => ({ value: h.id, label: `${h.name} (${h.code})` })),
);
const statusOptions = [
    { value: 'active', label: 'Active — assess overdue fines at check-in' },
    { value: 'disabled', label: 'Disabled — assess no fines' },
];

const form = useForm<{
    expected_version: number;
    status: string;
    fee_head_id: string | null;
    daily_rate: string;
    grace_days: number | null;
    max_amount: string;
}>({
    expected_version: props.current?.version ?? 0,
    status: props.current?.status ?? 'active',
    fee_head_id: props.current?.feeHead?.active ? props.current.feeHead.id : null,
    daily_rate: props.current?.dailyRate ?? '',
    grace_days: props.current?.graceDays ?? 0,
    max_amount: props.current?.maxAmount ?? '',
});
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);

function publish() {
    form.post('/app/library/fine-policy', { preserveScroll: true });
}

// After a publish, the form is based on the new current version.
watch(
    () => props.current?.version,
    (version) => {
        form.expected_version = version ?? 0;
    },
);

const describe = (p: Policy) =>
    p.status === 'disabled'
        ? 'Disabled'
        : `${p.currency} ${p.dailyRate} per day after ${p.graceDays} grace day(s)${p.maxAmount ? `, capped at ${p.currency} ${p.maxAmount}` : ', no cap'}`;
</script>

<template>
    <Head title="Library fine policy" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/library/circulation">← Circulation</a>
        <h1 class="mt-2 text-xl font-semibold">Library fine policy</h1>
        <p class="mt-1 text-sm text-slate-600">
            An overdue loan is fined at check-in under the policy current at that moment. A fine is
            waived through Finance → Concessions, or voided on its loan.
        </p>

        <section class="mt-6" aria-labelledby="current-policy-heading">
            <h2 id="current-policy-heading" class="font-semibold">Current policy</h2>
            <p v-if="!props.current" class="mt-2 text-sm text-slate-600">
                No policy has been published: no fines are assessed.
            </p>
            <dl
                v-else
                class="mt-2 grid grid-cols-[10rem_1fr] gap-y-1 text-sm"
                data-testid="current-policy"
            >
                <dt class="text-slate-600">Version</dt>
                <dd>{{ props.current.version }}</dd>
                <dt class="text-slate-600">Rule</dt>
                <dd>{{ describe(props.current) }}</dd>
                <dt class="text-slate-600">Fee head</dt>
                <dd>{{ props.current.feeHead ? feeHeadText(props.current.feeHead) : '—' }}</dd>
                <dt class="text-slate-600">Published</dt>
                <dd>{{ formatDate(props.current.publishedAt) }}</dd>
            </dl>
        </section>

        <section
            v-if="props.canManage"
            class="mt-8 rounded border border-slate-300 p-4"
            aria-labelledby="publish-policy-heading"
        >
            <h2 id="publish-policy-heading" class="font-semibold">Publish a new version</h2>
            <ErrorSummary :errors="form.errors" :fields="['policy', 'expected_version']" />
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="dialog?.open()">
                <SelectField
                    id="policy-status"
                    v-model="form.status"
                    label="Status"
                    :options="statusOptions"
                    required
                    :error="form.errors.status"
                />
                <template v-if="form.status === 'active'">
                    <SelectField
                        id="policy-fee-head"
                        v-model="form.fee_head_id"
                        label="Fee head"
                        :options="feeHeadOptions"
                        placeholder="Select a fee head"
                        required
                        :error="form.errors.fee_head_id"
                    />
                    <TextField
                        id="policy-daily-rate"
                        v-model="form.daily_rate"
                        label="Daily rate (INR)"
                        required
                        hint="Greater than zero, at most two decimal places, e.g. 2.50."
                        :error="form.errors.daily_rate"
                    />
                    <TextField
                        id="policy-grace"
                        v-model="form.grace_days"
                        label="Grace days"
                        type="number"
                        :min="0"
                        required
                        :error="form.errors.grace_days"
                    />
                    <TextField
                        id="policy-cap"
                        v-model="form.max_amount"
                        label="Cap per fine (INR)"
                        hint="Optional. Empty means no cap."
                        :error="form.errors.max_amount"
                    />
                </template>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    >
                        Publish version {{ (props.current?.version ?? 0) + 1 }}
                    </button>
                </div>
            </form>
            <ConfirmDialog
                ref="dialog"
                :title="`Publish version ${(props.current?.version ?? 0) + 1}?`"
                confirm-label="Publish"
                tone="neutral"
                @confirm="publish"
            >
                It applies to loans checked in from now on. Fines already assessed keep the version
                they were assessed under.
            </ConfirmDialog>
        </section>

        <section class="mt-8" aria-labelledby="policy-history-heading">
            <h2 id="policy-history-heading" class="font-semibold">History</h2>
            <p v-if="props.versions.length === 0" class="mt-2 text-sm text-slate-600">
                No version yet.
            </p>
            <div v-else class="relative overflow-x-auto mt-2">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Every published fine-policy version, newest first
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="pr-3 py-2">Version</th>
                            <th scope="col" class="pr-3 py-2">Rule</th>
                            <th scope="col" class="pr-3 py-2">Fee head</th>
                            <th scope="col" class="pr-3 py-2">Published</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="policy in props.versions" :key="policy.id">
                            <td class="pr-3 py-2">{{ policy.version }}</td>
                            <td class="pr-3 py-2">{{ describe(policy) }}</td>
                            <td class="pr-3 py-2">
                                {{ policy.feeHead ? feeHeadText(policy.feeHead) : '—' }}
                            </td>
                            <td class="pr-3 py-2">{{ formatDate(policy.publishedAt) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
