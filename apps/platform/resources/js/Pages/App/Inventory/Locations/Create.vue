<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface CampusOption {
    id: string;
    name: string;
}

interface Props {
    campuses: CampusOption[];
}

defineProps<Props>();

const form = useForm({
    code: '',
    name: '',
    campus_id: '',
});

function submit(): void {
    form.post('/app/inventory-locations');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/inventory-locations">← Inventory locations</a>
        <h1 class="mt-2 text-xl font-semibold">Add Location</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="code">Code</label>
                <input id="code" v-model="form.code" type="text" class="input mt-1" />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="name">Name</label>
                <input id="name" v-model="form.name" type="text" class="input mt-1" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-danger">
                    {{ form.errors.name }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="campus">Campus (optional)</label>
                <select id="campus" v-model="form.campus_id" class="input mt-1">
                    <option value="">School-wide (no Campus)</option>
                    <option v-for="campus in campuses" :key="campus.id" :value="campus.id">
                        {{ campus.name }}
                    </option>
                </select>
                <p v-if="form.errors.campus_id" class="mt-1 text-sm text-danger">
                    {{ form.errors.campus_id }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Add Location' }}
                </button>
                <a class="text-sm underline" href="/app/inventory-locations">Cancel</a>
            </div>
        </form>
    </main>
</template>
