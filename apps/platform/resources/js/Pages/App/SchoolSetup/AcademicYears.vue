<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';

interface AcademicYear {
    id: string;
    name: string;
    code: string;
    startsOn: string;
    endsOn: string;
    status: string;
}

interface Props {
    academicYears: AcademicYear[];
    canManage: boolean;
}

defineProps<Props>();

const form = useForm({ name: '', code: '', starts_on: '', ends_on: '' });

function submit() {
    form.post('/app/school-setup/academic-years', { onSuccess: () => form.reset() });
}

function activate(year: AcademicYear) {
    if (!confirm(`Activate "${year.name}"? Any currently active Academic Year will be closed.`)) {
        return;
    }
    router.post(`/app/school-setup/academic-years/${year.id}/activate`);
}

function close(year: AcademicYear) {
    if (
        !confirm(
            `Close "${year.name}"? This marks it historical and it can no longer be reopened from this screen.`,
        )
    ) {
        return;
    }
    router.post(`/app/school-setup/academic-years/${year.id}/close`);
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Academic Years</h1>

        <p v-if="academicYears.length === 0" class="mt-4 text-sm text-slate-500">
            No Academic Year exists yet. Create the first Academic Year to configure Terms and
            Sections.
        </p>
        <ul v-else class="mt-4 divide-y divide-slate-200 rounded border border-slate-200">
            <li
                v-for="year in academicYears"
                :key="year.id"
                class="flex items-center justify-between px-4 py-3 text-sm"
            >
                <div>
                    <span class="font-medium">{{ year.name }}</span>
                    <span class="text-slate-400">
                        ({{ year.code }}) · {{ year.startsOn }} – {{ year.endsOn }}</span
                    >
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="{
                            'bg-emerald-100 text-emerald-700': year.status === 'active',
                            'bg-slate-100 text-slate-600': year.status === 'draft',
                            'bg-amber-100 text-amber-700': year.status === 'closed',
                        }"
                    >
                        {{ year.status }}
                    </span>
                    <button
                        v-if="canManage && year.status === 'draft'"
                        class="text-xs underline"
                        @click="activate(year)"
                    >
                        Activate
                    </button>
                    <button
                        v-if="canManage && year.status === 'active'"
                        class="text-xs underline"
                        @click="close(year)"
                    >
                        Close
                    </button>
                </div>
            </li>
        </ul>

        <form v-if="canManage" class="mt-6 grid grid-cols-2 gap-3" @submit.prevent="submit">
            <div class="col-span-2">
                <label class="block text-sm text-slate-600" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="2026-27"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <div></div>
            <div>
                <label class="block text-sm text-slate-600" for="starts_on">Starts on</label>
                <input
                    id="starts_on"
                    v-model="form.starts_on"
                    type="date"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="ends_on">Ends on</label>
                <input
                    id="ends_on"
                    v-model="form.ends_on"
                    type="date"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="col-span-2 rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Add Academic Year
            </button>
            <p v-if="form.errors.code" class="col-span-2 text-sm text-red-600">
                {{ form.errors.code }}
            </p>
        </form>
    </main>
</template>
