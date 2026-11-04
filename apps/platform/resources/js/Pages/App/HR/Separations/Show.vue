<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { eventLabels, obligationStateLabels, type SeparationStatus } from '../../../../separations';

/**
 * HR.12 — one separation case. Everything shown comes from the server: the
 * terms, the decision, the clearance checklist (a snapshot taken when it was
 * approved), each owning module's answer about what is still outstanding,
 * and the timeline. The buttons offered are the ones the server says this
 * viewer may use; the server re-checks every one.
 */
interface Obligation {
    code: string;
    owner: string;
    label: string;
    state: 'open' | 'clear' | 'attention' | 'not_integrated';
    count: number | null;
    detail: string | null;
}

interface CaseDetail {
    id: string;
    version: number;
    status: SeparationStatus;
    reason: { value: string; label: string };
    notifiedOn: string;
    plannedLastDay: string;
    finalEndsOn: string | null;
    cancelReason: string | null;
    accessRetained: boolean;
    today: string;
    employee: { id: string; name: string | null; number: string | null };
    employment: {
        id: string;
        startsOn: string;
        endsOn: string | null;
        status: string;
        staffType: string | null;
    };
    people: { requestedBy: string | null; decidedBy: string | null; finalizedBy: string | null };
    timeline: {
        id: string;
        kind: string;
        occurredAt: string;
        actor: string | null;
        before: string | null;
        after: string | null;
        item: string | null;
    }[];
    clearance: {
        id: string;
        label: string;
        responsibility: string;
        mandatory: boolean;
        status: string;
        clearedBy: string | null;
        clearedAt: string | null;
        canClear: boolean;
    }[];
    obligations: Obligation[];
    pendingMandatory: number;
    blockingCount: number;
    actions: {
        decide: boolean;
        decideBlockedAsRequester: boolean;
        withdraw: boolean;
        changeLastDay: boolean;
        cancel: boolean;
        finalize: boolean;
        finalizeReached: boolean;
        recordAccessRetained: boolean;
        complete: boolean;
        completeReady: boolean;
    };
    cancelReasons: { value: string; label: string }[];
}

const props = defineProps<{ case: CaseDetail; hasMfaFactor: boolean }>();
const c = computed(() => props.case);
const base = computed(() => `/app/hr/separations/${props.case.id}`);

const approveForm = useForm({
    version: props.case.version,
    mfa_code: '',
    confirm: false as boolean,
});
const rejectForm = useForm({ version: props.case.version, confirm: false as boolean });
const withdrawForm = useForm({ version: props.case.version, confirm: false as boolean });
const lastDayForm = useForm({
    version: props.case.version,
    planned_last_day: props.case.plannedLastDay,
});
const cancelForm = useForm({
    version: props.case.version,
    reason: '',
    mfa_code: '',
    confirm: false as boolean,
});
const finalizeForm = useForm({
    version: props.case.version,
    mfa_code: '',
    confirm: false as boolean,
});
const accessForm = useForm({ version: props.case.version, confirm: false as boolean });
const completeForm = useForm({ version: props.case.version });
const clearForm = useForm({});

const open = ref<string | null>(null);
function toggle(panel: string): void {
    open.value = open.value === panel ? null : panel;
}

function post(form: ReturnType<typeof useForm>, action: string): void {
    form.post(`${base.value}/${action}`, {
        preserveScroll: true,
        onSuccess: () => (open.value = null),
    });
}

function clearItem(id: string): void {
    clearForm.post(`${base.value}/items/${id}/clear`, { preserveScroll: true });
}

