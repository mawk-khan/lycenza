<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { idempotencyKey, clearIdempotencyKey } from '../../../../idempotency';

interface RunRef {
    id: string;
    runKind: 'regular' | 'correction';
    status: 'draft' | 'calculated' | 'approved' | 'posted';
}

interface Period {
    id: string;
    periodMonth: string;
    startsOn: string;
    endsOn: string;
    paymentDate: string | null;
    status: 'draft' | 'open' | 'closed';
    hasRegularRun: boolean;
    runs: RunRef[];
}

interface Props {
    periods: Period[];
    canViewRuns: boolean;
}

defineProps<Props>();

const showForm = ref(false);
const form = useForm({ period_month: '', payment_date: '' });

function submit(): void {
    form.post('/app/payroll/periods', {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}

const busy = ref<string | null>(null);

function open(id: string): void {
    busy.value = id;
    router.post(`/app/payroll/periods/${id}/open`, {}, { onFinish: () => (busy.value = null) });
}

function close(id: string): void {
    if (
        !window.confirm(
            'Close this period? No new run may be created or calculated against it afterwards.',
        )
    )
        return;

    busy.value = id;
    router.post(`/app/payroll/periods/${id}/close`, {}, { onFinish: () => (busy.value = null) });
}

function createRun(periodId: string): void {
    busy.value = periodId;
    const key = idempotencyKey(`payroll-create-run-${periodId}`);
    router.post(
        `/app/payroll/periods/${periodId}/runs`,
        {},
        {
            headers: { 'Idempotency-Key': key },
            onSuccess: () => clearIdempotencyKey(`payroll-create-run-${periodId}`),
            onFinish: () => (busy.value = null),
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <div class="mt-2 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Payroll Periods &amp; Runs</h1>
            <button
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'New period' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-4 space-y-3 rounded border border-slate-200 p-4"
            @submit.prevent="submit"
        >
            <div>
                <label class="block text-sm text-slate-600" for="period_month">Month</label>
                <input
                    id="period_month"
                    v-model="form.period_month"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.period_month" class="mt-1 text-sm text-red-600">
                    {{ form.errors.period_month }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="payment_date"
                    >Payment date (optional)</label
                >
                <input
                    id="payment_date"
                    v-model="form.payment_date"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.payment_date" class="mt-1 text-sm text-red-600">
                    {{ form.errors.payment_date }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Creating…' : 'Create period' }}
            </button>
        </form>

        <div class="mt-6 space-y-4">
            <div v-for="p in periods" :key="p.id" class="rounded border border-slate-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium">{{ p.periodMonth }}</p>
                        <p class="text-xs text-slate-500">
                            {{ p.startsOn }} – {{ p.endsOn }}
                            <span v-if="p.paymentDate"> · Pays {{ p.paymentDate }}</span>
                        </p>
                    </div>
                    <StatusBadge :status="p.status" />
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button
                        v-if="p.status === 'draft'"
                        type="button"
                        :disabled="busy === p.id"
                        class="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50"
                        @click="open(p.id)"
                    >
                        Open period
                    </button>
                    <button
                        v-if="p.status === 'open'"
                        type="button"
                        :disabled="busy === p.id"
                        class="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50"
                        @click="close(p.id)"
                    >
                        Close period
                    </button>
                    <button
                        v-if="p.status === 'open' && !p.hasRegularRun"
                        type="button"
                        :disabled="busy === p.id"
                        class="rounded bg-slate-900 px-2 py-1 text-xs font-medium text-white disabled:opacity-50"
                        @click="createRun(p.id)"
                    >
                        Create payroll run
                    </button>
                </div>

                <ul v-if="canViewRuns && p.runs.length > 0" class="mt-3 space-y-1 text-sm">
                    <li v-for="r in p.runs" :key="r.id" class="flex items-center gap-2">
                        <a class="underline" :href="`/app/payroll/runs/${r.id}`">{{
                            r.runKind === 'regular' ? 'Regular run' : 'Correction run'
                        }}</a>
                        <StatusBadge :status="r.status" />
                    </li>
                </ul>
                <p v-else-if="canViewRuns" class="mt-3 text-sm text-slate-500">
                    No runs yet for this period.
                </p>
            </div>
        </div>

        <p v-if="periods.length === 0" class="mt-4 text-sm text-slate-500">
            No Payroll periods yet.
        </p>
    </main>
</template>
