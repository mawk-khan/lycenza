<script setup lang="ts">
// SIS.EXP-5 (OD-SIS-3): the add-only bulk Student & Guardian import. Upload
// a v1 CSV, review every row, then import them all at once -- or nothing.
// The server re-checks every row and your access when you confirm. Existing
// Students and Guardians are never updated, matched or merged.
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';
import { formatCalendarDate, formatDateTime } from '../../../datetime';

type Result = 'ready' | 'warning' | 'error';

interface Issue {
    field: string;
    level: 'warning' | 'error';
    message: string;
}

interface PreviewRow {
    line: number;
    result: Result;
    issues: Issue[];
    student: string;
    placement: string;
    guardians: string[];
}

interface Props {
    preview: {
        payload: { line: number; cells: Record<string, string> }[];
        rows: PreviewRow[];
        summary: Record<string, number>;
        committable: boolean;
        alreadyImported: string | null;
        issuedAt: string | null;
        signature: string | null;
        resultDigest: string | null;
    } | null;
    refusal: string | null;
    format: string;
    limits: { rows: number; kilobytes: number; minutes: number };
    columns: string[];
    relationships: string[];
    codes: {
        years: { code: string; name: string; startsOn: string; endsOn: string }[];
        campuses: { code: string; name: string }[];
        grades: { code: string; name: string }[];
        sections: { year: string; campus: string; grade: string; section: string }[];
    };
    history: {
        id: string;
        committedAt: string;
        format: string;
        rows: number;
        students: number;
        guardians: number;
        placements: number;
        actor: string | null;
    }[];
    hasMfaFactor: boolean;
}

const props = defineProps<Props>();
const page = usePage<{ errors: Record<string, string> }>();

const upload = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);
function submitUpload(): void {
    upload.post('/app/students/import/preview', { forceFormData: true, preserveScroll: true });
}

const confirm = ref(false);
const acknowledge = ref(false);
const mfaCode = ref('');
const committing = ref(false);
const hasWarnings = computed(() => (props.preview?.summary.warning ?? 0) > 0);

function commit(): void {
    if (props.preview === null || !props.preview.committable) {
        return;
    }
    committing.value = true;
    router.post(
        '/app/students/import/commit',
        {
            payload: props.preview.payload,
            issued_at: props.preview.issuedAt,
            result_digest: props.preview.resultDigest,
            signature: props.preview.signature,
            acknowledge_warnings: acknowledge.value,
            confirm: confirm.value,
            mfa_code: mfaCode.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                committing.value = false;
                mfaCode.value = '';
            },
        },
    );
}

