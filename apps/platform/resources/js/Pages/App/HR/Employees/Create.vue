<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    full_name: '',
    work_email: '',
    work_phone: '',
});

function submit(): void {
    form.post('/app/hr/employees');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hr/employees">← Employees</a>
        <h1 class="mt-2 text-xl font-semibold">Add employee</h1>
        <p class="mt-1 text-sm text-slate-500">
            Core identity only -- employment, assignment, and personal details are added from the
            Employee's own profile once created.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="full-name">Full name</label>
                <input
                    id="full-name"
                    v-model="form.full_name"
                    type="text"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p class="mt-1 text-sm text-red-600">{{ form.errors.full_name }}</p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="work-email">Work email</label>
                <input
                    id="work-email"
                    v-model="form.work_email"
                    type="email"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p class="mt-1 text-sm text-red-600">{{ form.errors.work_email }}</p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="work-phone">Work phone</label>
                <input
                    id="work-phone"
                    v-model="form.work_phone"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p class="mt-1 text-sm text-red-600">{{ form.errors.work_phone }}</p>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add employee' }}
                </button>
                <a class="text-sm underline" href="/app/hr/employees">Cancel</a>
            </div>
        </form>
    </main>
</template>
