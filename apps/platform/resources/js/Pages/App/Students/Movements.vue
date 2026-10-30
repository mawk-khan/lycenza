<script setup lang="ts">
// SIS.EXP-2: the School-wide Student movement and exit register -- read
// only. Every row is a projection of the canonical history (placements,
// executed rollovers, lifecycle events); this page changes nothing and
// offers no export. The server decides what is listed and who may open a
// Student.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import { formatCalendarDate } from '../../../datetime';
import { LIFECYCLE_REASON_LABELS } from '../../../lifecycleReasons';

interface Placement {
    academicYear: string | null;
    campus: string | null;
    gradeLevel: string | null;
    section: string | null;
    status: string;
}

interface Movement {
    id: string;
    effectiveDate: string;
    category: 'entry' | 'movement' | 'exit';
    type: string;
    typeLabel: string;
    student: { id: string; name: string; studentNumber: string };
    from: Placement | null;
    to: Placement | null;
    reasonCode: string | null;
}

interface Option {
    id: string;
    name: string;
}

interface Props {
    movements: {
        data: Movement[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    options: {
        academicYears: Option[];
        campuses: Option[];
        gradeLevels: Option[];
        sections: {
            id: string;
            label: string;
            academicYearId: string;
            campusId: string;
            gradeLevelId: string;
        }[];
        categories: { value: string; label: string }[];
        types: { value: string; label: string; category: string }[];
    };
    canOpenStudent: boolean;
}

const props = defineProps<Props>();

const form = ref({ ...props.filters });

const CATEGORY_LABELS: Record<string, string> = {
    entry: 'Entry',
    movement: 'Movement',
    exit: 'Exit',
};

const typeOptions = computed(() =>
    props.options.types.filter(
        (t) => form.value.category === '' || t.category === form.value.category,
    ),
);
const sectionOptions = computed(() =>
    props.options.sections.filter(
        (s) =>
            (form.value.academic_year_id === '' ||
                s.academicYearId === form.value.academic_year_id) &&
            (form.value.campus_id === '' || s.campusId === form.value.campus_id) &&
            (form.value.grade_level_id === '' || s.gradeLevelId === form.value.grade_level_id),
    ),
);
const hasFilters = computed(() =>
    Object.entries(props.filters).some(([k, v]) => k !== 'sort' && v !== ''),
);

function apply(): void {
    if (form.value.type !== '' && !typeOptions.value.some((t) => t.value === form.value.type)) {
        form.value.type = '';
    }
    if (
        form.value.section_id !== '' &&
        !sectionOptions.value.some((s) => s.id === form.value.section_id)
    ) {
        form.value.section_id = '';
    }
    const query = Object.fromEntries(Object.entries(form.value).filter(([, v]) => v !== ''));
    router.get('/app/students/movements', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function showExits(): void {
    form.value.category = 'exit';
    form.value.type = '';
    apply();
}

function reset(): void {
    form.value = Object.fromEntries(Object.keys(props.filters).map((k) => [k, ''])) as Record<
        string,
        string
    >;
    apply();
}

function place(p: Placement | null): string {
    if (!p) {
        return '—';
    }
    return [p.gradeLevel && p.section ? `${p.gradeLevel} · ${p.section}` : p.gradeLevel, p.campus]
        .filter(Boolean)
        .join(' · ');
}

function reason(code: string | null): string {
    return code === null ? '' : (LIFECYCLE_REASON_LABELS[code] ?? code);
}
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Student movements</h1>
        <p class="mt-1 text-sm text-slate-500">
            Entries, movements and exits from each Student's placement and status history. Read
            only: changes happen on the Student and Enrollment pages.
        </p>

        <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="apply">
            <div>
                <label class="block text-sm text-slate-600" for="mv-q"
                    >Student name or number</label
                >
                <input
                    id="mv-q"
                    v-model="form.q"
                    type="search"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-category">Category</label>
                <select
                    id="mv-category"
                    v-model="form.category"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="c in options.categories" :key="c.value" :value="c.value">
                        {{ c.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-type">Movement type</label>
                <select
                    id="mv-type"
                    v-model="form.type"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="t in typeOptions" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-year">Academic year</label>
                <select
                    id="mv-year"
                    v-model="form.academic_year_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="y in options.academicYears" :key="y.id" :value="y.id">
                        {{ y.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-from">From date</label>
                <input
                    id="mv-from"
                    v-model="form.from"
                    type="date"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-to">To date</label>
                <input
                    id="mv-to"
                    v-model="form.to"
                    type="date"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-campus">Campus</label>
                <select
                    id="mv-campus"
                    v-model="form.campus_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="c in options.campuses" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-grade">Grade</label>
                <select
                    id="mv-grade"
                    v-model="form.grade_level_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="g in options.gradeLevels" :key="g.id" :value="g.id">
                        {{ g.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-section">Section</label>
                <select
                    id="mv-section"
                    v-model="form.section_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">All</option>
                    <option v-for="s in sectionOptions" :key="s.id" :value="s.id">
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="mv-sort">Sort by</label>
                <select
                    id="mv-sort"
                    v-model="form.sort"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="apply"
                >
                    <option value="">Newest first</option>
                    <option value="date_asc">Oldest first</option>
                    <option value="student">Student name</option>
                    <option value="type">Movement type</option>
                </select>
            </div>
            <div class="flex items-end gap-3 sm:col-span-2">
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Apply
                </button>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                    @click="showExits"
                >
                    Exits only
                </button>
                <button v-if="hasFilters" type="button" class="text-sm underline" @click="reset">
                    Clear filters
                </button>
            </div>
        </form>

        <EmptyState
            v-if="movements.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No movements match these filters' : 'No movements yet'"
            description="Movements appear here when Students are placed, moved, promoted, withdrawn or change status."
        />

        <template v-else>
            <p class="mt-6 text-sm text-slate-500">
                {{ movements.from }}–{{ movements.to }} of {{ movements.total }}
            </p>
            <div class="relative mt-2 overflow-x-auto rounded border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        Student movements,
                        {{
                            movements.total
                        }}
                        in total
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Date</th>
                            <th scope="col" class="px-3 py-2 font-medium">Student</th>
                            <th scope="col" class="px-3 py-2 font-medium">Movement</th>
                            <th scope="col" class="px-3 py-2 font-medium">From</th>
                            <th scope="col" class="px-3 py-2 font-medium">To</th>
                            <th scope="col" class="px-3 py-2 font-medium">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="m in movements.data" :key="m.id">
                            <td class="whitespace-nowrap px-3 py-2">
                                {{ formatCalendarDate(m.effectiveDate) }}
                            </td>
                            <td class="px-3 py-2">
                                <a
                                    v-if="canOpenStudent"
                                    class="underline"
                                    :href="`/app/students/${m.student.id}`"
                                    >{{ m.student.name }}</a
                                >
                                <span v-else>{{ m.student.name }}</span>
                                <span class="block text-xs text-slate-500">{{
                                    m.student.studentNumber
                                }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ m.typeLabel }}</span>
                                <span class="block text-xs text-slate-500">{{
                                    CATEGORY_LABELS[m.category]
                                }}</span>
                            </td>
                            <td class="px-3 py-2">
                                {{ place(m.from) }}
                                <span
                                    v-if="m.from?.academicYear"
                                    class="block text-xs text-slate-500"
                                    >{{ m.from.academicYear }}</span
                                >
                            </td>
                            <td class="px-3 py-2">
                                {{ place(m.to) }}
                                <span
                                    v-if="m.to?.academicYear"
                                    class="block text-xs text-slate-500"
                                    >{{ m.to.academicYear }}</span
                                >
                            </td>
                            <td class="px-3 py-2">{{ reason(m.reasonCode) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination class="mt-4" :links="movements.links" />
        </template>
    </main>
</template>
