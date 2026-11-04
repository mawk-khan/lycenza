<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * HR.13 — assign a one-off task. The server lists only people the viewer may
 * assign to (anyone currently employed for HR; current direct reports for a
 * Team Task Manager) and re-checks everything when it saves.
 */
const props = defineProps<{
    categories: { id: string; label: string }[];
    academic: {
        sections: { id: string; label: string }[];
        subjectOfferings: { id: string; label: string }[];
    };
    today: string;
}>();

const form = useForm({
    employment_record_id: '',
    title: '',
    description: '',
    category_id: '',
    completion_mode: 'reviewed',
    available_on: props.today,
    due_on: props.today,
    section_id: '',
    subject_offering_id: '',
});
const search = ref('');
const candidates = ref<{ employmentRecordId: string; label: string }[]>([]);
const searched = ref(false);

async function findPeople(): Promise<void> {
    const response = await fetch(`/app/hr/tasks/assignees?q=${encodeURIComponent(search.value)}`, {
        headers: { Accept: 'application/json' },
    });
    candidates.value = response.ok ? ((await response.json()).data ?? []) : [];
    searched.value = true;
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr/tasks">← Staff tasks</a>
        <h1 class="mt-2 text-xl font-semibold">Assign a task</h1>
        <form class="mt-6 space-y-4" @submit.prevent="form.post('/app/hr/tasks')">
            <div>
                <label class="field-label" for="assign-search">Find a person</label>
                <div class="flex gap-2">
                    <input
                        id="assign-search"
                        v-model="search"
                        class="input mt-1"
                        @keydown.enter.prevent="findPeople"
                    />
                    <button type="button" class="btn btn-secondary" @click="findPeople">
                        Search
                    </button>
                </div>
                <p v-if="searched && candidates.length === 0" class="field-hint" aria-live="polite">
                    Nobody you can assign to matches.
                </p>
                <label class="field-label mt-2" for="assign-to">Assign to</label>
                <select
                    id="assign-to"
                    v-model="form.employment_record_id"
                    required
                    class="input mt-1"
                >
                    <option value="" disabled>Choose a person</option>
                    <option
                        v-for="c in candidates"
                        :key="c.employmentRecordId"
                        :value="c.employmentRecordId"
                    >
                        {{ c.label }}
                    </option>
                </select>
                <p v-if="form.errors.employment_record_id" class="field-error" role="alert">
                    {{ form.errors.employment_record_id }}
                </p>
            </div>
            <div>
                <label class="field-label" for="assign-title">Title</label>
                <input
                    id="assign-title"
                    v-model="form.title"
                    required
                    maxlength="120"
                    class="input mt-1"
                />
                <p v-if="form.errors.title" class="field-error" role="alert">
                    {{ form.errors.title }}
                </p>
            </div>
            <div>
                <label class="field-label" for="assign-description">Description (optional)</label>
                <textarea
                    id="assign-description"
                    v-model="form.description"
                    maxlength="1000"
                    rows="3"
                    class="input mt-1"
                ></textarea>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="assign-category">Category</label>
                    <select
                        id="assign-category"
                        v-model="form.category_id"
                        required
                        class="input mt-1"
                    >
                        <option value="" disabled>Choose a category</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">
                            {{ c.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="assign-mode">Completion</label>
                    <select id="assign-mode" v-model="form.completion_mode" class="input mt-1">
                        <option value="reviewed">Reviewed after submission</option>
                        <option value="self">Done on submission</option>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="assign-available">Available from</label>
                    <input
                        id="assign-available"
                        v-model="form.available_on"
                        type="date"
                        required
                        :min="today"
                        class="input mt-1"
                    />
                </div>
                <div>
                    <label class="field-label" for="assign-due">Due</label>
                    <input
                        id="assign-due"
                        v-model="form.due_on"
                        type="date"
                        required
                        :min="form.available_on"
                        class="input mt-1"
                    />
                    <p v-if="form.errors.due_on" class="field-error" role="alert">
                        {{ form.errors.due_on }}
                    </p>
                </div>
                <div>
                    <label class="field-label" for="assign-section">Section (optional)</label>
                    <select id="assign-section" v-model="form.section_id" class="input mt-1">
                        <option value="">None</option>
                        <option v-for="s in academic.sections" :key="s.id" :value="s.id">
                            {{ s.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="assign-subject"
                        >Subject offering (optional)</label
                    >
                    <select
                        id="assign-subject"
                        v-model="form.subject_offering_id"
                        class="input mt-1"
                    >
                        <option value="">None</option>
                        <option v-for="s in academic.subjectOfferings" :key="s.id" :value="s.id">
                            {{ s.label }}
                        </option>
                    </select>
                </div>
            </div>
            <p class="field-hint">
                An academic reference only labels the task; it grants no access to the class.
            </p>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                Assign task
            </button>
        </form>
    </main>
</template>
