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
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr/employees">← Employees</a>
        <h1 class="mt-2 text-xl font-semibold">Import result</h1>

        <dl
            class="mt-6 grid grid-cols-2 gap-4 rounded-xs border border-line p-4 text-sm sm:grid-cols-5"
        >
            <div>
                <dt class="text-ink-muted">Received</dt>
                <dd class="mt-0.5 text-lg font-semibold">{{ result.received }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Created</dt>
                <dd class="mt-0.5 text-lg font-semibold text-ink">{{ result.created }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Exact duplicates</dt>
                <dd class="mt-0.5 text-lg font-semibold text-ink">
                    {{ result.exact_duplicates }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-muted">Possible duplicates</dt>
                <dd class="mt-0.5 text-lg font-semibold text-ink">
                    {{ result.potential_duplicates }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-muted">Failed</dt>
                <dd class="mt-0.5 text-lg font-semibold text-danger">{{ result.failed }}</dd>
            </div>
        </dl>

        <table class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-ink-muted">
                    <th scope="col" class="py-2 font-medium">Row</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 font-medium">Employee</th>
                    <th scope="col" class="py-2 font-medium">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
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
                    <td class="py-2 text-ink-muted">
                        <span v-for="(e, i) in row.errors" :key="i">{{ e.message }} </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <a href="/app/hr/employees/import" class="btn btn-secondary mt-6 inline-block">
            Import more
        </a>
    </main>
</template>
