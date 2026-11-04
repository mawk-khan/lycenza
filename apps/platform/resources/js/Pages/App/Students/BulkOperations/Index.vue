<script setup lang="ts">
// SIS 11.13: Students → Bulk operations, step 1 (Select). Choose the move,
// one source class, the Students, the target class and the date; then
// Preview. Nothing is moved here. The server re-checks everything at the
// preview and again, under lock, at the final confirmation.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

interface Option {
    id: string;
    name: string;
}

interface SectionOption {
    id: string;
    label: string;
    academicYearId: string;
    campusId: string;
    gradeLevelId: string;
}

interface RosterRow {
    studentId: string;
    enrollmentId: string;
    studentNumber: string | null;
    name: string;
    rollNumber: string | null;
    studentStatus: string | null;
}

interface Props {
    operations: { value: string; label: string }[];
    filters: { operation: string; section_id: string; q: string };
    years: (Option & { status: string })[];
    campuses: Option[];
    gradeLevels: Option[];
    sections: SectionOption[];
    source: { id: string; label: string; academicYearId: string } | null;
    students: RosterRow[];
    targets: { id: string; label: string; occupancy?: string | null }[];
    today: string;
    effectiveDateEditable: boolean;
    limits: { students: number; list: number };
    rolloverHint: boolean;
}

const props = defineProps<Props>();
const page = usePage<{ errors: Record<string, string> }>();

const operation = ref(props.filters.operation);
const sourceSection = props.sections.find((s) => s.id === props.filters.section_id);
const yearId = ref(sourceSection?.academicYearId ?? props.years[0]?.id ?? '');
const campusId = ref(sourceSection?.campusId ?? '');
const gradeLevelId = ref(sourceSection?.gradeLevelId ?? '');
const sectionId = ref(props.filters.section_id);
const search = ref(props.filters.q);

const sectionChoices = computed(() =>
    props.sections.filter(
        (s) =>
            (yearId.value === '' || s.academicYearId === yearId.value) &&
            (campusId.value === '' || s.campusId === campusId.value) &&
            (gradeLevelId.value === '' || s.gradeLevelId === gradeLevelId.value),
    ),
);

function visit(params: Record<string, string>): void {
    router.get('/app/students/bulk-operations', params, { preserveState: true, replace: true });
}

function chooseSource(): void {
    visit({ operation: operation.value, section_id: sectionId.value });
}

function chooseOperation(): void {
    visit({ operation: operation.value, section_id: sectionId.value });
}

function applySearch(): void {
    visit({ operation: operation.value, section_id: sectionId.value, q: search.value });
}

// Selection: explicit rows only. Kept across a search within the same
// source class (shown as "hidden by the search"), cleared when the class or
// the move changes. The review lists every selected Student.
const selected = reactive(new Map<string, RosterRow>());
watch(
    () => [props.filters.section_id, props.filters.operation],
    () => {
        selected.clear();
        targetSectionId.value = '';
    },
);

const visibleIds = computed(() => new Set(props.students.map((s) => s.studentId)));
const hiddenSelected = computed(
    () => [...selected.keys()].filter((id) => !visibleIds.value.has(id)).length,
);
const allVisibleSelected = computed(
    () => props.students.length > 0 && props.students.every((s) => selected.has(s.studentId)),
);

function toggle(row: RosterRow, on: boolean): void {
    if (on) {
        selected.set(row.studentId, row);
    } else {
        selected.delete(row.studentId);
    }
}

function selectVisible(): void {
    for (const row of props.students) {
        selected.set(row.studentId, row);
    }
}

function clearSelection(): void {
    selected.clear();
}

const targetYearId = ref(props.source?.academicYearId ?? '');
watch(
    () => props.source?.academicYearId,
    (value) => {
        targetYearId.value = value ?? '';
    },
);
const crossYear = computed(
    () => props.source !== null && targetYearId.value !== props.source.academicYearId,
);
const targetSectionId = ref('');
const effectiveDate = ref(props.today);

const overLimit = computed(() => selected.size > props.limits.students);
const canPreview = computed(
    () =>
        props.source !== null &&
        selected.size > 0 &&
        !overLimit.value &&
        !crossYear.value &&
        targetSectionId.value !== '' &&
        effectiveDate.value !== '',
);
const previewing = ref(false);

