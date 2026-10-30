<script setup lang="ts">
// SIS.EXP-4: a Student's Leaving/Transfer certificates -- eligibility and
// preview, issue, history, void and reissue. The server decides every
// permission and fact; the preview shows exactly what an issue would freeze.
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';
import { formatCalendarDate } from '../../../datetime';

interface HistoryRow {
    id: string;
    type: string;
    typeLabel: string;
    number: string;
    issuedOn: string;
    leftOn: string;
    status: 'issued' | 'voided';
    voidReason: string | null;
    voidReasonLabel: string | null;
    voidedOn: string | null;
    supersedesNumber: string | null;
    replacedByNumber: string | null;
    downloadUrl: string | null;
}

interface Snapshot {
    student_name: string;
    student_number: string;
    school_name: string;
    school_legal_name: string | null;
    school_affiliation_reference: string | null;
    school_registration_reference: string | null;
    campus_name: string;
    academic_year_name: string;
    grade_name: string;
    section_name: string;
    joined_on: string | null;
    left_on: string;
    exit_reason: string;
    exit_reason_label: string;
}

interface Props {
    student: { id: string; name: string; studentNumber: string; status: string };
    history: HistoryRow[];
    canIssue: boolean;
    available: boolean;
    preview: {
        eligible: boolean;
        refusal: string | null;
        printable: boolean;
        types: { value: string; label: string; fingerprint: string; alreadyIssued: boolean }[];
        snapshot: Snapshot | null;
        reissueFingerprints: Record<string, string | null>;
    } | null;
    finance: { outstanding: string | null; currency: string | null } | null;
    voidReasons: { value: string; label: string }[];
    formKey: string;
    hasMfaFactor: boolean;
}

const props = defineProps<Props>();
const base = `/app/students/${props.student.id}/certificates`;

const issueForm = useForm({
    type: '',
    preview_fingerprint: '',
    form_key: props.formKey,
    confirm: false,
    mfa_code: '',
});

function submitIssue(): void {
    issueForm.preview_fingerprint =
        props.preview?.types.find((t) => t.value === issueForm.type)?.fingerprint ?? '';
    issueForm.post(base, {
        preserveScroll: true,
        onSuccess: () => {
            issueForm.reset();
            issueForm.form_key = props.formKey;
        },
        onFinish: () => (issueForm.mfa_code = ''),
    });
}

const acting = ref<{ id: string; mode: 'void' | 'reissue' } | null>(null);
const actionForm = useForm({
    reason: '',
    confirm: false,
    mfa_code: '',
    preview_fingerprint: '',
    form_key: '',
});

function start(row: HistoryRow, mode: 'void' | 'reissue'): void {
    acting.value = { id: row.id, mode };
    actionForm.defaults({
        reason: '',
        confirm: false,
        mfa_code: '',
        preview_fingerprint: props.preview?.reissueFingerprints[row.id] ?? '',
        form_key: props.formKey,
    });
    actionForm.reset();
    actionForm.clearErrors();
}

function submitAction(row: HistoryRow): void {
    const mode = acting.value?.mode;
    actionForm.post(`${base}/${row.id}/${mode}`, {
        preserveScroll: true,
        onSuccess: () => (acting.value = null),
        onFinish: () => (actionForm.mfa_code = ''),
    });
}

