<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { statusFilterLabels, type SeparationStatus } from '../../../../separations';

/**
 * HR.12 — the separation queue. Server-filtered and paged; every row opens
 * its case. Recording a separation starts from the employee's profile
 * (Employment → Record separation).
 */
interface Row {
    id: string;
    status: SeparationStatus;
    reason: string;
    notifiedOn: string;
    plannedLastDay: string;
    finalEndsOn: string | null;
    employeeId: string;
    employeeName: string;
    employeeNumber: string;
}

const props = defineProps<{
    result: {
        rows: Row[];
        total: number;
        page: number;
        perPage: number;
        counts: Record<string, number>;
    };
    filters: { status: string; reason: string | null };
    reasons: { value: string; label: string }[];
    canConfigure: boolean;
}>();

const status = ref(props.filters.status);
const reason = ref(props.filters.reason ?? '');
const pages = computed(() => Math.max(1, Math.ceil(props.result.total / props.result.perPage)));

function load(page = 1): void {
    router.get(
        '/app/hr/separations',
        {
            status: status.value,
            reason: reason.value || undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr">← HR</a>
        <div class="mt-2 flex flex-wrap items-baseline justify-between gap-3">
            <h1 class="text-xl font-semibold">Separations</h1>
            <a v-if="canConfigure" class="text-sm underline" href="/app/hr/separations/requirements"
                >Clearance requirements</a
            >
        </div>
        <p class="mt-1 text-sm text-ink-muted">
            HR records a resignation, retirement, contract end or mutual agreement; another person
            approves it. Start one from the employee's profile.
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="load()">
            <div>
                <label class="field-label" for="separation-status">Show</label>
                <select id="separation-status" v-model="status" class="input mt-1 w-auto">
                    <option v-for="(label, key) in statusFilterLabels" :key="key" :value="key">
                        {{ label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="field-label" for="separation-reason">Reason</label>
                <select id="separation-reason" v-model="reason" class="input mt-1 w-auto">
                    <option value="">Any reason</option>
                    <option v-for="r in reasons" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Apply</button>
        </form>

        <EmptyState
            v-if="result.rows.length === 0"
            class="mt-6"
            title="No separations here"
            description="Nothing matches these filters."
        />
        <div v-else class="mt-6 overflow-x-auto">
            <table class="data-table w-full">
                <caption class="sr-only">
                    Separation cases
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Employee</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Notice given</th>
                        <th scope="col">Last working day</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in result.rows" :key="row.id">
                        <td>
                            <a class="underline" :href="`/app/hr/separations/${row.id}`">{{
                                row.employeeName
                            }}</a>
                            <span class="ml-1 text-ink-muted">{{ row.employeeNumber }}</span>
                        </td>
                        <td>{{ row.reason }}</td>
                        <td>{{ row.notifiedOn }}</td>
                        <td>{{ row.finalEndsOn ?? row.plannedLastDay }}</td>
                        <td><StatusBadge :status="row.status" /></td>
                    </tr>
                </tbody>
            </table>
            <nav
                v-if="pages > 1"
                class="mt-4 flex items-center gap-3 text-sm"
                aria-label="Separation pages"
            >
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="result.page <= 1"
                    @click="load(result.page - 1)"
                >
                    Previous
                </button>
                <span aria-live="polite">Page {{ result.page }} of {{ pages }}</span>
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="result.page >= pages"
                    @click="load(result.page + 1)"
                >
                    Next
                </button>
            </nav>
        </div>
    </main>
</template>
