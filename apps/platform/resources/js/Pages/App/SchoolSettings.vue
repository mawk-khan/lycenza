<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface Props {
    school: { id: string; name: string; timezone: string; defaultLocale: string };
    canManage: boolean;
    // X-02: the supported identifiers; a timezone is chosen, never typed.
    timezones: string[];
}

const props = defineProps<Props>();

// Keep a (legacy) value visible even if it is not in the list.
const timezoneOptions = props.timezones.includes(props.school.timezone)
    ? props.timezones
    : [props.school.timezone, ...props.timezones];

const form = useForm({
    name: props.school.name,
    timezone: props.school.timezone,
    default_locale: props.school.defaultLocale,
});

function submit() {
    form.put('/app/settings');
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">School Settings</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="timezone">Timezone</label>
                <select
                    id="timezone"
                    v-model="form.timezone"
                    :disabled="!canManage"
                    :aria-invalid="!!form.errors.timezone"
                    aria-describedby="timezone-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                >
                    <option v-for="zone in timezoneOptions" :key="zone" :value="zone">
                        {{ zone }}
                    </option>
                </select>
                <p id="timezone-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.timezone }}
                </p>
            </div>
            <button
                v-if="canManage"
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Save
            </button>
            <p v-else class="text-sm text-slate-500">
                You have read-only access to these settings.
            </p>
        </form>
    </main>
</template>
