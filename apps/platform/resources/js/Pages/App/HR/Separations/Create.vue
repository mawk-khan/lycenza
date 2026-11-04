<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

/**
 * HR.12 — record a separation for one current employment. Recording changes
 * nothing yet: another person approves it, which starts the notice period.
 */
const props = defineProps<{
    employment: { id: string; startsOn: string; status: string; endsOn: string | null };
    employee: { id: string; name: string; number: string };
    recordable: boolean;
    liveCaseId: string | null;
    today: string;
    reasons: { value: string; label: string }[];
}>();

const form = useForm({
    employment_record_id: props.employment.id,
    reason: '',
    notified_on: props.today,
    planned_last_day: '',
});
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" :href="`/app/hr/employees/${employee.id}`"
            >← {{ employee.name }}</a
        >
        <h1 class="mt-2 text-xl font-semibold">Record a separation</h1>
        <p class="mt-1 text-sm text-ink-muted">
            {{ employee.name }} ({{ employee.number }}) · employment from {{ employment.startsOn }}
        </p>

        <div v-if="!recordable" class="notice notice-attention mt-6" role="status">
            <span aria-hidden="true">!</span>
            <span v-if="liveCaseId"
                >This employment already has a separation.
                <a class="underline" :href="`/app/hr/separations/${liveCaseId}`">Open it</a>.</span
            >
            <span v-else>A separation is recorded only for a current, active employment.</span>
        </div>

        <form
            v-else
            class="mt-6 space-y-4"
            @submit.prevent="form.post('/app/hr/separations', { preserveScroll: true })"
        >
            <div>
                <label class="field-label" for="separation-reason">Reason</label>
                <select
                    id="separation-reason"
                    v-model="form.reason"
                    required
                    class="input mt-1 w-auto"
                    :aria-invalid="form.errors.reason ? 'true' : undefined"
                >
                    <option value="" disabled>Choose a reason</option>
                    <option v-for="r in reasons" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
                <p v-if="form.errors.reason" class="field-error" role="alert">
                    {{ form.errors.reason }}
                </p>
            </div>
            <div>
                <label class="field-label" for="separation-notified">Notice given on</label>
                <input
                    id="separation-notified"
                    v-model="form.notified_on"
                    type="date"
                    required
                    :max="today"
                    class="input mt-1 w-auto"
                    :aria-invalid="form.errors.notified_on ? 'true' : undefined"
                />
                <p v-if="form.errors.notified_on" class="field-error" role="alert">
                    {{ form.errors.notified_on }}
                </p>
            </div>
            <div>
                <label class="field-label" for="separation-last-day"
                    >Planned last working day</label
                >
                <p id="separation-last-day-hint" class="field-hint">
                    The last day the person is employed (inclusive). It can be changed until the
                    separation is approved.
                </p>
                <input
                    id="separation-last-day"
                    v-model="form.planned_last_day"
                    type="date"
                    required
                    :min="form.notified_on || employment.startsOn"
                    class="input mt-1 w-auto"
                    aria-describedby="separation-last-day-hint"
                    :aria-invalid="form.errors.planned_last_day ? 'true' : undefined"
                />
                <p v-if="form.errors.planned_last_day" class="field-error" role="alert">
                    {{ form.errors.planned_last_day }}
                </p>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                Record separation
            </button>
        </form>
    </main>
</template>
