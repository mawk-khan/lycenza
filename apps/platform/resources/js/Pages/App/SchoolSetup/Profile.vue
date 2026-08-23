<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface School {
    id: string;
    name: string;
    legalName: string | null;
    code: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    addressLine1: string | null;
    city: string | null;
    stateRegion: string | null;
    postalCode: string | null;
    countryCode: string;
    educationBoardId: string | null;
}

interface Props {
    school: School;
    educationBoards: Array<{ id: string; name: string }>;
    canManage: boolean;
}

const props = defineProps<Props>();

const form = useForm({
    legal_name: props.school.legalName ?? '',
    email: props.school.email ?? '',
    phone: props.school.phone ?? '',
    website: props.school.website ?? '',
    address_line1: props.school.addressLine1 ?? '',
    city: props.school.city ?? '',
    state_region: props.school.stateRegion ?? '',
    postal_code: props.school.postalCode ?? '',
    education_board_id: props.school.educationBoardId ?? '',
});

function submit() {
    form.put('/app/school-setup/profile');
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">School Profile</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ school.name }}<span v-if="school.code"> · {{ school.code }}</span>
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="legal_name">Legal name</label>
                <input
                    id="legal_name"
                    v-model="form.legal_name"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="phone">Phone</label>
                <input
                    id="phone"
                    v-model="form.phone"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="website">Website</label>
                <input
                    id="website"
                    v-model="form.website"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="address_line1">Address</label>
                <input
                    id="address_line1"
                    v-model="form.address_line1"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-slate-600" for="city">City</label>
                    <input
                        id="city"
                        v-model="form.city"
                        :disabled="!canManage"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                    />
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="state_region">State</label>
                    <input
                        id="state_region"
                        v-model="form.state_region"
                        :disabled="!canManage"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                    />
                </div>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="postal_code">Postal code</label>
                <input
                    id="postal_code"
                    v-model="form.postal_code"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="education_board_id"
                    >Education Board</label
                >
                <select
                    id="education_board_id"
                    v-model="form.education_board_id"
                    :disabled="!canManage"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 disabled:bg-slate-100"
                >
                    <option value="">— None selected —</option>
                    <option v-for="board in educationBoards" :key="board.id" :value="board.id">
                        {{ board.name }}
                    </option>
                </select>
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
                You have read-only access to the School profile.
            </p>
        </form>
    </main>
</template>
