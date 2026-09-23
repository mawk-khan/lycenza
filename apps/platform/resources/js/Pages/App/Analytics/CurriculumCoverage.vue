<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0L.2-1 — the first Analytics report: syllabus coverage for one
 * School and one Academic Year.
 *
 * Every figure counts SYLLABUS UNITS (or unit × Section pairs), never a
 * Student, teacher or any other person. All aggregation, authorization
 * (`analytics.view`) and the fail-closed cohort policy happen on the
 * server; this page only renders what it was given. No export.
 */
interface Figures {
    planned: number;
    completed: number;
    inProgress: number;
    notStarted: number;
    coveragePercent: string | null;
}

interface SectionFigures extends Figures {
    sectionId: string;
    sectionName: string;
    sectionCode: string;
}

interface OfferingFigures extends Figures {
    subjectOfferingId: string;
    subjectCode: string;
    subjectName: string;
    gradeLevelName: string;
    campusName: string;
    activeSyllabusUnits: number;
    sectionCount: number;
    sections: SectionFigures[];
}

interface GradeFigures extends Figures {
    gradeLevelName: string;
}

interface Report {
    academicYears: { id: string; name: string; code: string; isActive: boolean }[];
    academicYearId: string | null;
    totals: Figures & { offerings: number; offeringsWithoutSyllabus: number };
    byGradeLevel: GradeFigures[];
    offerings: OfferingFigures[];
}

const props = defineProps<{ report: Report }>();

const academicYearId = ref(props.report.academicYearId ?? '');
const expanded = ref<Record<string, boolean>>({});

watch(academicYearId, (value) => {
    router.get(
        '/app/analytics/curriculum-coverage',
        { academic_year_id: value || undefined },
        { preserveState: false, replace: true },
    );
});

function percent(value: string | null): string {
    return value === null ? '—' : `${value}%`;
}

