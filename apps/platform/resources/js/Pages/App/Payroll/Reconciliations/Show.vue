<script setup lang="ts">
// HR.R1-C6 (PAY-18, OD-HR-23): one obligation. The review states what the reviewer
// read; it is not a new approval and changes no run. The page never decides who
// may review: the server sends the reason, and re-checks it all under the run lock.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { formatDateTime } from '../../../../datetime';

interface Props {
    reconciliation: {
        id: string;
        state: 'open' | 'reviewed';
        openedAt: string;
        affectedRun: {
            id: string;
            periodMonth: string | null;
            approvedAt: string | null;
            postedAt: string | null;
            sinceReversed: boolean;
        };
        reversedRun: { id: string; periodMonth: string | null; reversedAt: string };
        exceptionStillApplies: boolean;
        blockedReason:
            | 'already_reviewed'
            | 'not_independent'
            | 'same_person'
            | 'self_beneficiary'
            | 'cannot_review'
            | 'needs_mfa'
            | null;
        reviewVersion: string | null;
        details: {
            approvedBy: string | null;
            reversedBy: string | null;
            employeesWithoutAccount: number;
            changesRecorded: number;
            changes: {
                total: number;
                page: number;
                lastPage: number;
                data: {
                    key: string;
                    employeeNumber: string;
                    employee: string;
                    effectiveFrom: string;
                    structure: string;
                }[];
            };
        } | null;
        review: {
            reviewedAt: string;
            statementCode: string;
            reviewedBy: string | null;
            employeesWithoutAccount: number | null;
        } | null;
    };
    hasMfaFactor: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const status = computed(
    () => (page.props.flash as { success?: string | null } | undefined)?.success ?? null,
);

const accepted = ref(false);
const mfaCode = ref('');
const mfaError = ref<string | null>(null);
const refusal = ref<string | null>(null);
const submitting = ref(false);

function review(): void {
    submitting.value = true;
    refusal.value = null;
    mfaError.value = null;
    router.post(
        `/app/payroll/reconciliations/${props.reconciliation.id}/review`,
        {
            mfa_code: mfaCode.value,
            review_version: props.reconciliation.reviewVersion,
            accepted: accepted.value,
        },
        {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                mfaError.value = errors.mfa_code ?? null;
                refusal.value = errors.payroll ?? errors.accepted ?? errors.message ?? null;
                mfaCode.value = '';
            },
            onFinish: () => (submitting.value = false),
        },
    );
}
</script>

