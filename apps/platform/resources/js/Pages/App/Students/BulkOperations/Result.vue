<script setup lang="ts">
// SIS 11.13: the completed batch -- every moved Student, from where to where,
// from which date. Each move is in the Student's ordinary placement history
// and the movement register; there is no "undo" (a correction is another
// governed move).
import { formatCalendarDate } from '../../../../datetime';

interface SectionSummary {
    label: string;
}

interface Props {
    result: {
        operationLabel: string;
        source: SectionSummary;
        target: SectionSummary;
        effectiveDate: string;
        moved: {
            studentId: string;
            studentNumber: string | null;
            name: string | null;
            from: { rollNumber: string } | null;
            toRollNumber: string;
        }[];
    };
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students/bulk-operations">← Bulk operations</a>
        <h1 class="mt-2 text-xl font-semibold">{{ result.operationLabel }}: done</h1>
        <p class="mt-2 text-sm" role="status" data-testid="bulk-result">
            {{ result.moved.length }} Student(s) moved from {{ result.source.label }} to
            {{ result.target.label }}, effective {{ formatCalendarDate(result.effectiveDate) }}.
        </p>

        <div class="mt-4 overflow-x-auto rounded border border-slate-200">
            <table class="w-full min-w-[28rem] text-left text-sm">
                <caption class="sr-only">
                    Students moved
                </caption>
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="px-3 py-2">Student</th>
                        <th scope="col" class="px-3 py-2">Number</th>
                        <th scope="col" class="px-3 py-2">Old roll</th>
                        <th scope="col" class="px-3 py-2">New roll</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in result.moved"
                        :key="row.studentId"
                        class="border-t border-slate-100"
                    >
                        <td class="px-3 py-2">{{ row.name }}</td>
                        <td class="px-3 py-2">{{ row.studentNumber }}</td>
                        <td class="px-3 py-2">{{ row.from?.rollNumber }}</td>
                        <td class="px-3 py-2">{{ row.toRollNumber }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-sm">
            Each move is recorded in the Student's placement history and in the
            <a class="underline" href="/app/students/movements">Student movement register</a>. A
            move is never undone: to correct one, make another move.
        </p>
        <p class="mt-2 text-sm">
            <a class="underline" href="/app/students/bulk-operations">Start another bulk move</a>
            ·
            <a class="underline" href="/app/students">Students</a>
        </p>
    </main>
</template>
