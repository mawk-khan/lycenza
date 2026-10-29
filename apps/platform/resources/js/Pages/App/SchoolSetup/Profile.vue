<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import SelectField from '../../../Components/Form/SelectField.vue';
import TextField from '../../../Components/Form/TextField.vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';

/**
 * ORG.12C (ADR 0080 §8): the School profile in sections. Ordinary details
 * (contact, address, education board) save with the profile capability; the
 * institutional identity (legal name, registration and affiliation
 * references) is a separate form that needs a reason and a fresh
 * authentication code. Contact details belong to the School, never to a
 * person's account. No tax or statutory identifiers are kept here.
 */
interface School {
    name: string;
    legalName: string | null;
    code: string | null;
    status: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    addressLine1: string | null;
    addressLine2: string | null;
    city: string | null;
    stateRegion: string | null;
    postalCode: string | null;
    countryCode: string;
    educationBoardId: string | null;
    registrationReference: string | null;
    affiliationReference: string | null;
    timezone: string;
    currency: string;
}

const props = defineProps<{
    school: School;
    educationBoards: Array<{ id: string; name: string }>;
    identityReasons: Array<{ code: string; label: string }>;
    canManage: boolean;
    hasMfaFactor: boolean;
}>();

const form = useForm({
    email: props.school.email ?? '',
    phone: props.school.phone ?? '',
    website: props.school.website ?? '',
    address_line1: props.school.addressLine1 ?? '',
    address_line2: props.school.addressLine2 ?? '',
    city: props.school.city ?? '',
    state_region: props.school.stateRegion ?? '',
    postal_code: props.school.postalCode ?? '',
    country_code: props.school.countryCode ?? '',
    education_board_id: props.school.educationBoardId ?? '',
});

const identity = useForm({
    legal_name: props.school.legalName ?? '',
    registration_reference: props.school.registrationReference ?? '',
    affiliation_reference: props.school.affiliationReference ?? '',
    // The values this form was opened with: a newer change is never overwritten.
    expected_legal_name: props.school.legalName ?? '',
    expected_registration_reference: props.school.registrationReference ?? '',
    expected_affiliation_reference: props.school.affiliationReference ?? '',
    reason_code: '',
    mfa_code: '',
});

const boardName = (id: string | null) => props.educationBoards.find((b) => b.id === id)?.name;

function save() {
    form.put('/app/school-setup/profile', { preserveScroll: true });
}

function changeIdentity() {
    identity.put('/app/school-setup/profile/identity', {
        preserveScroll: true,
        onFinish: () => identity.reset('mfa_code'),
    });
}

const show = (value: string | null | undefined) => (value && value !== '' ? value : '—');
</script>