<template>
    <Head title="Payroll reconciliation" />
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/payroll/reconciliations">← Reconciliations</a>
        <h1 class="mt-2 text-xl font-semibold">
            Reconciliation for {{ reconciliation.affectedRun.periodMonth ?? 'this run' }}
        </h1>
        <p class="mt-1"><StatusBadge :status="reconciliation.state" /></p>
        <p v-if="status" class="notice-success mt-4" role="status">{{ status }}</p>

        <section class="mt-6 text-sm" aria-labelledby="chronology">
            <h2 id="chronology" class="font-medium">What happened, in order</h2>
            <ol class="mt-2 list-decimal space-y-1 pl-5">
                <li v-if="reconciliation.affectedRun.approvedAt">
                    The run for {{ reconciliation.affectedRun.periodMonth }} was approved on
                    {{ formatDateTime(reconciliation.affectedRun.approvedAt) }}, while the run for
                    {{ reconciliation.reversedRun.periodMonth }} stood.
                </li>
                <li v-if="reconciliation.affectedRun.postedAt">
                    It was posted on {{ formatDateTime(reconciliation.affectedRun.postedAt) }}.
                </li>
                <li>
                    The run for {{ reconciliation.reversedRun.periodMonth }} was reversed on
                    {{ formatDateTime(reconciliation.reversedRun.reversedAt) }}. The person who
                    approved {{ reconciliation.affectedRun.periodMonth }} then became the author, or
                    the Employee, of a compensation change it paid first.
                </li>
            </ol>
            <p class="mt-2">
                The approval and the posting stay on record, unchanged. Nothing here reverses a run,
                moves money or replaces the approval.
            </p>
            <p v-if="reconciliation.affectedRun.sinceReversed" class="notice-attention mt-2">
                This run has itself been reversed since.
            </p>
            <p v-if="!reconciliation.exceptionStillApplies" class="notice-attention mt-2">
                These changes no longer form an exception on the run as it stands, for example
                because a later independent approval covers them. This reconciliation stays open
                until someone reviews it.
            </p>
            <p>
                <Link
                    class="underline"
                    :href="`/app/payroll/runs/${reconciliation.affectedRun.id}`"
                >
                    Open the affected run
                </Link>
            </p>
        </section>

        <section v-if="reconciliation.details" class="mt-6 text-sm" aria-labelledby="evidence">
            <h2 id="evidence" class="font-medium">Evidence</h2>
            <p class="mt-2">Approved by {{ reconciliation.details.approvedBy ?? '—' }}.</p>
            <p>Reversed by {{ reconciliation.details.reversedBy ?? '—' }}.</p>
            <p
                v-if="reconciliation.details.changesRecorded > reconciliation.details.changes.total"
                class="notice-attention mt-2"
            >
                {{ reconciliation.details.changesRecorded }} changes were recorded when this opened.
                {{ reconciliation.details.changes.total }} are still held: retention removed the
                rest.
            </p>
            <p
                v-if="reconciliation.details.employeesWithoutAccount > 0"
                class="notice-attention mt-2"
            >
                {{ reconciliation.details.employeesWithoutAccount }} Employee(s) with a compensation
                change in this run have no linked account, so the system cannot tell whether you are
                one of them. Your review records this.
            </p>
            <div
                v-if="reconciliation.details.changes.total > 0"
                class="mt-2 overflow-x-auto"
                tabindex="0"
                role="region"
                aria-label="Compensation changes, scrollable"
            >
                <table class="data-table w-full min-w-[32rem] text-sm">
                    <caption class="sr-only">
                        Compensation changes behind this exception
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Employee no.</th>
                            <th scope="col">Employee</th>
                            <th scope="col">Effective from</th>
                            <th scope="col">Structure</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in reconciliation.details.changes.data" :key="c.key">
                            <td>{{ c.employeeNumber }}</td>
                            <td>{{ c.employee }}</td>
                            <td>{{ c.effectiveFrom }}</td>
                            <td>{{ c.structure }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-1 text-ink-muted">
                    Page {{ reconciliation.details.changes.page }} of
                    {{ reconciliation.details.changes.lastPage }} ·
                    {{ reconciliation.details.changes.total }} changes
                </p>
                <nav
                    v-if="reconciliation.details.changes.lastPage > 1"
                    class="mt-1 flex gap-3"
                    aria-label="Evidence pages"
                >
                    <Link
                        v-if="reconciliation.details.changes.page > 1"
                        class="underline"
                        :href="`/app/payroll/reconciliations/${reconciliation.id}?independence_page=${reconciliation.details.changes.page - 1}`"
                        preserve-scroll
                    >
                        Previous
                    </Link>
                    <Link
                        v-if="
                            reconciliation.details.changes.page <
                            reconciliation.details.changes.lastPage
                        "
                        class="underline"
                        :href="`/app/payroll/reconciliations/${reconciliation.id}?independence_page=${reconciliation.details.changes.page + 1}`"
                        preserve-scroll
                    >
                        Next
                    </Link>
                </nav>
            </div>
        </section>

        <section class="mt-6 border-t border-line pt-4 text-sm" aria-labelledby="review">
            <h2 id="review" class="font-medium">Independent review</h2>

            <template v-if="reconciliation.review">
                <p class="mt-2">
                    Reviewed on {{ formatDateTime(reconciliation.review.reviewedAt)
                    }}<template v-if="reconciliation.review.reviewedBy">
                        by {{ reconciliation.review.reviewedBy }}</template
                    >. This is final.
                </p>
                <p v-if="(reconciliation.review.employeesWithoutAccount ?? 0) > 0" class="mt-1">
                    The review recorded {{ reconciliation.review.employeesWithoutAccount }}
                    Employee(s) with no linked account.
                </p>
            </template>
            <p
                v-else-if="reconciliation.blockedReason === 'not_independent'"
                class="mt-2"
                role="alert"
            >
                You took part in this run or its reversal, so you cannot review it. Someone
                independent of both must do this.
            </p>
            <p v-else-if="reconciliation.blockedReason === 'same_person'" class="mt-2" role="alert">
                One of your accounts is known to belong to a person who took part in this run or its
                reversal, so you cannot review it. Someone independent of both must do this.
            </p>
            <p
                v-else-if="reconciliation.blockedReason === 'self_beneficiary'"
                class="mt-2"
                role="alert"
            >
                This run pays a compensation change of your own for the first time, so you cannot
                review it. Someone whose own pay it does not change must do this.
            </p>
            <p
                v-else-if="reconciliation.blockedReason === 'cannot_review'"
                class="mt-2"
                role="alert"
            >
                Reviewing needs payroll.runs.approve and payroll.compensation.sensitive.view. This
                stays open until someone who holds both reviews it.
            </p>
            <p v-else-if="reconciliation.blockedReason === 'needs_mfa'" class="mt-2" role="alert">
                Verify your identity to read the evidence before you can review this.
            </p>
            <div v-else class="mt-2 space-y-3">
                <label class="flex items-start gap-2">
                    <input v-model="accepted" type="checkbox" class="mt-1" />
                    <span>
                        I have read what happened, in the order shown, and I accept it as
                        reconciled. I am not approving this run, and I am not saying the original
                        approval was independent.
                    </span>
                </label>
                <MfaCodeField
                    id="payroll-reconciliation-mfa"
                    v-model="mfaCode"
                    :has-mfa-factor="hasMfaFactor"
                    action="Reviewing a payroll reconciliation"
                    :error="mfaError"
                />
                <p v-if="refusal" class="text-sm text-danger" role="alert">{{ refusal }}</p>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="submitting || !accepted || !hasMfaFactor"
                    @click="review"
                >
                    {{ submitting ? 'Recording…' : 'Record review' }}
                </button>
            </div>
        </section>
    </main>
</template>
