<script setup lang="ts">
// Phase 0N.11 (ADR 0048): Group curriculum coverage -- each member School's
// syllabus-unit coverage for its own active academic year, read one School
// at a time, and the Group total recomputed from the summed counts.
// Read-only: no filters, no export, no drill-through.
interface Coverage {
    hasActiveAcademicYear: boolean;
    academicYearName?: string | null;
    academicYearCode?: string | null;
    planned?: number;
    completed?: number;
    inProgress?: number;
    notStarted?: number;
    coveragePercent?: string | null;
    offerings?: number;
    offeringsWithoutSyllabus?: number;
}

interface SchoolRow {
    schoolId: string;
    name: string;
    state: 'included' | 'unavailable' | 'no_active_academic_year';
    observedAt: string;
    coverage: Coverage | null;
}

interface Report {
    generatedAt: string;
    schools: SchoolRow[];
    totals: {
        planned: number;
        completed: number;
        inProgress: number;
        notStarted: number;
        coveragePercent: string | null;
        offerings: number;
        offeringsWithoutSyllabus: number;
        contributingSchools: number;
        unavailableSchools: number;
        noActiveYearSchools: number;
    };
}

defineProps<{
    group: { id: string; name: string };
    report: Report | null;
}>();

function percent(value: string | null | undefined): string {
    return value === null || value === undefined ? '—' : `${value}%`;
}

function time(iso: string): string {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/groups/${group.id}`">← {{ group.name }}</a>
        <h1 class="mt-2 text-xl font-semibold">Curriculum coverage</h1>
        <p class="mt-1 text-sm text-slate-600">
            Syllabus units completed out of those planned, for each member School's own active
            academic year. Figures count syllabus units, never people. Schools are read one at a
            time, so this is a sequence of readings, not one simultaneous view.
        </p>

        <p
            v-if="!report"
            role="alert"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
            data-testid="report-unavailable"
        >
            The report could not be produced right now. No partial figures are shown. Try again
            later.
        </p>

        <template v-else>
            <p class="mt-4 text-xs text-slate-500" data-testid="generated-at">
                Generated {{ time(report.generatedAt) }}
            </p>

            <section class="mt-6" data-testid="group-totals">
                <h2 class="text-sm font-medium text-slate-500">Group total</h2>
                <p class="mt-1 text-2xl font-semibold">
                    {{ percent(report.totals.coveragePercent) }}
                </p>
                <p class="text-sm text-slate-600">
                    {{ report.totals.completed }} completed, {{ report.totals.inProgress }} in
                    progress, {{ report.totals.notStarted }} not started of
                    {{ report.totals.planned }} planned —
                    {{ report.totals.contributingSchools }} contributing School(s)
                </p>
            </section>

            <table class="mt-6 w-full text-left text-sm" data-testid="school-rows">
                <thead>
                    <tr class="text-slate-500">
                        <th class="py-1 font-medium">School</th>
                        <th class="py-1 font-medium">Academic year</th>
                        <th class="py-1 font-medium">Coverage</th>
                        <th class="py-1 font-medium">Completed / planned</th>
                        <th class="py-1 font-medium">Read at</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in report.schools"
                        :key="s.schoolId"
                        class="border-t border-slate-200"
                        :data-state="s.state"
                    >
                        <td class="py-2">{{ s.name }}</td>
                        <template v-if="s.state === 'included' && s.coverage">
                            <td class="py-2">{{ s.coverage.academicYearName }}</td>
                            <td class="py-2">{{ percent(s.coverage.coveragePercent) }}</td>
                            <td class="py-2">
                                {{ s.coverage.completed }} / {{ s.coverage.planned }}
                            </td>
                        </template>
                        <td v-else-if="s.state === 'unavailable'" class="py-2" colspan="3">
                            Unavailable
                        </td>
                        <td v-else class="py-2" colspan="3">No active academic year</td>
                        <td class="py-2 text-xs text-slate-500">{{ time(s.observedAt) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!report.schools.length" class="mt-2 text-sm text-slate-600">
                No member Schools.
            </p>
        </template>
    </main>
</template>
