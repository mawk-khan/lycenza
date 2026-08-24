<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import RelationshipFlags from '../../../Components/RelationshipFlags.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import { RELATIONSHIP_TYPES } from '../../../relationshipTypes';

interface Relationship {
    id: string;
    relationshipType: string;
    isPrimary: boolean;
    isLegalGuardian: boolean;
    isEmergencyContact: boolean;
    isAuthorizedPickup: boolean;
    guardian: {
        id: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        status: 'active' | 'inactive';
    };
    contact: { type: string; value: string } | null;
}

interface EnrollmentRef {
    id: string;
    name: string;
}

interface Enrollment {
    id: string;
    academicYear: EnrollmentRef;
    campus: EnrollmentRef;
    gradeLevel: EnrollmentRef;
    section: EnrollmentRef;
    rollNumber: string;
    status: 'active' | 'completed' | 'withdrawn' | 'transferred' | 'cancelled';
    startsOn: string;
    endsOn: string | null;
}

interface Props {
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        status: 'active' | 'inactive';
        dateOfBirth: string;
        createdAt: string;
    };
    relationships: Relationship[];
    canManageStudents: boolean;
    canManageGuardians: boolean;
    // Phase 1B.6: present ONLY when the actor holds enrollments.view --
    // a Student page for a user with students.view but not
    // enrollments.view must never receive Enrollment data at all (this
    // checkpoint's brief, section 35), so every field below the flag is
    // optional.
    canViewEnrollments: boolean;
    canManageEnrollments?: boolean;
    currentEnrollment?: Enrollment | null;
    enrollmentHistory?: Enrollment[];
}

const props = defineProps<Props>();

const canLinkGuardians = props.canManageStudents && props.canManageGuardians;

// --- Enrollment lifecycle (inline) -------------------------------------

type LifecycleAction = 'complete' | 'withdraw' | 'cancel';

const activeLifecycleAction = ref<LifecycleAction | null>(null);
const lifecycleForm = useForm({ ends_on: '' });

const LIFECYCLE_LABELS: Record<LifecycleAction, string> = {
    complete: 'Complete enrollment',
    withdraw: 'Withdraw enrollment',
    cancel: 'Cancel enrollment',
};

function startLifecycleAction(action: LifecycleAction): void {
    lifecycleForm.clearErrors();
    lifecycleForm.ends_on = '';
    activeLifecycleAction.value = action;
}

function submitLifecycleAction(enrollmentId: string): void {
    if (!activeLifecycleAction.value) return;
    lifecycleForm.post(`/app/enrollments/${enrollmentId}/${activeLifecycleAction.value}`, {
        onSuccess: () => {
            activeLifecycleAction.value = null;
        },
    });
}

function enrollmentPlacement(e: Enrollment): string {
    return `${e.gradeLevel.name} · Section ${e.section.name} · ${e.campus.name} · ${e.academicYear.name}`;
}

function fullName(): string {
    return [props.student.firstName, props.student.middleName, props.student.lastName]
        .filter(Boolean)
        .join(' ');
}

function guardianName(g: Relationship['guardian']): string {
    return [g.firstName, g.middleName, g.lastName].filter(Boolean).join(' ');
}

function toggleStatus(): void {
    const next = props.student.status === 'active' ? 'inactive' : 'active';
    router.post(`/app/students/${props.student.id}/status`, { status: next });
}

// --- Edit relationship (inline) --------------------------------------

const editingRelationshipId = ref<string | null>(null);
const relationshipForm = useForm({
    relationship_type: '',
    is_legal_guardian: false,
    is_emergency_contact: false,
    is_authorized_pickup: false,
});

function startEditRelationship(r: Relationship): void {
    relationshipForm.clearErrors();
    relationshipForm.relationship_type = r.relationshipType;
    relationshipForm.is_legal_guardian = r.isLegalGuardian;
    relationshipForm.is_emergency_contact = r.isEmergencyContact;
    relationshipForm.is_authorized_pickup = r.isAuthorizedPickup;
    editingRelationshipId.value = r.id;
}

function submitRelationshipEdit(relationshipId: string): void {
    relationshipForm.put(`/app/relationships/${relationshipId}`, {
        onSuccess: () => {
            editingRelationshipId.value = null;
        },
    });
}

