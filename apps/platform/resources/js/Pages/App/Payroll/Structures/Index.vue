<script setup lang="ts">
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface SalaryStructure {
    id: string;
    code: string;
    version: number;
    name: string;
    status: 'draft' | 'active' | 'inactive';
}

interface Props {
    structures: SalaryStructure[];
    canManage: boolean;
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <div class="mt-2 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Salary Structures</h1>
            <a v-if="canManage" href="/app/payroll/structures/create" class="btn btn-primary">
                New structure / revision
            </a>
        </div>

        <table class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-ink-muted">
                    <th scope="col" class="py-2 font-medium">Code</th>
                    <th scope="col" class="py-2 font-medium">Version</th>
                    <th scope="col" class="py-2 font-medium">Name</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="s in structures" :key="s.id">
                    <td class="py-3 font-mono text-xs">
                        <a class="underline" :href="`/app/payroll/structures/${s.id}`">{{
                            s.code
                        }}</a>
                    </td>
                    <td class="py-3">v{{ s.version }}</td>
                    <td class="py-3">{{ s.name }}</td>
                    <td class="py-3"><StatusBadge :status="s.status" /></td>
                </tr>
            </tbody>
        </table>

        <p v-if="structures.length === 0" class="mt-4 text-sm text-ink-muted">
            No Salary Structures yet.
        </p>
    </main>
</template>