const resultLabel: Record<Result, string> = { ready: 'Ready', warning: 'Warning', error: 'Error' };
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Import Students</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Add new Students with their Guardians and current placement from one CSV file. The
            import only ever adds: it never updates, matches or merges an existing Student or
            Guardian, sends no email or SMS and creates no portal account. Either every row is
            imported or nothing is.
        </p>

        <!-- Template and instructions -->
        <section class="mt-6" aria-labelledby="template-heading">
            <h2 id="template-heading" class="text-base font-semibold">1. The template</h2>
            <p class="mt-1 text-sm">
                <a class="underline" href="/app/students/import/template">Download the template</a>
                (format <code>{{ format }}</code
                >). Save the file as CSV UTF-8. Up to {{ limits.rows }} rows and
                {{ limits.kilobytes }} KB per file.
            </p>
            <details class="mt-2 text-sm">
                <summary class="cursor-pointer underline">How to fill it in</summary>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-700">
                    <li>
                        Required for every Student: <code>first_name</code>,
                        <code>date_of_birth</code> and the placement
                        (<code>academic_year_code</code>, <code>campus_code</code>,
                        <code>grade_code</code>, <code>section_code</code>,
                        <code>roll_number</code>, <code>start_date</code>). Dates are YYYY-MM-DD.
                    </li>
                    <li>
                        Student numbers are assigned on import, never in the file. A Student's
                        status, School and any identity, health, caste or religion detail cannot be
                        imported.
                    </li>
                    <li>
                        Up to two Guardians per Student (<code>guardian1_…</code>,
                        <code>guardian2_…</code>). Guardian 1 becomes the primary Guardian. Every
                        Guardian is NEW. Relationship is one of: {{ relationships.join(', ') }}. The
                        yes/no columns accept yes or no.
                    </li>
                    <li>
                        Siblings: give the shared Guardian the same <code>guardianN_key</code>
                        (any short label, e.g. FAM-1) on each Student's row, with exactly the same
                        name and contact details. The key is used only inside this file.
                    </li>
                </ul>
            </details>
            <details class="mt-2 text-sm">
                <summary class="cursor-pointer underline">This School's codes</summary>
                <div class="mt-2 grid gap-4 sm:grid-cols-3">
                    <div>
                        <h3 class="font-medium">Academic years</h3>
                        <ul class="text-slate-700">
                            <li v-for="y in codes.years" :key="y.code">
                                <code>{{ y.code }}</code> {{ y.name }} ({{
                                    formatCalendarDate(y.startsOn)
                                }}
                                to {{ formatCalendarDate(y.endsOn) }})
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-medium">Campuses</h3>
                        <ul class="text-slate-700">
                            <li v-for="c in codes.campuses" :key="c.code">
                                <code>{{ c.code }}</code> {{ c.name }}
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-medium">Grades</h3>
                        <ul class="text-slate-700">
                            <li v-for="g in codes.grades" :key="g.code">
                                <code>{{ g.code }}</code> {{ g.name }}
                            </li>
                        </ul>
                    </div>
                </div>
                <h3 class="mt-3 font-medium">Active Sections (year · Campus · Grade · Section)</h3>
                <p class="text-slate-700">
                    <span v-for="(s, i) in codes.sections" :key="i" class="mr-3 inline-block"
                        ><code
                            >{{ s.year }} · {{ s.campus }} · {{ s.grade }} · {{ s.section }}</code
                        ></span
                    >
                </p>
            </details>
        </section>

        <!-- Upload -->
        <section class="mt-8" aria-labelledby="upload-heading">
            <h2 id="upload-heading" class="text-base font-semibold">2. Upload and preview</h2>
            <form class="mt-2 flex flex-wrap items-end gap-3" @submit.prevent="submitUpload">
                <div>
                    <label class="block text-sm text-slate-700" for="import-file">CSV file</label>
                    <input
                        id="import-file"
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv"
                        class="mt-1 block text-sm"
                        :aria-invalid="!!upload.errors.file || !!page.props.errors?.file"
                        :aria-describedby="
                            upload.errors.file || page.props.errors?.file
                                ? 'import-file-error'
                                : undefined
                        "
                        @change="
                            upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null
                        "
                    />
                </div>
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    :disabled="upload.processing || upload.file === null"
                >
                    Preview
                </button>
            </form>
            <p
                v-if="upload.errors.file || page.props.errors?.file"
                id="import-file-error"
                class="mt-1 text-sm text-red-700"
            >
                {{ upload.errors.file || page.props.errors?.file }}
            </p>
        </section>

        <!-- Preview -->
        <section v-if="preview" class="mt-8" aria-labelledby="preview-heading">
            <h2 id="preview-heading" class="text-base font-semibold">3. Review</h2>
            <p
                v-if="refusal"
                role="alert"
                class="mt-2 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
            >
                {{ refusal }}
            </p>
            <p
                v-if="preview.alreadyImported"
                role="alert"
                class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
            >
                This exact file was already imported on
                {{ formatDateTime(preview.alreadyImported) }}. Nothing will be imported again.
            </p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-slate-500">Rows</dt>
                    <dd class="font-medium">{{ preview.summary.rows }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Ready / warning / error</dt>
                    <dd class="font-medium">
                        {{ preview.summary.ready }} / {{ preview.summary.warning }} /
                        {{ preview.summary.error }}
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Students and placements to create</dt>
                    <dd class="font-medium">{{ preview.summary.students }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Guardians / relationships</dt>
                    <dd class="font-medium">
                        {{ preview.summary.guardians }} / {{ preview.summary.relationships }}
                    </dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-slate-500">Student numbers will be assigned on import.</p>

            <div
                class="relative mt-3 max-h-[32rem] overflow-auto rounded border border-slate-200"
                role="region"
                aria-label="Preview rows"
                tabindex="0"
            >
                <table class="w-full min-w-[46rem] divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        Preview of
                        {{
                            preview.summary.rows
                        }}
                        rows
                    </caption>
                    <thead class="sticky top-0 bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Row</th>
                            <th scope="col" class="px-3 py-2 font-medium">Result</th>
                            <th scope="col" class="px-3 py-2 font-medium">Student</th>
                            <th scope="col" class="px-3 py-2 font-medium">Placement</th>
                            <th scope="col" class="px-3 py-2 font-medium">Guardians</th>
                            <th scope="col" class="px-3 py-2 font-medium">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="r in preview.rows" :key="r.line">
                            <td class="px-3 py-2">{{ r.line }}</td>
                            <td class="px-3 py-2 font-medium">
                                <span
                                    :class="
                                        r.result === 'error'
                                            ? 'text-red-700'
                                            : r.result === 'warning'
                                              ? 'text-amber-800'
                                              : 'text-emerald-800'
                                    "
                                    >{{ resultLabel[r.result] }}</span
                                >
                            </td>
                            <td class="px-3 py-2">{{ r.student }}</td>
                            <td class="px-3 py-2">{{ r.placement }}</td>
                            <td class="px-3 py-2">
                                <span v-for="g in r.guardians" :key="g" class="block">{{ g }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <ul class="space-y-1">
                                    <li v-for="(i, n) in r.issues" :key="n">
                                        <span class="font-medium">{{
                                            i.level === 'error' ? 'Error' : 'Warning'
                                        }}</span>
                                        (<code>{{ i.field }}</code
                                        >): {{ i.message }}
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Commit -->
            <form
                v-if="preview.committable"
                class="mt-6 grid max-w-xl gap-3"
                aria-labelledby="commit-heading"
                @submit.prevent="commit"
            >
                <h2 id="commit-heading" class="text-base font-semibold">4. Import</h2>
                <div v-if="hasWarnings" class="flex items-start gap-2">
                    <input id="import-acknowledge" v-model="acknowledge" type="checkbox" />
                    <label for="import-acknowledge" class="text-sm">
                        I have reviewed the {{ preview.summary.warning }} row(s) with warnings.
                        Possible existing Students and Guardians will still be created as NEW
                        records.
                    </label>
                </div>
                <div class="flex items-start gap-2">
                    <input
                        id="import-confirm"
                        v-model="confirm"
                        type="checkbox"
                        :aria-describedby="
                            page.props.errors?.confirm ? 'import-confirm-error' : undefined
                        "
                    />
                    <label for="import-confirm" class="text-sm">
                        Import {{ preview.summary.students }} new Student(s) with
                        {{ preview.summary.guardians }} Guardian(s). This cannot be undone here.
                    </label>
                </div>
                <p
                    v-if="page.props.errors?.confirm"
                    id="import-confirm-error"
                    class="text-sm text-red-700"
                >
                    {{ page.props.errors.confirm }}
                </p>
                <MfaCodeField
                    id="import-mfa"
                    v-model="mfaCode"
                    :has-mfa-factor="hasMfaFactor"
                    action="Importing Students"
                    :error="page.props.errors?.mfa_code ?? null"
                />
                <div>
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="
                            committing || !hasMfaFactor || !confirm || (hasWarnings && !acknowledge)
                        "
                    >
                        Import all rows
                    </button>
                </div>
                <p class="text-xs text-slate-500">
                    This preview can be imported for {{ limits.minutes }} minutes.
                </p>
            </form>
            <p v-else-if="!preview.alreadyImported" class="mt-4 text-sm text-red-800">
                Fix the rows marked Error in the file and upload it again. Nothing has been
                imported.
            </p>
        </section>

        <!-- History -->
        <section class="mt-10" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-base font-semibold">Past imports</h2>
            <EmptyState
                v-if="history.length === 0"
                class="mt-3"
                title="No imports yet"
                description="Committed imports appear here."
            />
            <div v-else class="relative mt-3 overflow-x-auto rounded border border-slate-200">
                <table class="w-full min-w-[36rem] divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        Past imports
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Imported</th>
                            <th scope="col" class="px-3 py-2 font-medium">By</th>
                            <th scope="col" class="px-3 py-2 font-medium">Students</th>
                            <th scope="col" class="px-3 py-2 font-medium">Guardians</th>
                            <th scope="col" class="px-3 py-2 font-medium">Format</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="b in history" :key="b.id">
                            <td class="px-3 py-2">{{ formatDateTime(b.committedAt) }}</td>
                            <td class="px-3 py-2">{{ b.actor ?? '—' }}</td>
                            <td class="px-3 py-2">{{ b.students }}</td>
                            <td class="px-3 py-2">{{ b.guardians }}</td>
                            <td class="px-3 py-2">
                                <code>{{ b.format }}</code>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