const outstanding = computed(() => c.value.obligations.filter((o) => o.state === 'open'));
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr/separations">← Separations</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">{{ c.employee.name }}</h1>
            <StatusBadge :status="c.status" />
        </div>
        <p class="mt-1 text-sm text-ink-muted">
            {{ c.employee.number }} ·
            <a class="underline" :href="`/app/hr/employees/${c.employee.id}`">Profile</a>
        </p>

        <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-ink-muted">Reason</dt>
                <dd>{{ c.reason.label }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Notice given on</dt>
                <dd>{{ c.notifiedOn }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">
                    {{ c.finalEndsOn ? 'Last working day' : 'Planned last working day' }}
                </dt>
                <dd>{{ c.finalEndsOn ?? c.plannedLastDay }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Employment</dt>
                <dd>
                    {{ c.employment.startsOn }} – {{ c.employment.endsOn ?? 'present' }} ·
                    {{ c.employment.status.replace('_', ' ') }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-muted">Recorded by</dt>
                <dd>{{ c.people.requestedBy ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Decided by</dt>
                <dd>{{ c.people.decidedBy ?? '—' }}</dd>
            </div>
            <div v-if="c.cancelReason">
                <dt class="text-ink-muted">Cancelled because</dt>
                <dd>{{ c.cancelReason }}</dd>
            </div>
        </dl>

        <!-- Actions -->
        <section class="mt-8" aria-labelledby="separation-actions">
            <h2 id="separation-actions" class="text-base font-semibold">Actions</h2>
            <p v-if="c.actions.decideBlockedAsRequester" class="notice notice-attention mt-2">
                <span aria-hidden="true">!</span>
                You recorded this separation, so another person must approve or reject it.
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <button
                    v-if="c.actions.decide"
                    type="button"
                    class="btn btn-primary"
                    :aria-expanded="open === 'approve'"
                    @click="toggle('approve')"
                >
                    Approve separation
                </button>
                <button
                    v-if="c.actions.decide"
                    type="button"
                    class="btn btn-secondary"
                    :aria-expanded="open === 'reject'"
                    @click="toggle('reject')"
                >
                    Reject separation
                </button>
                <button
                    v-if="c.actions.withdraw"
                    type="button"
                    class="btn btn-secondary"
                    :aria-expanded="open === 'withdraw'"
                    @click="toggle('withdraw')"
                >
                    Withdraw separation
                </button>
                <button
                    v-if="c.actions.changeLastDay"
                    type="button"
                    class="btn btn-secondary"
                    :aria-expanded="open === 'lastDay'"
                    @click="toggle('lastDay')"
                >
                    Change last working day
                </button>
                <button
                    v-if="c.actions.finalize"
                    type="button"
                    class="btn btn-primary"
                    :aria-expanded="open === 'finalize'"
                    :disabled="!c.actions.finalizeReached"
                    @click="toggle('finalize')"
                >
                    Finalize separation
                </button>
                <button
                    v-if="c.actions.cancel"
                    type="button"
                    class="btn btn-danger"
                    :aria-expanded="open === 'cancel'"
                    @click="toggle('cancel')"
                >
                    Cancel separation
                </button>
                <button
                    v-if="c.actions.recordAccessRetained"
                    type="button"
                    class="btn btn-secondary"
                    :aria-expanded="open === 'access'"
                    @click="toggle('access')"
                >
                    Record School access kept
                </button>
                <button
                    v-if="c.actions.complete"
                    type="button"
                    class="btn btn-primary"
                    :disabled="!c.actions.completeReady || completeForm.processing"
                    @click="post(completeForm, 'complete')"
                >
                    Complete offboarding
                </button>
            </div>
            <p
                v-if="c.actions.finalize && !c.actions.finalizeReached"
                class="mt-2 text-sm text-ink-muted"
            >
                The separation can be finalized on or after {{ c.plannedLastDay }}.
            </p>
            <p
                v-if="c.actions.complete && !c.actions.completeReady"
                class="mt-2 text-sm text-ink-muted"
            >
                Offboarding completes once every mandatory clearance item is cleared ({{
                    c.pendingMandatory
                }}
                left) and nothing is outstanding below ({{ c.blockingCount }}).
            </p>

            <form
                v-if="open === 'approve'"
                class="mt-4 space-y-3 border-t border-line pt-4"
                @submit.prevent="post(approveForm, 'approve')"
            >
                <p class="text-sm">
                    Approving starts the notice period. The employment stays current until it is
                    finalized on or after {{ c.plannedLastDay }}.
                </p>
                <MfaCodeField
                    id="separation-approve-mfa"
                    v-model="approveForm.mfa_code"
                    :has-mfa-factor="hasMfaFactor"
                    action="Approving a separation"
                    :error="approveForm.errors.mfa_code"
                />
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="approveForm.confirm" type="checkbox" class="mt-1" />
                    <span>I have checked this separation and approve it.</span>
                </label>
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="approveForm.processing || !approveForm.confirm || !hasMfaFactor"
                >
                    Approve separation
                </button>
            </form>

            <form
                v-if="open === 'reject'"
                class="mt-4 space-y-3 border-t border-line pt-4"
                @submit.prevent="post(rejectForm, 'reject')"
            >
                <p class="text-sm">
                    Rejecting leaves the employment unchanged. The decision is final.
                </p>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="rejectForm.confirm" type="checkbox" class="mt-1" />
                    <span>I reject this separation.</span>
                </label>
                <button
                    type="submit"
                    class="btn btn-secondary"
                    :disabled="rejectForm.processing || !rejectForm.confirm"
                >
                    Reject separation
                </button>
            </form>

            <form
                v-if="open === 'withdraw'"
                class="mt-4 space-y-3 border-t border-line pt-4"
                @submit.prevent="post(withdrawForm, 'withdraw')"
            >
                <p class="text-sm">
                    Withdrawing closes this separation before any decision. The employment is
                    unchanged.
                </p>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="withdrawForm.confirm" type="checkbox" class="mt-1" />
                    <span>The resignation was withdrawn.</span>
                </label>
                <button
                    type="submit"
                    class="btn btn-secondary"
                    :disabled="withdrawForm.processing || !withdrawForm.confirm"
                >
                    Withdraw separation
                </button>
            </form>

            <form
                v-if="open === 'lastDay'"
                class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4"
                @submit.prevent="post(lastDayForm, 'last-day')"
            >
                <div>
                    <label class="field-label" for="separation-new-last-day"
                        >New last working day</label
                    >
                    <input
                        id="separation-new-last-day"
                        v-model="lastDayForm.planned_last_day"
                        type="date"
                        required
                        :min="c.notifiedOn"
                        class="input mt-1 w-auto"
                    />
                    <p v-if="lastDayForm.errors.planned_last_day" class="field-error" role="alert">
                        {{ lastDayForm.errors.planned_last_day }}
                    </p>
                </div>
                <button type="submit" class="btn btn-primary" :disabled="lastDayForm.processing">
                    Change last working day
                </button>
            </form>

            <form
                v-if="open === 'cancel'"
                class="mt-4 space-y-3 border-t border-line pt-4"
                @submit.prevent="post(cancelForm, 'cancel')"
            >
                <p class="text-sm">
                    Cancelling ends the notice period: the employment is active again. The clearance
                    checklist is kept as history.
                </p>
                <div>
                    <label class="field-label" for="separation-cancel-reason">Reason</label>
                    <select
                        id="separation-cancel-reason"
                        v-model="cancelForm.reason"
                        required
                        class="input mt-1 w-auto"
                    >
                        <option value="" disabled>Choose a reason</option>
                        <option v-for="r in c.cancelReasons" :key="r.value" :value="r.value">
                            {{ r.label }}
                        </option>
                    </select>
                    <p v-if="cancelForm.errors.reason" class="field-error" role="alert">
                        {{ cancelForm.errors.reason }}
                    </p>
                </div>
                <MfaCodeField
                    id="separation-cancel-mfa"
                    v-model="cancelForm.mfa_code"
                    :has-mfa-factor="hasMfaFactor"
                    action="Cancelling a separation"
                    :error="cancelForm.errors.mfa_code"
                />
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="cancelForm.confirm" type="checkbox" class="mt-1" />
                    <span>Cancel this approved separation.</span>
                </label>
                <button
                    type="submit"
                    class="btn btn-danger"
                    :disabled="cancelForm.processing || !cancelForm.confirm || !hasMfaFactor"
                >
                    Cancel separation
                </button>
            </form>

            <form
                v-if="open === 'finalize'"
                class="mt-4 space-y-3 border-t border-line pt-4"
                @submit.prevent="post(finalizeForm, 'finalize')"
            >
                <p class="text-sm">
                    The employment ends on {{ c.plannedLastDay }}. Its assignments, reporting lines
                    and teaching ownership end with it. Clearance and the items below do not block
                    this; they block completing offboarding.
                </p>
                <MfaCodeField
                    id="separation-finalize-mfa"
                    v-model="finalizeForm.mfa_code"
                    :has-mfa-factor="hasMfaFactor"
                    action="Finalizing a separation"
                    :error="finalizeForm.errors.mfa_code"
                />
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="finalizeForm.confirm" type="checkbox" class="mt-1" />
                    <span>End this employment on {{ c.plannedLastDay }}.</span>
                </label>
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="finalizeForm.processing || !finalizeForm.confirm || !hasMfaFactor"
                >
                    Finalize separation
                </button>
            </form>

            <form
                v-if="open === 'access'"
                class="mt-4 space-y-3 border-t border-line pt-4"
                @submit.prevent="post(accessForm, 'access-retained')"
            >
                <p class="text-sm">
                    Record this only when the person keeps School access on purpose (for example a
                    continuing non-employee role). To remove access, off-board the account under
                    <a class="underline" href="/app/settings/staff">Settings → Staff accounts</a>
                    instead.
                </p>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="accessForm.confirm" type="checkbox" class="mt-1" />
                    <span>This School access is kept deliberately.</span>
                </label>
                <button
                    type="submit"
                    class="btn btn-secondary"
                    :disabled="accessForm.processing || !accessForm.confirm"
                >
                    Record School access kept
                </button>
            </form>
        </section>

        <!-- Clearance -->
        <section class="mt-10" aria-labelledby="separation-clearance">
            <h2 id="separation-clearance" class="text-base font-semibold">Clearance</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Taken from the School's clearance requirements when the separation was approved.
                Each item is cleared by the person responsible for it; there are no waivers.
            </p>
            <EmptyState
                v-if="c.clearance.length === 0"
                class="mt-3"
                title="No clearance items"
                :description="
                    c.status === 'requested'
                        ? 'The checklist is taken when the separation is approved.'
                        : 'No requirement applied to this employment.'
                "
            />
            <table v-else class="data-table mt-3 w-full">
                <caption class="sr-only">
                    Clearance items
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Responsible</th>
                        <th scope="col">State</th>
                        <th scope="col"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in c.clearance" :key="item.id">
                        <td>
                            {{ item.label }}
                            <span v-if="item.mandatory" class="tag tag-neutral ml-1"
                                >Mandatory</span
                            >
                        </td>
                        <td>{{ item.responsibility }}</td>
                        <td>
                            <span v-if="item.status === 'cleared'" class="tag tag-positive"
                                ><span aria-hidden="true">✓</span> Cleared by
                                {{ item.clearedBy ?? '—' }}</span
                            >
                            <span v-else class="tag tag-attention"
                                ><span aria-hidden="true">!</span> Pending</span
                            >
                        </td>
                        <td>
                            <button
                                v-if="item.canClear"
                                type="button"
                                class="btn btn-ghost"
                                :disabled="clearForm.processing"
                                @click="clearItem(item.id)"
                            >
                                Clear item
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Obligations -->
        <section
            v-if="c.obligations.length > 0"
            class="mt-10"
            aria-labelledby="separation-obligations"
        >
            <h2 id="separation-obligations" class="text-base font-semibold">Across the School</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Each module answers from its own records. Nothing here is changed by the separation:
                resolve it in that module. {{ outstanding.length }} outstanding.
            </p>
            <table class="data-table mt-3 w-full">
                <caption class="sr-only">
                    Offboarding obligations by module
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Module</th>
                        <th scope="col">What</th>
                        <th scope="col">State</th>
                        <th scope="col">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in c.obligations" :key="o.code">
                        <td>{{ o.owner }}</td>
                        <td>
                            {{ o.label
                            }}<span v-if="o.count" class="text-ink-muted"> ({{ o.count }})</span>
                        </td>
                        <td>
                            <span :class="obligationStateLabels[o.state].tag"
                                ><span aria-hidden="true">{{
                                    obligationStateLabels[o.state].glyph
                                }}</span>
                                {{ obligationStateLabels[o.state].label }}</span
                            >
                        </td>
                        <td class="text-sm text-ink-muted">{{ o.detail ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Timeline -->
        <section class="mt-10" aria-labelledby="separation-timeline">
            <h2 id="separation-timeline" class="text-base font-semibold">History</h2>
            <ol class="mt-3 space-y-2 text-sm">
                <li v-for="e in c.timeline" :key="e.id" class="border-l-2 border-line pl-3">
                    <span class="font-medium">{{ eventLabels[e.kind] ?? e.kind }}</span>
                    <span v-if="e.kind === 'planned_day_changed'">
                        : {{ e.before }} → {{ e.after }}</span
                    >
                    <span v-if="e.item"> : {{ e.item }}</span>
                    <span class="block text-ink-muted"
                        >{{ new Date(e.occurredAt).toLocaleString() }} · {{ e.actor ?? '—' }}</span
                    >
                </li>
            </ol>
        </section>
    </main>
</template>
