<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import RelationshipFlags from '../../../Components/RelationshipFlags.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

interface Contact {
    id: string;
    type: 'email' | 'mobile';
    value: string;
    label: string | null;
    isPrimary: boolean;
    isActive: boolean;
    verifiedAt: string | null;
}

interface LinkedStudent {
    relationshipId: string;
    student: { id: string; studentNumber: string; firstName: string; lastName: string | null };
    relationshipType: string;
    isPrimary: boolean;
    isLegalGuardian: boolean;
    isEmergencyContact: boolean;
    isAuthorizedPickup: boolean;
}

interface AccountLink {
    schoolMembershipId: string;
    memberName: string;
    membershipActive: boolean;
}

interface MembershipCandidate {
    schoolMembershipId: string;
    name: string;
}

interface EmailPreferenceState {
    preferenceEnabled: boolean | null;
    consentStatus: 'granted' | 'withdrawn' | null;
    endpointAvailable: boolean;
}

interface AccountInvitationState {
    canManage: boolean;
    hasEmailContact: boolean;
    pending: { status: string; expiresAt: string } | null;
}

interface Props {
    guardian: {
        id: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        status: 'active' | 'inactive';
    };
    contacts: Contact[];
    students: LinkedStudent[];
    canManage: boolean;
    accountLink: AccountLink | null;
    accountInvitation: AccountInvitationState;
    communicationPreferences: { email: EmailPreferenceState };
    canManageCommunicationPreferences: boolean;
}

const props = defineProps<Props>();

// --- Phase 5B.2: School OS account link ---------------------------------

const showLinkPicker = ref(false);
const linkQuery = ref('');
const linkCandidates = ref<MembershipCandidate[]>([]);
const linkSearching = ref(false);
const linking = ref(false);
const unlinking = ref(false);
let linkSearchDebounce: ReturnType<typeof setTimeout> | undefined;

function searchLinkCandidates(): void {
    const q = linkQuery.value.trim();
    clearTimeout(linkSearchDebounce);
    linkSearchDebounce = setTimeout(async () => {
        linkSearching.value = true;
        try {
            const response = await fetch(
                `/app/guardians/${props.guardian.id}/account-link/search?q=${encodeURIComponent(q)}`,
                { headers: { Accept: 'application/json' } },
            );
            const body = await response.json();
            linkCandidates.value = body.candidates as MembershipCandidate[];
        } finally {
            linkSearching.value = false;
        }
    }, 250);
}

function linkAccount(candidate: MembershipCandidate): void {
    linking.value = true;
    router.post(
        `/app/guardians/${props.guardian.id}/account-link`,
        { school_membership_id: candidate.schoolMembershipId },
        {
            preserveScroll: true,
            onFinish: () => {
                linking.value = false;
                showLinkPicker.value = false;
                linkQuery.value = '';
                linkCandidates.value = [];
            },
        },
    );
}

function unlinkAccount(): void {
    const confirmed = window.confirm(
        'Unlink this School OS account? The Guardian record and any past communications are kept -- only future in-app reachability changes.',
    );
    if (!confirmed) return;
    unlinking.value = true;
    router.delete(`/app/guardians/${props.guardian.id}/account-link`, {
        preserveScroll: true,
        onFinish: () => (unlinking.value = false),
    });
}

// --- Phase 5D.3: account invitation ---------------------------------

const invitationBusy = ref(false);

function sendInvitation(): void {
    invitationBusy.value = true;
    router.post(
        `/app/guardians/${props.guardian.id}/account-invitation`,
        {},
        { preserveScroll: true, onFinish: () => (invitationBusy.value = false) },
    );
}

function resendInvitation(): void {
    invitationBusy.value = true;
    router.post(
        `/app/guardians/${props.guardian.id}/account-invitation/resend`,
        {},
        { preserveScroll: true, onFinish: () => (invitationBusy.value = false) },
    );
}

function revokeInvitation(): void {
    const confirmed = window.confirm('Revoke this pending invitation? The link will stop working.');
    if (!confirmed) return;
    invitationBusy.value = true;
    router.delete(`/app/guardians/${props.guardian.id}/account-invitation`, {
        preserveScroll: true,
        onFinish: () => (invitationBusy.value = false),
    });
}

function fullName(): string {
    return [props.guardian.firstName, props.guardian.middleName, props.guardian.lastName]
        .filter(Boolean)
        .join(' ');
}