<template>
    <main class="mx-auto max-w-3xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">School profile</h1>
        <p class="mt-1 text-sm text-slate-600">
            The School's identity and institutional contact details. These describe the School, not
            any person's account.
        </p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <section aria-labelledby="identity-heading" class="rounded border border-slate-200 p-4">
                <h2 id="identity-heading" class="text-sm font-semibold">Identity</h2>
                <dl class="mt-2 space-y-1 text-sm [overflow-wrap:anywhere]">
                    <div>
                        <dt class="inline text-slate-500">Display name:</dt>
                        <dd class="inline">{{ school.name }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Legal name:</dt>
                        <dd class="inline">{{ show(school.legalName) }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">School code:</dt>
                        <dd class="inline">{{ show(school.code) }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Status:</dt>
                        <dd class="inline capitalize">{{ school.status }}</dd>
                    </div>
                </dl>
            </section>
            <section aria-labelledby="contact-heading" class="rounded border border-slate-200 p-4">
                <h2 id="contact-heading" class="text-sm font-semibold">Contact</h2>
                <dl class="mt-2 space-y-1 text-sm [overflow-wrap:anywhere]">
                    <div>
                        <dt class="inline text-slate-500">Email:</dt>
                        <dd class="inline">{{ show(school.email) }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Phone:</dt>
                        <dd class="inline">{{ show(school.phone) }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Website:</dt>
                        <dd class="inline">{{ show(school.website) }}</dd>
                    </div>
                </dl>
            </section>
            <section aria-labelledby="address-heading" class="rounded border border-slate-200 p-4">
                <h2 id="address-heading" class="text-sm font-semibold">Address</h2>
                <address class="mt-2 text-sm not-italic">
                    <span v-if="school.addressLine1" class="block">{{ school.addressLine1 }}</span>
                    <span v-if="school.addressLine2" class="block">{{ school.addressLine2 }}</span>
                    <span class="block">{{
                        [school.city, school.stateRegion, school.postalCode]
                            .filter(Boolean)
                            .join(', ') || '—'
                    }}</span>
                    <span class="block">{{ school.countryCode }}</span>
                </address>
            </section>
            <section
                aria-labelledby="institution-heading"
                class="rounded border border-slate-200 p-4"
            >
                <h2 id="institution-heading" class="text-sm font-semibold">
                    Institutional details
                </h2>
                <dl class="mt-2 space-y-1 text-sm [overflow-wrap:anywhere]">
                    <div>
                        <dt class="inline text-slate-500">Education board:</dt>
                        <dd class="inline">{{ show(boardName(school.educationBoardId)) }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Affiliation reference:</dt>
                        <dd class="inline">{{ show(school.affiliationReference) }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Registration reference:</dt>
                        <dd class="inline">{{ show(school.registrationReference) }}</dd>
                    </div>
                </dl>
            </section>
            <section
                aria-labelledby="defaults-heading"
                class="rounded border border-slate-200 p-4 sm:col-span-2"
            >
                <h2 id="defaults-heading" class="text-sm font-semibold">Operating defaults</h2>
                <dl class="mt-2 space-y-1 text-sm [overflow-wrap:anywhere]">
                    <div>
                        <dt class="inline text-slate-500">Time zone:</dt>
                        <dd class="inline">
                            {{ school.timezone }}
                            <span class="text-slate-500"
                                >(changed in School settings; every Campus uses it)</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Currency:</dt>
                        <dd class="inline">
                            {{ school.currency }}
                            <span class="text-slate-500">(set for every School by Finance)</span>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <template v-if="canManage">
            <section aria-labelledby="edit-heading" class="mt-8">
                <h2 id="edit-heading" class="text-base font-semibold">Edit contact and address</h2>
                <ErrorSummary :errors="form.errors" :fields="Object.keys(form.errors)" />
                <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                    <TextField
                        id="email"
                        v-model="form.email"
                        type="email"
                        label="Email"
                        :error="form.errors.email"
                    />
                    <TextField
                        id="phone"
                        v-model="form.phone"
                        type="tel"
                        label="Phone"
                        hint="International format, e.g. +14155550123"
                        :error="form.errors.phone"
                    />
                    <TextField
                        id="website"
                        v-model="form.website"
                        class="sm:col-span-2"
                        label="Website"
                        hint="Starts with https:// or http://"
                        :error="form.errors.website"
                    />
                    <TextField
                        id="address_line1"
                        v-model="form.address_line1"
                        class="sm:col-span-2"
                        label="Address line 1"
                        :error="form.errors.address_line1"
                    />
                    <TextField
                        id="address_line2"
                        v-model="form.address_line2"
                        class="sm:col-span-2"
                        label="Address line 2"
                        :error="form.errors.address_line2"
                    />
                    <TextField
                        id="city"
                        v-model="form.city"
                        label="City or locality"
                        :error="form.errors.city"
                    />
                    <TextField
                        id="state_region"
                        v-model="form.state_region"
                        label="State, province or region"
                        :error="form.errors.state_region"
                    />
                    <TextField
                        id="postal_code"
                        v-model="form.postal_code"
                        label="Postal code"
                        :error="form.errors.postal_code"
                    />
                    <TextField
                        id="country_code"
                        v-model="form.country_code"
                        label="Country code"
                        hint="Two letters, e.g. IN, GB, AE"
                        :maxlength="2"
                        required
                        :error="form.errors.country_code"
                    />
                    <SelectField
                        id="education_board_id"
                        v-model="form.education_board_id"
                        class="sm:col-span-2"
                        label="Education board"
                        placeholder="— None selected —"
                        :options="educationBoards.map((b) => ({ value: b.id, label: b.name }))"
                        :error="form.errors.education_board_id"
                    />
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                        >
                            Save contact and address
                        </button>
                    </div>
                </form>
            </section>

            <section
                aria-labelledby="identity-edit-heading"
                class="mt-10 rounded border border-amber-200 bg-amber-50/40 p-4"
            >
                <h2 id="identity-edit-heading" class="text-base font-semibold">
                    Change institutional identity
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    The legal name and the registration and affiliation references identify the
                    School as an institution. Changing them needs a reason and a fresh
                    authentication code, and is recorded. These are not tax identifiers.
                </p>
                <ErrorSummary
                    :errors="identity.errors"
                    :fields="['identity', 'reason_code', 'mfa_code']"
                />
                <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="changeIdentity">
                    <TextField
                        id="legal_name"
                        v-model="identity.legal_name"
                        class="sm:col-span-2"
                        label="Legal name"
                        hint="May be the same as the display name"
                        :error="identity.errors.legal_name"
                    />
                    <TextField
                        id="registration_reference"
                        v-model="identity.registration_reference"
                        label="Registration reference"
                        :maxlength="100"
                        :error="identity.errors.registration_reference"
                    />
                    <TextField
                        id="affiliation_reference"
                        v-model="identity.affiliation_reference"
                        label="Affiliation reference"
                        :maxlength="100"
                        :error="identity.errors.affiliation_reference"
                    />
                    <SelectField
                        id="reason_code"
                        v-model="identity.reason_code"
                        class="sm:col-span-2"
                        label="Reason for the change"
                        placeholder="— Choose a reason —"
                        required
                        :options="identityReasons.map((r) => ({ value: r.code, label: r.label }))"
                        :error="identity.errors.reason_code"
                    />
                    <div class="sm:col-span-2">
                        <MfaCodeField
                            id="identity_mfa_code"
                            v-model="identity.mfa_code"
                            :has-mfa-factor="hasMfaFactor"
                            action="change the institutional identity"
                            :error="identity.errors.mfa_code"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="identity.processing || !hasMfaFactor"
                            class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                        >
                            Change institutional identity
                        </button>
                    </div>
                </form>
            </section>
        </template>
        <p v-else class="mt-8 text-sm text-slate-600">
            You have read-only access to the School profile.
        </p>
    </main>
</template>