function makePrimary(relationshipId: string): void {
    router.post(`/app/relationships/${relationshipId}/primary`, {}, { preserveScroll: true });
}

function unlink(r: Relationship): void {
    const confirmed = window.confirm(
        `Remove this Guardian from ${fullName()}?\n\n` +
            'This removes their relationship with this student. The Guardian record and links to other students will remain.',
    );
    if (!confirmed) return;

    router.delete(`/app/relationships/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/students">← Students</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ fullName() }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ student.studentNumber }}</p>
            </div>
            <div v-if="canManageStudents" class="flex items-center gap-2">
                <a
                    :href="`/app/students/${student.id}/edit`"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                >
                    Edit
                </a>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                    @click="toggleStatus"
                >
                    {{ student.status === 'active' ? 'Mark inactive' : 'Mark active' }}
                </button>
            </div>
        </div>

        <!-- Identity -->
        <section class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Identity</h2>
            <dl
                class="mt-2 grid grid-cols-1 gap-x-8 gap-y-3 rounded border border-slate-200 p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-slate-500">Student number</dt>
                    <dd class="mt-0.5">{{ student.studentNumber }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Status</dt>
                    <dd class="mt-0.5"><StatusBadge :status="student.status" /></dd>
                </div>
                <div>
                    <dt class="text-slate-500">Name</dt>
                    <dd class="mt-0.5">{{ fullName() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Date of birth</dt>
                    <dd class="mt-0.5">{{ student.dateOfBirth }}</dd>
                </div>
            </dl>
        </section>

        <!-- Academic placement (Phase 1B.6) -- absent entirely (not
             just hidden) unless the actor holds enrollments.view. -->
        <section v-if="canViewEnrollments" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Current placement</h2>
                <a
                    v-if="canManageEnrollments && !currentEnrollment"
                    :href="`/app/students/${student.id}/enrollments/create`"
                    class="text-sm font-medium underline"
                >
                    Enroll student
                </a>
            </div>

            <EmptyState
                v-if="!currentEnrollment"
                class="mt-3"
                title="No current enrollment"
                description="This student has no active academic placement for the current Academic Year."
            />

            <div v-else class="mt-3 rounded border border-slate-200 p-4 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ enrollmentPlacement(currentEnrollment) }}</p>
                        <p class="mt-1 text-slate-500">
                            Roll {{ currentEnrollment.rollNumber }} · Since
                            {{ currentEnrollment.startsOn }}
                        </p>
                        <div class="mt-2"><StatusBadge :status="currentEnrollment.status" /></div>
                    </div>

                    <div
                        v-if="canManageEnrollments"
                        class="flex flex-wrap items-center gap-3 text-sm"
                    >
                        <a
                            :href="`/app/enrollments/${currentEnrollment.id}/transfer`"
                            class="underline"
                        >
                            Transfer
                        </a>
                        <button
                            type="button"
                            class="underline"
                            @click="startLifecycleAction('complete')"
                        >
                            Complete
                        </button>
                        <button
                            type="button"
                            class="underline"
                            @click="startLifecycleAction('withdraw')"
                        >
                            Withdraw
                        </button>
                        <button
                            type="button"
                            class="text-red-600 underline"
                            @click="startLifecycleAction('cancel')"
                        >
                            Cancel
                        </button>
                    </div>
                </div>

                <!-- Inline lifecycle form: Complete/Withdraw/Cancel all
                     collect exactly one field (an end date) -- there is
                     no generic status editor (this checkpoint's brief,
                     section 40). Each button posts to its own explicit,
                     named backend action. -->
                <form
                    v-if="activeLifecycleAction"
                    class="mt-4 border-t border-slate-100 pt-4"
                    @submit.prevent="submitLifecycleAction(currentEnrollment.id)"
                >
                    <p class="text-sm font-medium text-slate-700">
                        {{ LIFECYCLE_LABELS[activeLifecycleAction] }}
                    </p>
                    <div class="mt-2">
                        <label
                            class="block text-sm text-slate-600"
                            :for="`ends-on-${activeLifecycleAction}`"
                        >
                            End date
                        </label>
                        <input
                            :id="`ends-on-${activeLifecycleAction}`"
                            v-model="lifecycleForm.ends_on"
                            type="date"
                            required
                            :aria-invalid="!!lifecycleForm.errors.ends_on"
                            aria-describedby="ends-on-error"
                            class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm sm:w-56"
                        />
                        <p id="ends-on-error" class="mt-1 text-sm text-red-600">
                            {{ lifecycleForm.errors.ends_on }}
                        </p>
                    </div>
                    <div class="mt-3 flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="lifecycleForm.processing"
                            class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Confirm
                        </button>
                        <button
                            type="button"
                            class="text-sm underline"
                            @click="activeLifecycleAction = null"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            <h2 class="mt-6 text-sm font-medium text-slate-500">Enrollment history</h2>
            <p
                v-if="!enrollmentHistory || enrollmentHistory.length === 0"
                class="mt-3 text-sm text-slate-500"
            >
                No Enrollment history yet.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="e in enrollmentHistory"
                    :key="e.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p>{{ enrollmentPlacement(e) }}</p>
                        <p class="mt-0.5 text-slate-500">
                            Roll {{ e.rollNumber }} · {{ e.startsOn }} – {{ e.endsOn ?? 'present' }}
                        </p>
                    </div>
                    <StatusBadge :status="e.status" />
                </li>
            </ul>
        </section>

        <!-- Guardians -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Guardians</h2>
                <a
                    v-if="canLinkGuardians"
                    :href="`/app/students/${student.id}/guardians/add`"
                    class="text-sm font-medium underline"
                >
                    Add guardian
                </a>
            </div>

            <p v-if="relationships.length === 0" class="mt-3 text-sm text-slate-500">
                No Guardians linked yet.
            </p>

            <ul v-else class="mt-3 space-y-3">
                <li
                    v-for="r in relationships"
                    :key="r.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <a
                                class="font-medium underline"
                                :href="`/app/guardians/${r.guardian.id}`"
                                >{{ guardianName(r.guardian) }}</a
                            >
                            <RelationshipFlags
                                :relationship-type="r.relationshipType"
                                :is-primary="r.isPrimary"
                                :is-legal-guardian="r.isLegalGuardian"
                                :is-emergency-contact="r.isEmergencyContact"
                                :is-authorized-pickup="r.isAuthorizedPickup"
                            />
                            <p v-if="r.contact" class="mt-1 text-sm text-slate-500">
                                {{ r.contact.value }}
                            </p>
                        </div>

                        <div
                            v-if="canLinkGuardians"
                            class="flex flex-wrap items-center gap-3 text-sm"
                        >
                            <button
                                v-if="!r.isPrimary"
                                type="button"
                                class="underline"
                                @click="makePrimary(r.id)"
                            >
                                Make primary
                            </button>
                            <button
                                type="button"
                                class="underline"
                                @click="startEditRelationship(r)"
                            >
                                Edit
                            </button>
                            <button type="button" class="text-red-600 underline" @click="unlink(r)">
                                Remove from student
                            </button>
                        </div>
                    </div>

                    <!-- Inline edit-relationship form -->
                    <form
                        v-if="editingRelationshipId === r.id"
                        class="mt-4 border-t border-slate-100 pt-4"
                        @submit.prevent="submitRelationshipEdit(r.id)"
                    >
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label
                                    class="block text-sm text-slate-600"
                                    :for="`relationship-type-${r.id}`"
                                    >Relationship</label
                                >
                                <select
                                    :id="`relationship-type-${r.id}`"
                                    v-model="relationshipForm.relationship_type"
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option
                                        v-for="t in RELATIONSHIP_TYPES"
                                        :key="t.value"
                                        :value="t.value"
                                    >
                                        {{ t.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <fieldset class="mt-3 space-y-2 text-sm">
                            <legend class="sr-only">Authority</legend>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="relationshipForm.is_legal_guardian"
                                    type="checkbox"
                                />
                                Legal guardian
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="relationshipForm.is_emergency_contact"
                                    type="checkbox"
                                />
                                Emergency contact
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="relationshipForm.is_authorized_pickup"
                                    type="checkbox"
                                />
                                Authorized pickup
                            </label>
                        </fieldset>
                        <div class="mt-3 flex items-center gap-3">
                            <button
                                type="submit"
                                :disabled="relationshipForm.processing"
                                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="text-sm underline"
                                @click="editingRelationshipId = null"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </li>
            </ul>
        </section>
    </main>
</template>
