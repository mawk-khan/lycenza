<script setup lang="ts">
import { formatCalendarDate } from '../../../../datetime';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface CopyRow {
    id: string;
    code: string;
    status: 'active' | 'inactive';
    available: boolean;
    campusId: string | null;
    campusName: string | null;
    activeLoan: { id: string; studentName: string; dueAt: string } | null;
}

interface Props {
    title: {
        id: string;
        title: string;
        author: string | null;
        isbn: string | null;
        status: 'active' | 'inactive';
    };
    copies: CopyRow[];
    canManage: boolean;
    campuses: { id: string; name: string }[];
}

const props = defineProps<Props>();

const showAddCopy = ref(false);
const form = useForm({ code: '', campus_id: '' });
const moveError = ref<string | null>(null);

// ORG.7D-5: a Copy's Campus (none = School-shared); refused while on loan.
function moveCopy(copy: CopyRow, campusId: string): void {
    moveError.value = null;
    router.patch(
        `/app/library/copies/${copy.id}`,
        { campus_id: campusId === '' ? null : campusId },
        {
            preserveScroll: true,
            onError: (errors) => {
                moveError.value = errors.campus_id ?? null;
            },
        },
    );
}

function submitCopy(): void {
    form.post(`/app/library/titles/${props.title.id}/copies`, {
        onSuccess: () => {
            form.reset();
            showAddCopy.value = false;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/library/titles">← Library catalogue</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ title.title }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ title.author ?? 'Author unknown' }}</p>
                <p v-if="title.isbn" class="mt-1 text-xs text-ink-muted">ISBN {{ title.isbn }}</p>
            </div>
            <StatusBadge :status="title.status" />
        </div>

        <div class="mt-8 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-ink">Copies</h2>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary"
                @click="showAddCopy = !showAddCopy"
            >
                {{ showAddCopy ? 'Cancel' : 'Add copy' }}
            </button>
        </div>

        <form v-if="showAddCopy" class="mt-3 flex items-end gap-3" @submit.prevent="submitCopy">
            <div>
                <label class="block text-sm text-ink-muted" for="copy-code">Accession code</label>
                <input id="copy-code" v-model="form.code" type="text" class="input mt-1 w-48" />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="copy-campus">Campus</label>
                <select id="copy-campus" v-model="form.campus_id" class="input mt-1 w-48">
                    <option value="">School-wide (no Campus)</option>
                    <option v-for="campus in campuses" :key="campus.id" :value="campus.id">
                        {{ campus.name }}
                    </option>
                </select>
                <p v-if="form.errors.campus_id" class="mt-1 text-sm text-danger">
                    {{ form.errors.campus_id }}
                </p>
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary">
                {{ form.processing ? 'Saving…' : 'Register copy' }}
            </button>
        </form>

        <p v-if="moveError" class="mt-3 text-sm text-danger">{{ moveError }}</p>

        <p v-if="copies.length === 0" class="mt-4 text-sm text-ink-muted">
            No copies registered yet.
        </p>

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-ink-muted">
                    <th scope="col" class="py-2 font-medium">Code</th>
                    <th scope="col" class="py-2 font-medium">Campus</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 font-medium">Availability</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="copy in copies" :key="copy.id">
                    <td class="py-3 font-medium">{{ copy.code }}</td>
                    <td class="py-3 text-ink-muted">
                        <select
                            v-if="canManage && copy.activeLoan === null"
                            :aria-label="`Campus of copy ${copy.code}`"
                            :value="copy.campusId ?? ''"
                            class="input w-auto"
                            @change="moveCopy(copy, ($event.target as HTMLSelectElement).value)"
                        >
                            <option value="">School-wide</option>
                            <option v-for="campus in campuses" :key="campus.id" :value="campus.id">
                                {{ campus.name }}
                            </option>
                        </select>
                        <span v-else>{{ copy.campusName ?? 'School-wide' }}</span>
                    </td>
                    <td class="py-3"><StatusBadge :status="copy.status" /></td>
                    <td class="py-3 text-ink-muted">
                        <span v-if="copy.available" class="text-ink">Available</span>
                        <span v-else-if="copy.activeLoan">
                            On loan to {{ copy.activeLoan.studentName }} (due
                            {{ formatCalendarDate(copy.activeLoan.dueAt) }})
                        </span>
                        <span v-else>Unavailable</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
