<script setup lang="ts">
interface RowError {
    field: string | null;
    code: string;
    message: string;
}

interface RowResult {
    row_number: number;
    status: 'created' | 'duplicate_exact' | 'duplicate_potential' | 'failed';
    employee_id: string | null;
    employee_number: string | null;
    errors: RowError[];
}

interface Props {
    result: {
        received: number;
        created: number;
        exact_duplicates: number;
        potential_duplicates: number;
        failed: number;
        rows: RowResult[];
    };
}

defineProps<Props>();

const STATUS_LABEL: Record<RowResult['status'], string> = {
    created: 'Created',
    duplicate_exact: 'Duplicate (exact match)',
    duplicate_potential: 'Possible duplicate',
    failed: 'Failed',
};
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hr/employees">← Employees</a>
        <h1 class="mt-2 text-xl font-semibold">Import result</h1>

        <dl
            class="mt-6 grid grid-cols-2 gap-4 rounded border border-slate-200 p-4 text-sm sm:grid-cols-5"
        >
            <div>
                <dt class="text-slate-500">Received</dt>
                <dd class="mt-0.5 text-lg font-semibold">{{ result.received }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Created</dt>
                <dd class="mt-0.5 text-lg font-semibold text-emerald-700">{{ result.created }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Exact duplicates</dt>
                <dd class="mt-0.5 text-lg font-semibold text-amber-700">
                    {{ result.exact_duplicates }}
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Possible duplicates</dt>
                <dd class="mt-0.5 text-lg font-semibold text-amber-700">
                    {{ result.potential_duplicates }}
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Failed</dt>
                <dd class="mt-0.5 text-lg font-semibold text-red-700">{{ result.failed }}</dd>
            </div>
        </dl>

        <table class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Row</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 font-medium">Employee</th>
                    <th scope="col" class="py-2 font-medium">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="row in result.rows" :key="row.row_number">
                    <td class="py-2">{{ row.row_number }}</td>
                    <td class="py-2">{{ STATUS_LABEL[row.status] }}</td>
                    <td class="py-2">
                        <a
                            v-if="row.employee_id"
                            class="underline"
                            :href="`/app/hr/employees/${row.employee_id}`"
                        >
                            {{ row.employee_number }}
                        </a>
                        <span v-else>—</span>
                    </td>
                    <td class="py-2 text-slate-500">
                        <span v-for="(e, i) in row.errors" :key="i">{{ e.message }} </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <a
            href="/app/hr/employees/import"
            class="mt-6 inline-block rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
        >
            Import more
        </a>
    </main>
</template>
