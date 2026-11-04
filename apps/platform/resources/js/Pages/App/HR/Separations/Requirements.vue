<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * HR.12 — the School's administrative clearance requirements. Added and
 * retired, never edited: a separation takes its own copy when it is
 * approved, so changes here never rewrite a separation already approved.
 * Library, Finance, Inventory, Timetable, Transport, Leave and School
 * access are never configured here — each separation asks those modules.
 */
interface Requirement {
    id: string;
    code: string;
    label: string;
    responsibility: string;
    mandatory: boolean;
    staffType: string | null;
    status: 'active' | 'retired';
}

const props = defineProps<{
    requirements: Requirement[];
    responsibilities: { value: string; label: string }[];
    staffTypes: { value: string; label: string }[];
}>();

const form = useForm({
    code: '',
    label: '',
    responsibility: '',
    mandatory: true as boolean,
    staff_type: '',
});
const retireForm = useForm({});

function staffTypeLabel(value: string | null): string {
    return value === null
        ? 'All staff'
        : (props.staffTypes.find((t) => t.value === value)?.label ?? value);
}

function submit(): void {
    form.transform((d) => ({ ...d, staff_type: d.staff_type || null })).post(
        '/app/hr/separations/requirements',
        {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr/separations">← Separations</a>
        <h1 class="mt-2 text-xl font-semibold">Clearance requirements</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Administrative items cleared before offboarding completes (for example keys or an ID
            card returned). Other modules are checked automatically, so they are not listed here.
        </p>

        <EmptyState
            v-if="requirements.length === 0"
            class="mt-6"
            title="No clearance requirements yet"
            description="Add the first one below."
        />
        <table v-else class="data-table mt-6 w-full">
            <caption class="sr-only">
                Clearance requirements
            </caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Item</th>
                    <th scope="col">Responsible</th>
                    <th scope="col">Applies to</th>
                    <th scope="col">Mandatory</th>
                    <th scope="col"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="r in requirements"
                    :key="r.id"
                    :class="r.status === 'retired' ? 'text-ink-muted' : ''"
                >
                    <td>{{ r.code }}</td>
                    <td>
                        {{ r.label
                        }}<span v-if="r.status === 'retired'" class="tag tag-neutral ml-1"
                            >Retired</span
                        >
                    </td>
                    <td>{{ r.responsibility }}</td>
                    <td>{{ staffTypeLabel(r.staffType) }}</td>
                    <td>{{ r.mandatory ? 'Yes' : 'No' }}</td>
                    <td>
                        <button
                            v-if="r.status === 'active'"
                            type="button"
                            class="btn btn-ghost"
                            :disabled="retireForm.processing"
                            @click="
                                retireForm.post(`/app/hr/separations/requirements/${r.id}/retire`, {
                                    preserveScroll: true,
                                })
                            "
                        >
                            Retire {{ r.code }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <form class="mt-8 space-y-4 border-t border-line pt-6" @submit.prevent="submit">
            <h2 class="text-base font-semibold">Add a requirement</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="requirement-code">Code</label>
                    <input
                        id="requirement-code"
                        v-model="form.code"
                        required
                        maxlength="40"
                        class="input mt-1"
                        :aria-invalid="form.errors.code ? 'true' : undefined"
                    />
                    <p v-if="form.errors.code" class="field-error" role="alert">
                        {{ form.errors.code }}
                    </p>
                </div>
                <div>
                    <label class="field-label" for="requirement-label">Item</label>
                    <input
                        id="requirement-label"
                        v-model="form.label"
                        required
                        maxlength="120"
                        class="input mt-1"
                        :aria-invalid="form.errors.label ? 'true' : undefined"
                    />
                    <p v-if="form.errors.label" class="field-error" role="alert">
                        {{ form.errors.label }}
                    </p>
                </div>
                <div>
                    <label class="field-label" for="requirement-responsibility">Responsible</label>
                    <select
                        id="requirement-responsibility"
                        v-model="form.responsibility"
                        required
                        class="input mt-1"
                    >
                        <option value="" disabled>Choose who clears it</option>
                        <option v-for="o in responsibilities" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.responsibility" class="field-error" role="alert">
                        {{ form.errors.responsibility }}
                    </p>
                </div>
                <div>
                    <label class="field-label" for="requirement-staff-type">Applies to</label>
                    <select
                        id="requirement-staff-type"
                        v-model="form.staff_type"
                        class="input mt-1"
                    >
                        <option value="">All staff</option>
                        <option v-for="t in staffTypes" :key="t.value" :value="t.value">
                            {{ t.label }}
                        </option>
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.mandatory" type="checkbox" />
                <span>Mandatory: offboarding cannot complete until it is cleared</span>
            </label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                Add requirement
            </button>
        </form>
    </main>
</template>