function toggle(id: string): void {
    expanded.value[id] = !expanded.value[id];
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Analytics · Curriculum Coverage</h1>
        <p class="mt-1 text-sm text-slate-500">
            How much of each required Subject Offering's syllabus the Sections have covered. Figures
            count syllabus units (unit × Section), never students or staff.
        </p>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-year">Academic Year</label>
            <select
                id="filter-year"
                v-model="academicYearId"
                class="mt-1 w-64 rounded border border-slate-300 px-3 py-2 text-sm"
            >
                <option v-if="report.academicYears.length === 0" value="">No academic years</option>
                <option v-for="year in report.academicYears" :key="year.id" :value="year.id">
                    {{ year.name }} ({{ year.code }}){{ year.isActive ? ' — active' : '' }}
                </option>
            </select>
        </div>

        <EmptyState
            v-if="report.offerings.length === 0"
            class="mt-8"
            title="No curriculum coverage to report"
            description="There are no active required Subject Offerings for this Academic Year yet."
        />

        <template v-else>
            <section
                class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4"
                data-testid="coverage-totals"
            >
                <div class="rounded border border-slate-200 p-4">
                    <p class="text-xs text-slate-500">Coverage</p>
                    <p class="mt-1 text-2xl font-semibold">
                        {{ percent(report.totals.coveragePercent) }}
                    </p>
                </div>
                <div class="rounded border border-slate-200 p-4">
                    <p class="text-xs text-slate-500">Completed</p>
                    <p class="mt-1 text-2xl font-semibold">{{ report.totals.completed }}</p>
                    <p class="text-xs text-slate-500">of {{ report.totals.planned }} planned</p>
                </div>
                <div class="rounded border border-slate-200 p-4">
                    <p class="text-xs text-slate-500">In progress</p>
                    <p class="mt-1 text-2xl font-semibold">{{ report.totals.inProgress }}</p>
                </div>
                <div class="rounded border border-slate-200 p-4">
                    <p class="text-xs text-slate-500">Not started</p>
                    <p class="mt-1 text-2xl font-semibold">{{ report.totals.notStarted }}</p>
                </div>
            </section>
            <p class="mt-2 text-xs text-slate-500">
                {{ report.totals.offerings }} required Subject Offerings;
                {{ report.totals.offeringsWithoutSyllabus }} have no active syllabus units yet.
            </p>

            <h2 class="mt-8 text-sm font-medium text-slate-500">By grade level</h2>
            <table class="mt-2 w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-slate-500">
                    <tr>
                        <th class="py-2">Grade level</th>
                        <th class="py-2 text-right">Planned</th>
                        <th class="py-2 text-right">Completed</th>
                        <th class="py-2 text-right">In progress</th>
                        <th class="py-2 text-right">Not started</th>
                        <th class="py-2 text-right">Coverage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="grade in report.byGradeLevel"
                        :key="grade.gradeLevelName"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">{{ grade.gradeLevelName }}</td>
                        <td class="py-2 text-right">{{ grade.planned }}</td>
                        <td class="py-2 text-right">{{ grade.completed }}</td>
                        <td class="py-2 text-right">{{ grade.inProgress }}</td>
                        <td class="py-2 text-right">{{ grade.notStarted }}</td>
                        <td class="py-2 text-right">{{ percent(grade.coveragePercent) }}</td>
                    </tr>
                </tbody>
            </table>

            <h2 class="mt-8 text-sm font-medium text-slate-500">By Subject Offering</h2>
            <table class="mt-2 w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-slate-500">
                    <tr>
                        <th class="py-2">Subject Offering</th>
                        <th class="py-2 text-right">Units</th>
                        <th class="py-2 text-right">Sections</th>
                        <th class="py-2 text-right">Completed</th>
                        <th class="py-2 text-right">In progress</th>
                        <th class="py-2 text-right">Not started</th>
                        <th class="py-2 text-right">Coverage</th>
                    </tr>
                </thead>
                <tbody>
                    <template
                        v-for="offering in report.offerings"
                        :key="offering.subjectOfferingId"
                    >
                        <tr class="border-b border-slate-100">
                            <td class="py-2">
                                <button
                                    v-if="offering.sections.length > 0"
                                    class="mr-1 text-slate-500"
                                    :aria-label="`Toggle sections for ${offering.subjectCode}`"
                                    @click="toggle(offering.subjectOfferingId)"
                                >
                                    {{ expanded[offering.subjectOfferingId] ? '▾' : '▸' }}
                                </button>
                                {{ offering.subjectCode }} · {{ offering.subjectName }} —
                                {{ offering.gradeLevelName }}, {{ offering.campusName }}
                            </td>
                            <td class="py-2 text-right">{{ offering.activeSyllabusUnits }}</td>
                            <td class="py-2 text-right">{{ offering.sectionCount }}</td>
                            <td class="py-2 text-right">{{ offering.completed }}</td>
                            <td class="py-2 text-right">{{ offering.inProgress }}</td>
                            <td class="py-2 text-right">{{ offering.notStarted }}</td>
                            <td class="py-2 text-right">{{ percent(offering.coveragePercent) }}</td>
                        </tr>
                        <template v-if="expanded[offering.subjectOfferingId]">
                            <tr
                                v-for="section in offering.sections"
                                :key="section.sectionId"
                                class="border-b border-slate-100 bg-slate-50 text-slate-600"
                            >
                                <td class="py-1 pl-8">Section {{ section.sectionName }}</td>
                                <td class="py-1 text-right">{{ section.planned }}</td>
                                <td class="py-1 text-right"></td>
                                <td class="py-1 text-right">{{ section.completed }}</td>
                                <td class="py-1 text-right">{{ section.inProgress }}</td>
                                <td class="py-1 text-right">{{ section.notStarted }}</td>
                                <td class="py-1 text-right">
                                    {{ percent(section.coveragePercent) }}
                                </td>
                            </tr>
                        </template>
                    </template>
                </tbody>
            </table>
        </template>
    </main>
</template>
