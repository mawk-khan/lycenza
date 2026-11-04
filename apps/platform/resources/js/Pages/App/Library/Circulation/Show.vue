<script setup lang="ts">
import { formatCalendarDate, formatDate } from '../../../../datetime';
// SWC.3 (ADR 0073 section 19.5): one loan and, for a holder of
// `library.fines.view`, its fine -- amount (the stored value), days, the
// policy version it was assessed under, status -- and, with
// `library.fines.void`, voiding it (an auditable state change that cancels
// its charge; never a delete). There is no School-wide fine list (X2).
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmDialog from '../../../../Components/ConfirmDialog.vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import TextField from '../../../../Components/Form/TextField.vue';
import { feeHeadText, type FeeHeadLabel } from '../../../../support/feeIntent';

const props = defineProps<{
    loan: {
        id: string;
        titleName: string;
        copyCode: string;
        studentName: string;
        checkedOutAt: string;
        dueAt: string;
        returnedAt: string | null;
        status: string;
    };
    fine: {
        id: string;
        amount: string;
        currency: string;
        overdueDays: number;
        chargeableDays: number;
        policyVersion: number | null;
        feeHead: FeeHeadLabel | null;
        status: string;
        assessedAt: string | null;
        voidedAt: string | null;
        voidReason: string | null;
    } | null;
    canViewFines: boolean;
    canVoid: boolean;
    // ORG.8E (ADR 0076 §63): a void names a closed reason and needs a fresh code.
    voidReasons: string[];
    hasMfaFactor: boolean;
}>();

const VOID_REASON_LABELS: Record<string, string> = {
    assessed_in_error: 'Assessed in error',
    loan_record_corrected: 'Loan record corrected',
    returned_before_due: 'Returned before it was due',
    other: 'Other (explain in the note)',
};

const form = useForm({ reason_code: '', reason: '', mfa_code: '' });
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function voidFine() {
    if (!props.fine) return;
    form.post(`/app/library/fines/${props.fine.id}/void`, {
        preserveScroll: true,
        onError: () => form.reset('mfa_code'),
    });
}
</script>

<template>
    <Head :title="`Loan — ${props.loan.titleName}`" />
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/library/circulation">← Circulation</a>
        <h1 class="mt-2 text-xl font-semibold">{{ props.loan.titleName }}</h1>
        <dl class="mt-4 grid grid-cols-[9rem_1fr] gap-y-1 text-sm">
            <dt class="text-ink-muted">Copy</dt>
            <dd>{{ props.loan.copyCode }}</dd>
            <dt class="text-ink-muted">Student</dt>
            <dd>{{ props.loan.studentName }}</dd>
            <dt class="text-ink-muted">Checked out</dt>
            <dd>{{ formatCalendarDate(props.loan.checkedOutAt) }}</dd>
            <dt class="text-ink-muted">Due</dt>
            <dd>{{ formatCalendarDate(props.loan.dueAt) }}</dd>
            <dt class="text-ink-muted">Returned</dt>
            <dd>
                {{ props.loan.returnedAt ? formatCalendarDate(props.loan.returnedAt) : 'Not yet' }}
            </dd>
        </dl>

        <section v-if="props.canViewFines" class="mt-8" aria-labelledby="fine-heading">
            <h2 id="fine-heading" class="font-semibold">Overdue fine</h2>
            <p v-if="!props.fine" class="mt-2 text-sm text-ink-muted">
                No fine was assessed for this loan.
            </p>
            <template v-else>
                <dl class="mt-2 grid grid-cols-[9rem_1fr] gap-y-1 text-sm" data-testid="loan-fine">
                    <dt class="text-ink-muted">Amount</dt>
                    <dd>{{ props.fine.currency }} {{ props.fine.amount }}</dd>
                    <dt class="text-ink-muted">Days</dt>
                    <dd>
                        {{ props.fine.overdueDays }} overdue,
                        {{ props.fine.chargeableDays }} charged
                    </dd>
                    <dt class="text-ink-muted">Policy</dt>
                    <dd>
                        Version {{ props.fine.policyVersion ?? '—' }} ·
                        {{ feeHeadText(props.fine.feeHead) }}
                    </dd>
                    <dt class="text-ink-muted">Assessed</dt>
                    <dd>{{ formatDate(props.fine.assessedAt) }}</dd>
                    <dt class="text-ink-muted">Status</dt>
                    <dd>
                        <template v-if="props.fine.status === 'voided'">
                            Voided {{ formatDate(props.fine.voidedAt) }} —
                            {{ props.fine.voidReason }}
                        </template>
                        <template v-else>Assessed (charged through Finance)</template>
                    </dd>
                </dl>

                <form
                    v-if="props.canVoid && props.fine.status !== 'voided'"
                    class="mt-4 flex flex-wrap items-end gap-3"
                    @submit.prevent="dialog?.open()"
                >
                    <div class="w-72">
                        <label class="block text-sm text-ink" for="void-reason-code"
                            >Why is it voided?</label
                        >
                        <select
                            id="void-reason-code"
                            v-model="form.reason_code"
                            required
                            class="input mt-1"
                        >
                            <option value="" disabled>Choose a reason</option>
                            <option v-for="r in props.voidReasons" :key="r" :value="r">
                                {{ VOID_REASON_LABELS[r] ?? r }}
                            </option>
                        </select>
                        <p v-if="form.errors.reason_code" class="mt-1 text-sm text-danger">
                            {{ form.errors.reason_code }}
                        </p>
                    </div>
                    <div class="w-96">
                        <TextField
                            id="void-reason"
                            v-model="form.reason"
                            label="Note"
                            required
                            :maxlength="255"
                            :error="form.errors.reason"
                        />
                    </div>
                    <MfaCodeField
                        id="void-mfa"
                        v-model="form.mfa_code"
                        class="w-72"
                        :has-mfa-factor="props.hasMfaFactor"
                        action="Voiding a fine"
                        :error="form.errors.mfa_code"
                    />
                    <button
                        type="submit"
                        :disabled="
                            form.processing ||
                            !form.reason ||
                            !form.reason_code ||
                            !props.hasMfaFactor
                        "
                        class="btn btn-danger"
                    >
                        Void fine
                    </button>
                </form>
            </template>
        </section>

        <ConfirmDialog
            ref="dialog"
            title="Void this fine?"
            confirm-label="Void fine"
            @confirm="voidFine"
        >
            The fine's charge is cancelled and the void is recorded with your reason. This cannot be
            undone. A fine with a payment or a concession applied cannot be voided.
        </ConfirmDialog>
    </main>
</template>