function preview(): void {
    if (!canPreview.value || props.source === null) {
        return;
    }
    previewing.value = true;
    router.post(
        '/app/students/bulk-operations/preview',
        {
            operation: operation.value,
            source_section_id: props.source.id,
            target_section_id: targetSectionId.value,
            effective_date: props.effectiveDateEditable ? effectiveDate.value : props.today,
            rows: [...selected.values()].map((r) => ({
                student_id: r.studentId,
                enrollment_id: r.enrollmentId,
                roll_number: r.rollNumber ?? '',
            })),
        },
        { onFinish: () => (previewing.value = false) },
    );
}

const operationHelp: Record<string, string> = {
    section_change: 'Move Students to another Section of the same Academic Year, Campus and Grade.',
    campus_transfer:
        'Move Students to a class of the same Academic Year and Grade on another Campus. Hostel, transport and elective arrangements are never moved: they must be resolved first.',
};
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Bulk operations</h1>
        <p class="mt-1 max-w-3xl text-sm text-ink-muted">
            Move several Students of one class at once. You review every Student before anything
            changes, confirm with an authentication code, and then either every Student is moved or
            none is. Moving Students into another Academic Year is done through
            <a class="underline" href="/app/enrollment-rollovers">Academic-Year Rollover</a>.
        </p>

        <p
            v-if="rolloverHint"
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 px-3 py-2 text-sm text-ink"
            role="status"
        >
            Moving Students to another Academic Year or Grade is done through
            <a class="underline" href="/app/enrollment-rollovers">Academic-Year Rollover</a>.
        </p>

        <!-- 1. Operation -->
        <fieldset class="mt-6">
            <legend class="text-base font-semibold">1. What do you want to do?</legend>
            <div class="mt-2 flex flex-wrap gap-4">
                <label
                    v-for="o in operations"
                    :key="o.value"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="operation"
                        type="radio"
                        name="operation"
                        :value="o.value"
                        @change="chooseOperation"
                    />
                    {{ o.label }}
                </label>
            </div>
            <p class="mt-1 text-sm text-ink-muted">{{ operationHelp[operation] }}</p>
        </fieldset>

        <!-- 2. Source class -->
        <section class="mt-6" aria-labelledby="source-heading">
            <h2 id="source-heading" class="text-base font-semibold">2. Source class</h2>
            <div class="mt-2 grid gap-3 sm:grid-cols-4">
                <div>
                    <label class="block text-sm text-ink-muted" for="source-year"
                        >Academic Year</label
                    >
                    <select id="source-year" v-model="yearId" class="input mt-1">
                        <option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="source-campus">Campus</label>
                    <select id="source-campus" v-model="campusId" class="input mt-1">
                        <option value="">All campuses</option>
                        <option v-for="c in campuses" :key="c.id" :value="c.id">
                            {{ c.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="source-grade">Grade</label>
                    <select id="source-grade" v-model="gradeLevelId" class="input mt-1">
                        <option value="">All grades</option>
                        <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="source-section">Section</label>
                    <select
                        id="source-section"
                        v-model="sectionId"
                        class="input mt-1"
                        @change="chooseSource"
                    >
                        <option value="" disabled>Choose a class</option>
                        <option v-for="s in sectionChoices" :key="s.id" :value="s.id">
                            {{ s.label }}
                        </option>
                    </select>
                </div>
            </div>
            <p v-if="sectionChoices.length === 0" class="mt-2 text-sm text-ink-muted">
                No class matches these choices.
            </p>
        </section>

        <!-- 3. Students -->
        <section v-if="source" class="mt-6" aria-labelledby="students-heading">
            <h2 id="students-heading" class="text-base font-semibold">
                3. Students in {{ source.label }}
            </h2>
            <form class="mt-2 flex flex-wrap items-end gap-2" @submit.prevent="applySearch">
                <div>
                    <label class="block text-sm text-ink-muted" for="student-search"
                        >Find by name or student number</label
                    >
                    <input
                        id="student-search"
                        v-model="search"
                        type="search"
                        maxlength="100"
                        class="input mt-1 w-64 max-w-full"
                    />
                </div>
                <button type="submit" class="btn btn-secondary">Search</button>
            </form>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="students.length === 0 || allVisibleSelected"
                    @click="selectVisible"
                >
                    Select all shown ({{ students.length }})
                </button>
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="selected.size === 0"
                    @click="clearSelection"
                >
                    Clear selection
                </button>
                <p class="text-sm" role="status" aria-live="polite" data-testid="selected-count">
                    {{ selected.size }} selected<span v-if="hiddenSelected > 0">
                        ({{ hiddenSelected }} hidden by the search)</span
                    >
                </p>
            </div>
            <p v-if="overLimit" class="mt-2 text-sm text-danger" role="alert">
                A bulk move takes at most {{ limits.students }} Students at a time.
            </p>

            <p v-if="students.length === 0" class="mt-3 text-sm text-ink-muted">
                No Student in this class matches.
            </p>
            <div
                v-else
                class="relative mt-3 max-h-[28rem] overflow-auto rounded-xs border border-line"
            >
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <caption class="sr-only">
                        Students in the source class
                    </caption>
                    <thead class="sticky top-0 bg-graphite-100">
                        <tr>
                            <th scope="col" class="w-12 px-3 py-2">Select</th>
                            <th scope="col" class="px-3 py-2">Student</th>
                            <th scope="col" class="px-3 py-2">Number</th>
                            <th scope="col" class="px-3 py-2">Roll</th>
                            <th scope="col" class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in students"
                            :key="row.studentId"
                            class="border-t border-line"
                        >
                            <td class="px-3 py-2">
                                <input
                                    :id="`select-${row.studentId}`"
                                    type="checkbox"
                                    class="h-4 w-4"
                                    :checked="selected.has(row.studentId)"
                                    :aria-label="`Select ${row.name} (${row.studentNumber})`"
                                    @change="
                                        toggle(row, ($event.target as HTMLInputElement).checked)
                                    "
                                />
                            </td>
                            <td class="px-3 py-2">
                                <label :for="`select-${row.studentId}`">{{ row.name }}</label>
                            </td>
                            <td class="px-3 py-2">{{ row.studentNumber }}</td>
                            <td class="px-3 py-2">{{ row.rollNumber }}</td>
                            <td class="px-3 py-2">
                                {{ row.studentStatus === 'active' ? 'Active' : 'Inactive' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="students.length >= limits.list" class="mt-1 text-xs text-ink-muted">
                Showing the first {{ limits.list }} Students: search to narrow the list.
            </p>
        </section>

        <!-- 4. Target -->
        <section v-if="source" class="mt-6" aria-labelledby="target-heading">
            <h2 id="target-heading" class="text-base font-semibold">4. Target</h2>
            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="block text-sm text-ink-muted" for="target-year"
                        >Academic Year</label
                    >
                    <select
                        id="target-year"
                        v-model="targetYearId"
                        class="input mt-1"
                        :aria-describedby="crossYear ? 'target-year-error' : undefined"
                    >
                        <option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="target-section"
                        >Target class</label
                    >
                    <select
                        id="target-section"
                        v-model="targetSectionId"
                        :disabled="crossYear || targets.length === 0"
                        class="input mt-1"
                    >
                        <option value="" disabled>Choose a class</option>
                        <option v-for="t in targets" :key="t.id" :value="t.id">
                            {{ t.occupancy ? `${t.label} — ${t.occupancy}` : t.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="effective-date"
                        >Effective date</label
                    >
                    <input
                        v-if="effectiveDateEditable"
                        id="effective-date"
                        v-model="effectiveDate"
                        type="date"
                        class="input mt-1"
                    />
                    <p v-else id="effective-date" class="mt-2 text-sm">
                        Today (a Campus transfer takes effect today)
                    </p>
                </div>
            </div>
            <p
                v-if="crossYear"
                id="target-year-error"
                class="mt-2 text-sm text-danger"
                role="alert"
            >
                A bulk move stays within one Academic Year. To move Students into another Academic
                Year, use
                <a class="underline" href="/app/enrollment-rollovers">Academic-Year Rollover</a>.
            </p>
            <p v-else-if="targets.length === 0" class="mt-2 text-sm text-ink-muted">
                There is no class this move can go to.
            </p>
            <p v-if="page.props.errors?.target_section_id" class="mt-1 text-sm text-danger">
                {{ page.props.errors.target_section_id }}
            </p>
            <p v-if="page.props.errors?.rows" class="mt-1 text-sm text-danger">
                {{ page.props.errors.rows }}
            </p>

            <button
                type="button"
                class="btn btn-primary mt-4"
                :disabled="!canPreview || previewing"
                @click="preview"
            >
                {{ previewing ? 'Checking…' : `Preview move of ${selected.size} Student(s)` }}
            </button>
            <p class="mt-1 text-xs text-ink-muted">
                The preview shows what would happen to each Student. Nothing is moved yet.
            </p>
        </section>
    </main>
</template>
