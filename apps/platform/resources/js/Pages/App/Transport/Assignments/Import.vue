<script setup lang="ts">
// ORG.9C-4 (ADR 0076 §72): add-only bulk rider assignment. Upload a CSV,
// review every row, then assign them all at once -- or nothing changes. The
// server re-checks every row and your access when you confirm; existing
// assignments are never replaced or ended here.
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Outcome =
    'ready' | 'already_assigned' | 'duplicate_row' | 'conflict' | 'invalid' | 'not_accessible';

interface PayloadRow {
    line: number;
    student_number: string;
    route_code: string;
    pickup_stop: string | null;
    dropoff_stop: string | null;
}

interface PreviewRow {
    line: number;
    studentNumber: string;
    routeCode: string;
    pickupStop: string | null;
    dropoffStop: string | null;
    studentName: string | null;
    outcome: Outcome;
    message: string;
}

const props = defineProps<{
    preview: {
        payload: PayloadRow[];
        rows: PreviewRow[];
        summary: Record<Outcome, number>;
        committable: boolean;
        issuedAt: string | null;
        signature: string | null;
    } | null;
    refusal?: string;
    limits: { rows: number; kilobytes: number; columns: string[] };
}>();

const labels: Record<Outcome, string> = {
    ready: 'Ready',
    already_assigned: 'Already assigned',
    duplicate_row: 'Repeated row',
    conflict: 'Conflict',
    invalid: 'Invalid',
    not_accessible: 'Not found or not accessible',
};
const tone: Record<Outcome, string> = {
    ready: 'text-emerald-700',
    already_assigned: 'text-slate-600',
    duplicate_row: 'text-slate-600',
    conflict: 'text-red-700',
    invalid: 'text-red-700',
    not_accessible: 'text-red-700',
};

const upload = useForm<{ file: File | null }>({ file: null });
const errors = computed(() => usePage().props.errors as Record<string, string | undefined>);
const committing = ref(false);

function pick(event: Event): void {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function validate(): void {
    upload.post('/app/transport/assignments/import/preview', { forceFormData: true });
}

function commit(): void {
    if (props.preview === null || !props.preview.committable) return;
    committing.value = true;
    router.post(
        '/app/transport/assignments/import/commit',
        {
            payload: props.preview.payload as unknown as Record<string, string>[],
            issued_at: props.preview.issuedAt,
            signature: props.preview.signature,
        },
        { onFinish: () => (committing.value = false) },
    );
}
</script>

<template>
    <Head title="Import Transport riders" />
    <main class="mx-auto max-w-5xl p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/transport/assignments">← Transport assignments</a>
        <h1 class="mt-2 text-xl font-semibold">Import Transport riders</h1>
        <p class="mt-1 text-sm text-slate-500">
            Adds new rider assignments from a CSV file. Nothing changes until you confirm, and then
            either every new row is assigned or none is. Existing assignments are never changed or
            ended here — end them first from the assignment list.
        </p>

        <section class="mt-6 rounded border border-slate-200 p-4">
            <h2 class="text-sm font-semibold text-slate-700">1. Choose a file</h2>
            <p class="mt-1 text-sm text-slate-600">
                UTF-8 CSV, up to {{ limits.rows }} rows and {{ limits.kilobytes }} KB. Columns:
                <code>{{ limits.columns.join(', ') }}</code
                >. Students are matched by their student number only, routes by route code, and
                stops by their number on the route (both stops are optional).
                <a class="underline" href="/app/transport/assignments/import/template"
                    >Download a blank template</a
                >.
            </p>
            <form class="mt-3 flex flex-wrap items-center gap-3" @submit.prevent="validate">
                <input type="file" accept=".csv,text/csv" class="text-sm" @change="pick" />
                <button
                    type="submit"
                    :disabled="upload.processing || upload.file === null"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    {{ upload.processing ? 'Checking…' : 'Check rows' }}
                </button>
            </form>
            <p v-if="errors.file" class="mt-2 text-sm text-red-700">{{ errors.file }}</p>
        </section>

        <section v-if="preview" class="mt-6">
            <h2 class="text-sm font-semibold text-slate-700">2. Review</h2>
            <p v-if="refusal" class="mt-2 rounded bg-red-50 p-3 text-sm text-red-800">
                {{ refusal }}
            </p>
            <ul class="mt-2 flex flex-wrap gap-3 text-sm">
                <li v-for="(count, key) in preview.summary" :key="key" :class="tone[key]">
                    {{ labels[key] }}: {{ count }}
                </li>
            </ul>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Line</th>
                            <th scope="col" class="py-2 font-medium">Student</th>
                            <th scope="col" class="py-2 font-medium">Route</th>
                            <th scope="col" class="py-2 font-medium">Stops</th>
                            <th scope="col" class="py-2 font-medium">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in preview.rows" :key="row.line">
                            <td class="py-2 text-slate-500">{{ row.line }}</td>
                            <td class="py-2">
                                {{ row.studentNumber }}
                                <span v-if="row.studentName" class="text-slate-500"
                                    >— {{ row.studentName }}</span
                                >
                            </td>
                            <td class="py-2">{{ row.routeCode }}</td>
                            <td class="py-2">
                                {{ row.pickupStop ?? '—' }} / {{ row.dropoffStop ?? '—' }}
                            </td>
                            <td class="py-2" :class="tone[row.outcome]">
                                {{ labels[row.outcome]
                                }}<span class="block text-xs text-slate-500">{{
                                    row.message
                                }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2 class="mt-6 text-sm font-semibold text-slate-700">3. Confirm</h2>
            <p v-if="!preview.committable" class="mt-1 text-sm text-red-700">
                Fix the rows marked Conflict, Invalid or Not accessible in your file and check it
                again. Nothing has been assigned.
            </p>
            <p v-else-if="preview.summary.ready === 0" class="mt-1 text-sm text-slate-600">
                Every row is already assigned exactly like this; there is nothing new to assign.
            </p>
            <button
                v-else
                type="button"
                :disabled="committing"
                class="mt-2 rounded bg-emerald-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                @click="commit"
            >
                {{ committing ? 'Assigning…' : `Assign ${preview.summary.ready} new rider(s)` }}
            </button>
        </section>
    </main>
</template>
