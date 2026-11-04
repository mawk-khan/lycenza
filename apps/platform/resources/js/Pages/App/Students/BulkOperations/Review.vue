<script setup lang="ts">
// SIS 11.13: step 2 (Preview) and step 3 (Confirm). Every selected Student's
// outcome as the server would decide it now -- nothing has moved. Roll
// numbers can be corrected and blocked Students removed, then checked
// again. Only a preview where every Student is Ready can be confirmed, with
// a fresh authentication code; the server checks everything again under
// lock and moves every Student or none.
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import { formatCalendarDate } from '../../../../datetime';

type State = 'ready' | 'already_in_target' | 'stale' | 'blocked';

interface Row {
    studentId: string;
    enrollmentId: string;
    rollNumber: string;
    studentNumber: string | null;
    name: string | null;
    from: { campus: string; gradeLevel: string; section: string; rollNumber: string } | null;
    state: State;
    stateLabel: string;
    message: string | null;
}

interface SectionSummary {
    id: string;
    academicYear: string;
    campus: string;
    gradeLevel: string;
    section: string;
    label: string;
}

interface Props {
    preview: {
        operation: string;
        operationLabel: string;
        scope: string;
        source: SectionSummary;
        target: SectionSummary;
        effectiveDate: string;
        rows: Row[];
        summary: Record<State, number>;
        capacity: { now: string; after: string; moving: number; warning: string | null };
        executable: boolean;
        issuedAt: string | null;
        signature: string | null;
    };
    refusal: string | null;
    hasMfaFactor: boolean;
    previewMinutes: number;
}

const props = defineProps<Props>();
const page = usePage<{ errors: Record<string, string> }>();

const rolls = ref<Record<string, string>>(
    Object.fromEntries(props.preview.rows.map((r) => [r.studentId, r.rollNumber])),
);
const dirty = computed(() =>
    props.preview.rows.some((r) => (rolls.value[r.studentId] ?? '') !== r.rollNumber),
);
const notReady = computed(() => props.preview.rows.filter((r) => r.state !== 'ready'));

function payload(rows: Row[]) {
    return {
        operation: props.preview.operation,
        source_section_id: props.preview.source.id,
        target_section_id: props.preview.target.id,
        effective_date: props.preview.effectiveDate,
        rows: rows.map((r) => ({
            student_id: r.studentId,
            enrollment_id: r.enrollmentId,
            roll_number: rolls.value[r.studentId] ?? r.rollNumber,
        })),
    };
}

const checking = ref(false);
function checkAgain(rows: Row[]): void {
    checking.value = true;
    router.post('/app/students/bulk-operations/preview', payload(rows), {
        onFinish: () => (checking.value = false),
    });
}

const confirm = ref(false);
const mfaCode = ref('');
const executing = ref(false);
function execute(): void {
    if (!props.preview.executable || dirty.value) {
        return;
    }
    executing.value = true;
    router.post(
        '/app/students/bulk-operations/execute',
        {
            ...payload(props.preview.rows),
            issued_at: props.preview.issuedAt,
            signature: props.preview.signature,
            confirm: confirm.value,
            mfa_code: mfaCode.value,
        },
        {
            onFinish: () => {
                executing.value = false;
                mfaCode.value = '';
            },
        },
    );
}

const backHref = computed(
    () =>
        `/app/students/bulk-operations?operation=${props.preview.operation}&section_id=${props.preview.source.id}`,
);