const date = (v: string | null) => (v ? formatCalendarDate(v) : '—');
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← {{ student.name }}</a>
        <h1 class="mt-2 text-xl font-semibold">Certificates</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ student.name }} · Student number {{ student.studentNumber }}
        </p>
        <p class="mt-2 max-w-3xl text-sm text-slate-500">
            Leaving and Transfer certificates are issued once from the Student's recorded exit. Each
            number is used once; a correction voids the certificate and issues a new one with a new
            number. Outstanding fees never block a certificate, and no financial information is
            printed on it.
        </p>

        <!-- Issue -->
        <section v-if="canIssue" class="mt-8" aria-labelledby="issue-heading">
            <h2 id="issue-heading" class="text-base font-semibold">Issue a certificate</h2>
            <p v-if="!available" class="mt-2 text-sm text-amber-800">
                Issuing is not available in this environment: the certificate wording still needs
                legal approval for production use.
            </p>
            <template v-else-if="preview">
                <p v-if="!preview.eligible" class="mt-2 text-sm text-slate-700">
                    <span class="font-medium">Not eligible:</span> {{ preview.refusal }}
                </p>
                <template v-else>
                    <p v-if="!preview.printable" class="mt-2 text-sm text-red-800">
                        This Student's record contains characters the certificate template cannot
                        print yet (only Latin-script text is supported). Issuing will be refused.
                    </p>
                    <p v-if="finance" class="mt-2 text-sm text-slate-700">
                        <span class="font-medium">For your information:</span>
                        {{
                            finance.outstanding === null
                                ? 'No outstanding fees on record.'
                                : `Outstanding fees on record: ${finance.outstanding} ${finance.currency}.`
                        }}
                        This never affects issuing and is not printed on the certificate.
                    </p>
                    <div class="mt-3 overflow-x-auto rounded border border-slate-200">
                        <table class="w-full text-sm">
                            <caption class="px-3 py-2 text-left text-sm font-medium text-slate-600">
                                What the certificate will record (frozen when issued)
                            </caption>
                            <tbody class="divide-y divide-slate-200">
                                <tr>
                                    <th
                                        scope="row"
                                        class="w-1/3 px-3 py-2 text-left font-normal text-slate-600"
                                    >
                                        Student
                                    </th>
                                    <td class="px-3 py-2">
                                        {{ preview.snapshot?.student_name }} ({{
                                            preview.snapshot?.student_number
                                        }})
                                    </td>
                                </tr>
                                <tr>
                                    <th
                                        scope="row"
                                        class="px-3 py-2 text-left font-normal text-slate-600"
                                    >
                                        School
                                    </th>
                                    <td class="px-3 py-2">
                                        {{ preview.snapshot?.school_name }}
                                        <span
                                            v-if="preview.snapshot?.school_legal_name"
                                            class="block text-xs text-slate-500"
                                            >{{ preview.snapshot?.school_legal_name }}</span
                                        >
                                    </td>
                                </tr>
                                <tr>
                                    <th
                                        scope="row"
                                        class="px-3 py-2 text-left font-normal text-slate-600"
                                    >
                                        Last class
                                    </th>
                                    <td class="px-3 py-2">
                                        {{ preview.snapshot?.grade_name }} · Section
                                        {{ preview.snapshot?.section_name }} ·
                                        {{ preview.snapshot?.campus_name }} ({{
                                            preview.snapshot?.academic_year_name
                                        }})
                                    </td>
                                </tr>
                                <tr>
                                    <th
                                        scope="row"
                                        class="px-3 py-2 text-left font-normal text-slate-600"
                                    >
                                        Joined / left
                                    </th>
                                    <td class="px-3 py-2">
                                        {{ date(preview.snapshot?.joined_on ?? null) }} /
                                        {{ date(preview.snapshot?.left_on ?? null) }}
                                    </td>
                                </tr>
                                <tr>
                                    <th
                                        scope="row"
                                        class="px-3 py-2 text-left font-normal text-slate-600"
                                    >
                                        Reason for leaving
                                    </th>
                                    <td class="px-3 py-2">
                                        {{ preview.snapshot?.exit_reason_label }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <form class="mt-4 grid max-w-xl gap-4" @submit.prevent="submitIssue">
                        <fieldset>
                            <legend class="text-sm text-slate-700">Certificate type</legend>
                            <div
                                v-for="t in preview.types"
                                :key="t.value"
                                class="mt-1 flex items-center gap-2"
                            >
                                <input
                                    :id="`issue-type-${t.value}`"
                                    v-model="issueForm.type"
                                    type="radio"
                                    name="type"
                                    :value="t.value"
                                    :disabled="t.alreadyIssued"
                                    :aria-describedby="
                                        t.alreadyIssued ? `issue-type-${t.value}-note` : undefined
                                    "
                                />
                                <label :for="`issue-type-${t.value}`" class="text-sm">{{
                                    t.label
                                }}</label>
                                <span
                                    v-if="t.alreadyIssued"
                                    :id="`issue-type-${t.value}-note`"
                                    class="text-xs text-slate-500"
                                    >(already issued — void or reissue it below)</span
                                >
                            </div>
                            <p v-if="issueForm.errors.type" class="mt-1 text-sm text-red-700">
                                {{ issueForm.errors.type }}
                            </p>
                        </fieldset>
                        <div class="flex items-start gap-2">
                            <input
                                id="issue-confirm"
                                v-model="issueForm.confirm"
                                type="checkbox"
                                :aria-describedby="
                                    issueForm.errors.confirm ? 'issue-confirm-error' : undefined
                                "
                            />
                            <label for="issue-confirm" class="text-sm"
                                >I have checked the details above. Issuing gives this certificate a
                                permanent number.</label
                            >
                        </div>
                        <p
                            v-if="issueForm.errors.confirm"
                            id="issue-confirm-error"
                            class="text-sm text-red-700"
                        >
                            {{ issueForm.errors.confirm }}
                        </p>
                        <MfaCodeField
                            id="issue-mfa"
                            v-model="issueForm.mfa_code"
                            :has-mfa-factor="hasMfaFactor"
                            action="Issuing a certificate"
                            :error="issueForm.errors.mfa_code"
                        />
                        <div>
                            <button
                                type="submit"
                                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                :disabled="
                                    issueForm.processing || !hasMfaFactor || issueForm.type === ''
                                "
                            >
                                Issue certificate
                            </button>
                        </div>
                    </form>
                </template>
            </template>
        </section>

        <!-- History -->
        <section class="mt-10" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-base font-semibold">History</h2>
            <EmptyState
                v-if="history.length === 0"
                class="mt-3"
                title="No certificates issued"
                description="Certificates appear here once issued."
            />
            <div v-else class="relative mt-3 overflow-x-auto rounded border border-slate-200">
                <table class="w-full min-w-[44rem] divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        Certificates issued for
                        {{
                            student.name
                        }}
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Certificate</th>
                            <th scope="col" class="px-3 py-2 font-medium">Issued</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <template v-for="row in history" :key="row.id">
                            <tr>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ row.number }}</span>
                                    <span class="block text-xs text-slate-500"
                                        >{{ row.typeLabel
                                        }}<template v-if="row.supersedesNumber">
                                            · replaces {{ row.supersedesNumber }}</template
                                        ></span
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    {{ date(row.issuedOn) }}
                                </td>
                                <td class="px-3 py-2">
                                    <span v-if="row.status === 'issued'">Issued</span>
                                    <span v-else
                                        >Void<span class="block text-xs text-slate-500"
                                            >{{ row.voidReasonLabel
                                            }}<template v-if="row.replacedByNumber">
                                                · replaced by {{ row.replacedByNumber }}</template
                                            ></span
                                        ></span
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <a
                                        v-if="row.downloadUrl"
                                        class="underline"
                                        :href="row.downloadUrl"
                                        >Download<span class="sr-only"> {{ row.number }}</span></a
                                    >
                                    <template
                                        v-if="canIssue && available && row.status === 'issued'"
                                    >
                                        <button
                                            type="button"
                                            class="ml-3 underline"
                                            :aria-expanded="
                                                acting?.id === row.id && acting?.mode === 'reissue'
                                            "
                                            @click="start(row, 'reissue')"
                                        >
                                            Void and reissue<span class="sr-only">
                                                {{ row.number }}</span
                                            >
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 underline"
                                            :aria-expanded="
                                                acting?.id === row.id && acting?.mode === 'void'
                                            "
                                            @click="start(row, 'void')"
                                        >
                                            Void<span class="sr-only"> {{ row.number }}</span>
                                        </button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="acting?.id === row.id">
                                <td colspan="4" class="bg-slate-50 px-3 py-3">
                                    <form
                                        class="grid max-w-xl gap-3"
                                        :aria-label="`${acting.mode === 'void' ? 'Void' : 'Void and reissue'} ${row.number}`"
                                        @submit.prevent="submitAction(row)"
                                    >
                                        <p
                                            v-if="acting.mode === 'reissue'"
                                            class="text-sm text-slate-600"
                                        >
                                            {{ row.number }} is voided as replaced and a corrected
                                            certificate is issued with a new number from the
                                            Student's current record (the preview above). The
                                            original stays on record.
                                        </p>
                                        <p
                                            v-if="
                                                acting.mode === 'reissue' &&
                                                !actionForm.preview_fingerprint
                                            "
                                            class="text-sm text-red-800"
                                        >
                                            The Student is no longer eligible, so no replacement can
                                            be issued. Use Void instead.
                                        </p>
                                        <div v-if="acting.mode === 'void'">
                                            <label
                                                class="block text-sm"
                                                :for="`void-reason-${row.id}`"
                                                >Reason</label
                                            >
                                            <select
                                                :id="`void-reason-${row.id}`"
                                                v-model="actionForm.reason"
                                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                                :aria-describedby="
                                                    actionForm.errors.reason
                                                        ? `void-reason-error-${row.id}`
                                                        : undefined
                                                "
                                            >
                                                <option value="" disabled>Choose a reason</option>
                                                <option
                                                    v-for="r in voidReasons"
                                                    :key="r.value"
                                                    :value="r.value"
                                                >
                                                    {{ r.label }}
                                                </option>
                                            </select>
                                            <p
                                                v-if="actionForm.errors.reason"
                                                :id="`void-reason-error-${row.id}`"
                                                class="mt-1 text-sm text-red-700"
                                            >
                                                {{ actionForm.errors.reason }}
                                            </p>
                                        </div>
                                        <div class="flex items-start gap-2">
                                            <input
                                                :id="`action-confirm-${row.id}`"
                                                v-model="actionForm.confirm"
                                                type="checkbox"
                                            />
                                            <label
                                                :for="`action-confirm-${row.id}`"
                                                class="text-sm"
                                                >{{
                                                    acting.mode === 'void'
                                                        ? 'Void this certificate. Its number is never reused.'
                                                        : 'Void this certificate and issue the corrected replacement.'
                                                }}</label
                                            >
                                        </div>
                                        <p
                                            v-if="actionForm.errors.confirm"
                                            class="text-sm text-red-700"
                                        >
                                            {{ actionForm.errors.confirm }}
                                        </p>
                                        <MfaCodeField
                                            :id="`action-mfa-${row.id}`"
                                            v-model="actionForm.mfa_code"
                                            :has-mfa-factor="hasMfaFactor"
                                            :action="
                                                acting.mode === 'void'
                                                    ? 'Voiding a certificate'
                                                    : 'Reissuing a certificate'
                                            "
                                            :error="actionForm.errors.mfa_code"
                                        />
                                        <div class="flex gap-3">
                                            <button
                                                type="submit"
                                                class="rounded bg-red-800 px-3 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                                                :disabled="
                                                    actionForm.processing ||
                                                    !hasMfaFactor ||
                                                    (acting.mode === 'reissue' &&
                                                        !actionForm.preview_fingerprint)
                                                "
                                            >
                                                {{
                                                    acting.mode === 'void'
                                                        ? 'Void certificate'
                                                        : 'Void and reissue'
                                                }}
                                            </button>
                                            <button
                                                type="button"
                                                class="text-sm underline"
                                                @click="acting = null"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
