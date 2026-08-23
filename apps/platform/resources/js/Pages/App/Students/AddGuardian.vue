<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { RELATIONSHIP_TYPES } from '../../../relationshipTypes';

interface Props {
    student: { id: string; firstName: string; lastName: string | null };
}

const props = defineProps<Props>();

interface GuardianCandidate {
    id: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
    status: string;
}

function candidateName(g: GuardianCandidate): string {
    return [g.firstName, g.middleName, g.lastName].filter(Boolean).join(' ');
}

const tab = ref<'existing' | 'new'>('existing');
const selectedGuardian = ref<GuardianCandidate | null>(null);

// --- Find existing: name search --------------------------------------

const nameQuery = ref('');
const nameResults = ref<GuardianCandidate[]>([]);
const nameSearching = ref(false);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

watch(nameQuery, (value) => {
    clearTimeout(debounceTimer);
    if (value.trim().length < 2) {
        nameResults.value = [];
        return;
    }
    // Modest debounce for the incremental-typing search (this
    // checkpoint's brief, section 37).
    debounceTimer = setTimeout(async () => {
        nameSearching.value = true;
        try {
            const response = await fetch(
                `/app/students/${props.student.id}/guardians/search?q=${encodeURIComponent(value)}`,
                { headers: { Accept: 'application/json' } },
            );
            const body = await response.json();
            nameResults.value = body.data;
        } finally {
            nameSearching.value = false;
        }
    }, 300);
});

// --- Find existing: exact contact lookup ------------------------------

const contactType = ref<'email' | 'mobile'>('email');
const contactValue = ref('');
const contactSearching = ref(false);
const contactSearched = ref(false);
const contactError = ref('');
const contactCandidates = ref<GuardianCandidate[]>([]);

async function searchByContact(): Promise<void> {
    contactSearching.value = true;
    contactError.value = '';
    try {
        const response = await fetch(
            `/app/students/${props.student.id}/guardians/candidates?` +
                new URLSearchParams({
                    type: contactType.value,
                    value: contactValue.value,
                }).toString(),
            { headers: { Accept: 'application/json' } },
        );
        if (!response.ok) {
            if (response.status === 403) {
                contactError.value = "You don't have permission to search Guardian contacts.";
            } else {
                const body = await response.json();
                contactError.value = body.errors?.value?.[0] ?? 'That contact value is not valid.';
            }
            contactCandidates.value = [];
            return;
        }
        const body = await response.json();
        contactCandidates.value = body.data;
        contactSearched.value = true;
    } finally {
        contactSearching.value = false;
    }
}

function selectGuardian(guardian: GuardianCandidate): void {
    selectedGuardian.value = guardian;
}

// --- Relationship + submit ---------------------------------------------

const relationshipForm = useForm({
    guardian_id: '',
    relationship_type: '',
    is_legal_guardian: false,
    is_emergency_contact: false,
    is_authorized_pickup: false,
});

function submitLinkExisting(): void {
    if (!selectedGuardian.value) return;
    relationshipForm.guardian_id = selectedGuardian.value.id;
    relationshipForm.post(`/app/students/${props.student.id}/guardians/link`);
}

const newGuardianForm = useForm({
    first_name: '',
    middle_name: '',
    last_name: '',
    relationship_type: '',
    is_legal_guardian: false,
    is_emergency_contact: false,
    is_authorized_pickup: false,
});