function studentName(s: LinkedStudent['student']): string {
    return [s.firstName, s.lastName].filter(Boolean).join(' ');
}

function toggleStatus(): void {
    const next = props.guardian.status === 'active' ? 'inactive' : 'active';
    router.post(`/app/guardians/${props.guardian.id}/status`, { status: next });
}

function setPrimaryContact(contactId: string): void {
    router.post(`/app/contacts/${contactId}/primary`, {}, { preserveScroll: true });
}

function deactivateContact(contact: Contact): void {
    const confirmed = window.confirm(
        `Deactivate this ${contact.type === 'email' ? 'email' : 'mobile'} contact? It will no longer be usable as the primary contact, but the record is kept, not deleted.`,
    );
    if (!confirmed) return;
    router.post(`/app/contacts/${contact.id}/deactivate`, {}, { preserveScroll: true });
}

// --- Phase 5D.2: domain communication preference/consent ----------------

const updatingPreference = ref(false);
const recordingConsent = ref(false);

function setEmailPreference(enabled: boolean): void {
    updatingPreference.value = true;
    router.put(
        `/app/guardians/${props.guardian.id}/communication-preference`,
        { channel: 'email', enabled },
        { preserveScroll: true, onFinish: () => (updatingPreference.value = false) },
    );
}

function recordEmailConsent(status: 'granted' | 'withdrawn'): void {
    recordingConsent.value = true;
    router.post(
        `/app/guardians/${props.guardian.id}/communication-consent`,
        { channel: 'email', status },
        { preserveScroll: true, onFinish: () => (recordingConsent.value = false) },
    );
}

// --- Add contact --------------------------------------------------------

const showAddContact = ref(false);
const contactForm = useForm({
    type: 'email' as 'email' | 'mobile',
    value: '',
    label: '',
    is_primary: false,
});