const stateClass: Record<State, string> = {
    ready: 'bg-graphite-100 text-ink border-graphite-300',
    already_in_target: 'bg-graphite-100 text-ink border-control-line',
    stale: 'bg-accent-100 text-ink border-accent-300',
    blocked: 'bg-danger-tint text-danger border-danger/40',
};
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-ink sm:p-8">
        <a class="text-sm underline" :href="backHref">← Back to the selection</a>
        <h1 class="mt-2 text-xl font-semibold">Review: {{ preview.operationLabel }}</h1>
        <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-[10rem_1fr]">
            <dt class="text-ink-muted">From</dt>
            <dd>{{ preview.source.label }}</dd>
            <dt class="text-ink-muted">To</dt>
            <dd>{{ preview.target.label }}</dd>
            <dt class="text-ink-muted">Effective</dt>
            <dd>{{ formatCalendarDate(preview.effectiveDate) }}</dd>
        </dl>

        <div
            v-if="refusal"
            class="mt-4 rounded-xs border border-danger/40 bg-danger-tint px-3 py-2 text-sm text-danger"
            role="alert"
            data-testid="bulk-refusal"
        >
            {{ refusal }}
        </div>

        <p class="mt-4 text-sm" role="status">
            Nothing has been moved yet. {{ preview.summary.ready }} of
            {{ preview.rows.length }} Student(s) ready<span v-if="preview.summary.blocked"
                >, {{ preview.summary.blocked }} blocked</span
            ><span v-if="preview.summary.stale"
                >, {{ preview.summary.stale }} changed since selection</span
            ><span v-if="preview.summary.already_in_target"
                >, {{ preview.summary.already_in_target }} already in the target class</span
            >.
        </p>

        <section class="mt-4 rounded-xs border border-line p-3" aria-labelledby="capacity-heading">
            <h2 id="capacity-heading" class="text-sm font-semibold">Target class capacity</h2>
            <p class="mt-1 text-sm">Now: {{ preview.capacity.now }}</p>
            <p class="text-sm">After this move: {{ preview.capacity.after }}</p>
            <p
                v-if="preview.capacity.warning"
                class="mt-1 text-sm text-ink"
                data-testid="capacity-warning"
            >
                {{ preview.capacity.warning }}
            </p>
        </section>

        <div class="relative mt-4 max-h-[32rem] overflow-auto rounded-xs border border-line">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <caption class="sr-only">
                    Every selected Student and what would happen to them
                </caption>
                <thead class="sticky top-0 bg-graphite-100">
                    <tr>
                        <th scope="col" class="px-3 py-2">Student</th>
                        <th scope="col" class="px-3 py-2">Now</th>
                        <th scope="col" class="px-3 py-2">Moves to</th>
                        <th scope="col" class="px-3 py-2">New roll number</th>
                        <th scope="col" class="px-3 py-2">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in preview.rows"
                        :key="row.studentId"
                        class="border-t border-line align-top"
                    >
                        <td class="px-3 py-2">
                            <span v-if="row.name">{{ row.name }}</span>
                            <span v-else class="text-ink-muted">Student not available</span>
                            <span v-if="row.studentNumber" class="block text-xs text-ink-muted">{{
                                row.studentNumber
                            }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="row.from">
                                {{ row.from.gradeLevel }} · Section {{ row.from.section }} ·
                                {{ row.from.campus }}
                                <span class="block text-xs text-ink-muted"
                                    >Roll {{ row.from.rollNumber }}</span
                                >
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            {{ preview.target.gradeLevel }} · Section {{ preview.target.section }} ·
                            {{ preview.target.campus }}
                        </td>
                        <td class="px-3 py-2">
                            <label class="sr-only" :for="`roll-${row.studentId}`"
                                >New roll number for {{ row.name ?? 'this Student' }}</label
                            >
                            <input
                                :id="`roll-${row.studentId}`"
                                v-model="rolls[row.studentId]"
                                type="text"
                                maxlength="255"
                                class="input w-24"
                                :aria-describedby="row.message ? `msg-${row.studentId}` : undefined"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="inline-block rounded-xs border px-2 py-0.5 text-xs font-medium"
                                :class="stateClass[row.state]"
                                >{{ row.stateLabel }}</span
                            >
                            <p
                                v-if="row.message"
                                :id="`msg-${row.studentId}`"
                                class="mt-1 max-w-xs text-xs text-ink"
                            >
                                {{ row.message }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                class="btn btn-secondary"
                :disabled="checking"
                @click="checkAgain(preview.rows)"
            >
                {{ dirty ? 'Check the new roll numbers' : 'Check again' }}
            </button>
            <button
                v-if="notReady.length > 0 && notReady.length < preview.rows.length"
                type="button"
                class="btn btn-secondary"
                :disabled="checking"
                @click="checkAgain(preview.rows.filter((r) => r.state === 'ready'))"
            >
                Remove {{ notReady.length }} Student(s) that are not ready and check again
            </button>
        </div>

        <section
            class="mt-6 rounded-xs border border-control-line p-4"
            aria-labelledby="confirm-heading"
        >
            <h2 id="confirm-heading" class="text-base font-semibold">Confirm the move</h2>
            <p v-if="!preview.executable" class="mt-1 text-sm text-ink">
                Every Student must be Ready before the move can be confirmed. Correct the roll
                numbers or remove the Students that are not ready, then check again.
            </p>
            <p v-else-if="dirty" class="mt-1 text-sm text-ink">
                You changed roll numbers: check again before confirming.
            </p>
            <form v-else class="mt-2 space-y-3" @submit.prevent="execute">
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="confirm" type="checkbox" class="mt-1 h-4 w-4" />
                    <span
                        >I reviewed these {{ preview.rows.length }} Student(s). Move all of them to
                        {{ preview.target.label }} — or none, if anything changed.</span
                    >
                </label>
                <p v-if="page.props.errors?.confirm" class="text-sm text-danger">
                    {{ page.props.errors.confirm }}
                </p>
                <MfaCodeField
                    id="bulk-mfa"
                    v-model="mfaCode"
                    :has-mfa-factor="hasMfaFactor"
                    action="A bulk move"
                    :error="page.props.errors?.mfa_code ?? null"
                />
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="executing || !hasMfaFactor"
                >
                    {{ executing ? 'Moving…' : `Move ${preview.rows.length} Student(s)` }}
                </button>
                <p class="text-xs text-ink-muted">
                    This preview stays valid for {{ previewMinutes }} minutes.
                </p>
            </form>
        </section>
    </main>
</template>