function submitNewGuardian(): void {
    newGuardianForm.post(`/app/students/${props.student.id}/guardians`);
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">
            Add guardian to {{ [student.firstName, student.lastName].filter(Boolean).join(' ') }}
        </h1>

        <div class="mt-6 flex gap-1 border-b border-slate-200" role="tablist">
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'existing'"
                class="border-b-2 px-4 py-2 text-sm font-medium"
                :class="
                    tab === 'existing'
                        ? 'border-slate-900 text-slate-900'
                        : 'border-transparent text-slate-500'
                "
                @click="tab = 'existing'"
            >
                Find existing guardian
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'new'"
                class="border-b-2 px-4 py-2 text-sm font-medium"
                :class="
                    tab === 'new'
                        ? 'border-slate-900 text-slate-900'
                        : 'border-transparent text-slate-500'
                "
                @click="tab = 'new'"
            >
                Create new guardian
            </button>
        </div>

        <!-- Find existing -->
        <section v-if="tab === 'existing'" class="mt-6 space-y-6">
            <div>
                <label class="block text-sm font-medium text-slate-700" for="guardian-name-search">
                    Search by name
                </label>
                <input
                    id="guardian-name-search"
                    v-model="nameQuery"
                    type="text"
                    placeholder="Start typing a Guardian's name…"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <ul
                    v-if="nameResults.length"
                    class="mt-2 divide-y divide-slate-100 rounded border border-slate-200"
                >
                    <li
                        v-for="g in nameResults"
                        :key="g.id"
                        class="flex items-center justify-between px-3 py-2 text-sm"
                    >
                        <span>{{ candidateName(g) }}</span>
                        <button type="button" class="underline" @click="selectGuardian(g)">
                            Select
                        </button>
                    </li>
                </ul>
                <p
                    v-else-if="nameQuery.trim().length >= 2 && !nameSearching"
                    class="mt-2 text-sm text-slate-500"
                >
                    No Guardians match "{{ nameQuery }}".
                </p>
            </div>

            <div class="border-t border-slate-100 pt-6">
                <p class="text-sm font-medium text-slate-700">Or find by exact contact</p>
                <p class="mt-1 text-sm text-slate-500">
                    Search an exact email or mobile number already on file for this School.
                </p>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="searchByContact">
                    <div>
                        <label class="block text-sm text-slate-600" for="contact-type">Type</label>
                        <select
                            id="contact-type"
                            v-model="contactType"
                            class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="email">Email</option>
                            <option value="mobile">Mobile</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm text-slate-600" for="contact-value">
                            {{ contactType === 'email' ? 'Email address' : 'Mobile number' }}
                        </label>
                        <input
                            id="contact-value"
                            v-model="contactValue"
                            type="text"
                            :placeholder="
                                contactType === 'email'
                                    ? 'parent@example.com'
                                    : 'Include country code, e.g. +91 9876543210'
                            "
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="contactSearching || !contactValue"
                        class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50 disabled:opacity-50"
                    >
                        Search
                    </button>
                </form>
                <p v-if="contactError" class="mt-2 text-sm text-red-600">{{ contactError }}</p>

                <div v-if="contactSearched && !contactError" class="mt-3">
                    <p v-if="contactCandidates.length === 0" class="text-sm text-slate-500">
                        No existing Guardian uses this contact information.
                    </p>
                    <div v-else>
                        <!-- Neutral wording: shared household contacts are
                             legitimate, this is not "duplicate detection". -->
                        <p class="text-sm text-amber-700">
                            Existing guardians use this contact information.
                        </p>
                        <ul class="mt-2 divide-y divide-slate-100 rounded border border-slate-200">
                            <li
                                v-for="g in contactCandidates"
                                :key="g.id"
                                class="flex items-center justify-between px-3 py-2 text-sm"
                            >
                                <span>{{ candidateName(g) }}</span>
                                <button type="button" class="underline" @click="selectGuardian(g)">
                                    Use this guardian
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Relationship form once a Guardian is selected -->
            <form
                v-if="selectedGuardian"
                class="rounded border border-slate-200 p-4"
                @submit.prevent="submitLinkExisting"
            >
                <p class="text-sm">
                    Linking <strong>{{ candidateName(selectedGuardian) }}</strong> ·
                    <button type="button" class="underline" @click="selectedGuardian = null">
                        Change
                    </button>
                </p>

                <div class="mt-4">
                    <label
                        class="block text-sm font-medium text-slate-700"
                        for="existing-relationship-type"
                        >Relationship</label
                    >
                    <select
                        id="existing-relationship-type"
                        v-model="relationshipForm.relationship_type"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 sm:w-64"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="t in RELATIONSHIP_TYPES" :key="t.value" :value="t.value">
                            {{ t.label }}
                        </option>
                    </select>
                    <p class="mt-1 text-sm text-red-600">
                        {{ relationshipForm.errors.relationship_type }}
                    </p>
                </div>

                <fieldset class="mt-4 space-y-2 text-sm">
                    <legend class="text-sm font-medium text-slate-700">Authority</legend>
                    <label class="flex items-center gap-2">
                        <input v-model="relationshipForm.is_legal_guardian" type="checkbox" /> Legal
                        guardian
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="relationshipForm.is_emergency_contact" type="checkbox" />
                        Emergency contact
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="relationshipForm.is_authorized_pickup" type="checkbox" />
                        Authorized pickup
                    </label>
                </fieldset>

                <button
                    type="submit"
                    :disabled="relationshipForm.processing"
                    class="mt-4 rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    Link guardian
                </button>
            </form>
        </section>

        <!-- Create new -->
        <section v-else class="mt-6">
            <form class="space-y-4" @submit.prevent="submitNewGuardian">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="new-first-name"
                            >First name</label
                        >
                        <input
                            id="new-first-name"
                            v-model="newGuardianForm.first_name"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-slate-700"
                            for="new-middle-name"
                            >Middle name</label
                        >
                        <input
                            id="new-middle-name"
                            v-model="newGuardianForm.middle_name"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="new-last-name"
                            >Last name</label
                        >
                        <input
                            id="new-last-name"
                            v-model="newGuardianForm.last_name"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                        />
                    </div>
                </div>
                <p class="text-sm text-red-600">{{ newGuardianForm.errors.first_name }}</p>

                <div>
                    <label
                        class="block text-sm font-medium text-slate-700"
                        for="new-relationship-type"
                        >Relationship</label
                    >
                    <select
                        id="new-relationship-type"
                        v-model="newGuardianForm.relationship_type"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 sm:w-64"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="t in RELATIONSHIP_TYPES" :key="t.value" :value="t.value">
                            {{ t.label }}
                        </option>
                    </select>
                    <p class="mt-1 text-sm text-red-600">
                        {{ newGuardianForm.errors.relationship_type }}
                    </p>
                </div>

                <fieldset class="space-y-2 text-sm">
                    <legend class="text-sm font-medium text-slate-700">Authority</legend>
                    <label class="flex items-center gap-2">
                        <input v-model="newGuardianForm.is_legal_guardian" type="checkbox" /> Legal
                        guardian
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="newGuardianForm.is_emergency_contact" type="checkbox" />
                        Emergency contact
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="newGuardianForm.is_authorized_pickup" type="checkbox" />
                        Authorized pickup
                    </label>
                </fieldset>

                <p class="text-sm text-slate-500">
                    Contact information can be added from the Guardian's page after creation.
                </p>

                <button
                    type="submit"
                    :disabled="newGuardianForm.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    Create and link guardian
                </button>
            </form>
        </section>
    </main>
</template>