function submitContact(): void {
    contactForm.post(`/app/guardians/${props.guardian.id}/contacts`, {
        preserveScroll: true,
        onSuccess: () => {
            contactForm.reset();
            showAddContact.value = false;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/guardians">← Guardians</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ fullName() }}</h1>
                <div class="mt-1"><StatusBadge :status="guardian.status" /></div>
            </div>
            <div v-if="canManage" class="flex items-center gap-2">
                <a
                    :href="`/app/guardians/${guardian.id}/edit`"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                >
                    Edit
                </a>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                    @click="toggleStatus"
                >
                    {{ guardian.status === 'active' ? 'Mark inactive' : 'Mark active' }}
                </button>
            </div>
        </div>

        <!-- Contacts -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Contact information</h2>
                <button
                    v-if="canManage && !showAddContact"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showAddContact = true"
                >
                    Add contact
                </button>
            </div>

            <p v-if="contacts.length === 0 && !showAddContact" class="mt-3 text-sm text-slate-500">
                No contact information on file yet.
            </p>

            <ul v-if="contacts.length" class="mt-3 space-y-2">
                <li
                    v-for="contact in contacts"
                    :key="contact.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p>
                            <span class="font-medium">{{ contact.value }}</span>
                            <span class="ml-2 text-slate-400">{{
                                contact.type === 'email' ? 'Email' : 'Mobile'
                            }}</span>
                            <span v-if="contact.label" class="ml-2 text-slate-400"
                                >· {{ contact.label }}</span
                            >
                        </p>
                        <p class="mt-0.5 text-slate-500">
                            <span v-if="contact.isPrimary">Primary</span>
                            <span v-if="contact.isPrimary && !contact.isActive"> · </span>
                            <span v-if="!contact.isActive">Inactive</span>
                            <span v-if="!contact.verifiedAt">
                                <span v-if="contact.isPrimary || !contact.isActive"> · </span>Not
                                verified
                            </span>
                        </p>
                    </div>
                    <div v-if="canManage" class="flex items-center gap-3">
                        <button
                            v-if="!contact.isPrimary && contact.isActive"
                            type="button"
                            class="underline"
                            @click="setPrimaryContact(contact.id)"
                        >
                            Make primary
                        </button>
                        <button
                            v-if="contact.isActive"
                            type="button"
                            class="text-red-600 underline"
                            @click="deactivateContact(contact)"
                        >
                            Deactivate
                        </button>
                    </div>
                </li>
            </ul>

            <form
                v-if="showAddContact"
                class="mt-4 rounded border border-slate-200 p-4"
                @submit.prevent="submitContact"
            >
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-sm text-slate-600" for="contact-type">Type</label>
                        <select
                            id="contact-type"
                            v-model="contactForm.type"
                            class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="email">Email</option>
                            <option value="mobile">Mobile</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm text-slate-600" for="contact-value">
                            {{ contactForm.type === 'email' ? 'Email address' : 'Mobile number' }}
                        </label>
                        <input
                            id="contact-value"
                            v-model="contactForm.value"
                            type="text"
                            :placeholder="
                                contactForm.type === 'email'
                                    ? 'parent@example.com'
                                    : 'Include country code, e.g. +91 9876543210'
                            "
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600" for="contact-label"
                            >Label (optional)</label
                        >
                        <input
                            id="contact-label"
                            v-model="contactForm.label"
                            type="text"
                            placeholder="Work, Home…"
                            class="mt-1 w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <p v-if="contactForm.type === 'mobile'" class="mt-2 text-xs text-slate-500">
                    Mobile numbers must include a country code (e.g. +91 9876543210). We never guess
                    a country for you.
                </p>
                <p class="mt-2 text-sm text-red-600">{{ contactForm.errors.value }}</p>
                <label class="mt-3 flex items-center gap-2 text-sm">
                    <input v-model="contactForm.is_primary" type="checkbox" /> Set as primary
                    {{ contactForm.type }}
                </label>
                <div class="mt-3 flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="contactForm.processing"
                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    >
                        Save contact
                    </button>
                    <button type="button" class="text-sm underline" @click="showAddContact = false">
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <!-- Linked students -->
        <section class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Students</h2>
            <p v-if="students.length === 0" class="mt-3 text-sm text-slate-500">
                Not linked to any Student yet.
            </p>
            <ul v-else class="mt-3 space-y-3">
                <li
                    v-for="s in students"
                    :key="s.relationshipId"
                    class="rounded border border-slate-200 p-4"
                >
                    <a class="font-medium underline" :href="`/app/students/${s.student.id}`">{{
                        studentName(s.student)
                    }}</a>
                    <p class="text-sm text-slate-500">{{ s.student.studentNumber }}</p>
                    <RelationshipFlags
                        :relationship-type="s.relationshipType"
                        :is-primary="s.isPrimary"
                        :is-legal-guardian="s.isLegalGuardian"
                        :is-emergency-contact="s.isEmergencyContact"
                        :is-authorized-pickup="s.isAuthorizedPickup"
                    />
                </li>
            </ul>
        </section>

        <!-- School OS account link -->
        <section class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">School OS account</h2>
            <p class="mt-1 text-xs text-slate-400">
                Optional. Linking an existing School OS account lets this Guardian receive in-app
                communications when they sign in. It does not create a new account and does not
                affect their email/mobile contact information used for email delivery.
            </p>

            <div v-if="accountLink" class="mt-3 rounded border border-slate-200 p-4 text-sm">
                <p>
                    Linked to <span class="font-medium">{{ accountLink.memberName }}</span>
                    <span v-if="!accountLink.membershipActive" class="ml-2 text-amber-600"
                        >(membership inactive -- in-app currently unavailable)</span
                    >
                </p>
                <button
                    v-if="canManage"
                    type="button"
                    class="mt-2 text-red-600 underline"
                    :disabled="unlinking"
                    @click="unlinkAccount"
                >
                    Unlink
                </button>
            </div>

            <div v-else-if="canManage">
                <button
                    v-if="!showLinkPicker"
                    type="button"
                    class="mt-3 text-sm font-medium underline"
                    @click="showLinkPicker = true"
                >
                    Link account
                </button>
                <div v-else class="relative mt-3">
                    <input
                        v-model="linkQuery"
                        type="text"
                        placeholder="Search School OS accounts by name…"
                        class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        @input="searchLinkCandidates"
                    />
                    <ul
                        v-if="linkCandidates.length > 0"
                        class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                    >
                        <li
                            v-for="candidate in linkCandidates"
                            :key="candidate.schoolMembershipId"
                            class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                            @click="linkAccount(candidate)"
                        >
                            {{ candidate.name }}
                        </li>
                    </ul>
                    <button
                        type="button"
                        class="mt-2 text-xs text-slate-400 underline"
                        @click="showLinkPicker = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>
            <p v-else class="mt-3 text-sm text-slate-500">Not linked.</p>
        </section>

        <!-- Guardian account invitation (Phase 5D.3) -->
        <section v-if="!accountLink" class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Invite a new School OS account</h2>
            <p class="mt-1 text-xs text-slate-400">
                Sends this Guardian a one-time link at their email contact to create their own
                School OS account. Different from linking above, which attaches an account that
                already exists.
            </p>

            <div v-if="!accountInvitation.canManage" class="mt-3 text-sm text-slate-500">
                You do not have permission to invite an account for this Guardian.
            </div>
            <div v-else-if="!accountInvitation.hasEmailContact" class="mt-3 text-sm text-slate-500">
                Add an active email contact before inviting an account.
            </div>
            <div
                v-else-if="accountInvitation.pending"
                class="mt-3 rounded border border-slate-200 p-4 text-sm"
            >
                <p>
                    Invitation
                    <span class="font-medium">{{ accountInvitation.pending.status }}</span>
                    <span v-if="accountInvitation.pending.status === 'pending'">
                        (expires
                        {{ new Date(accountInvitation.pending.expiresAt).toLocaleDateString() }})
                    </span>
                </p>
                <div class="mt-2 space-x-4">
                    <button
                        type="button"
                        class="text-sm font-medium underline"
                        :disabled="invitationBusy"
                        @click="resendInvitation"
                    >
                        Resend
                    </button>
                    <button
                        type="button"
                        class="text-sm text-red-600 underline"
                        :disabled="invitationBusy"
                        @click="revokeInvitation"
                    >
                        Revoke
                    </button>
                </div>
            </div>
            <button
                v-else
                type="button"
                class="mt-3 text-sm font-medium underline"
                :disabled="invitationBusy"
                @click="sendInvitation"
            >
                Invite account
            </button>
        </section>

        <!-- Domain communication preference / consent (Phase 5D.2) -->
        <section class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Communication preferences</h2>
            <p class="mt-1 text-xs text-slate-400">
                Governs optional Email communications sent directly to this Guardian's contact
                information, independent of any School OS account link above. Required/Emergency
                school communications are not affected by these settings.
            </p>

            <div class="mt-3 rounded border border-slate-200 p-4 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">Email</span>
                    <span
                        class="rounded px-1.5 py-0.5 text-xs font-medium"
                        :class="
                            communicationPreferences.email.endpointAvailable
                                ? 'bg-emerald-100 text-emerald-700'
                                : 'bg-amber-100 text-amber-700'
                        "
                    >
                        {{
                            communicationPreferences.email.endpointAvailable
                                ? 'Endpoint available'
                                : 'No email endpoint available'
                        }}
                    </span>
                </div>

                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <span class="text-slate-600">
                        Preference:
                        <span class="font-medium">{{
                            communicationPreferences.email.preferenceEnabled === null
                                ? 'Default (enabled)'
                                : communicationPreferences.email.preferenceEnabled
                                  ? 'Enabled'
                                  : 'Opted out'
                        }}</span>
                    </span>
                    <div v-if="canManageCommunicationPreferences" class="flex gap-2">
                        <button
                            type="button"
                            :disabled="updatingPreference"
                            class="rounded border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50 disabled:opacity-50"
                            @click="setEmailPreference(true)"
                        >
                            Enable
                        </button>
                        <button
                            type="button"
                            :disabled="updatingPreference"
                            class="rounded border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50 disabled:opacity-50"
                            @click="setEmailPreference(false)"
                        >
                            Opt out
                        </button>
                    </div>
                </div>

                <div
                    class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3"
                >
                    <span class="text-slate-600">
                        Consent:
                        <span class="font-medium">{{
                            communicationPreferences.email.consentStatus === null
                                ? 'Unknown'
                                : communicationPreferences.email.consentStatus === 'granted'
                                  ? 'Granted'
                                  : 'Withdrawn'
                        }}</span>
                    </span>
                    <div v-if="canManageCommunicationPreferences" class="flex gap-2">
                        <button
                            type="button"
                            :disabled="recordingConsent"
                            class="rounded border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50 disabled:opacity-50"
                            @click="recordEmailConsent('granted')"
                        >
                            Record granted
                        </button>
                        <button
                            type="button"
                            :disabled="recordingConsent"
                            class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-red-600 hover:bg-slate-50 disabled:opacity-50"
                            @click="recordEmailConsent('withdrawn')"
                        >
                            Record withdrawn
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
