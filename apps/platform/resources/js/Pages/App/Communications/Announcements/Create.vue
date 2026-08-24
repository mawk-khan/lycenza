<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface TemplatePrefill {
    id: string;
    title: string | null;
    body: string;
    priority: string | null;
}

interface CurrentAcademicYear {
    id: string;
    label: string;
}

interface Props {
    emailChannelEnabled: boolean;
    schoolTimezone: string;
    canMarkRequired: boolean;
    canDispatchEmergency: boolean;
    template: TemplatePrefill | null;
    currentAcademicYear: CurrentAcademicYear | null;
}

const props = defineProps<Props>();

const title = ref(props.template?.title ?? '');
const body = ref(props.template?.body ?? '');
const priority = ref<'normal' | 'important' | 'urgent' | 'critical'>(
    (props.template?.priority as 'normal' | 'important' | 'urgent' | 'critical' | undefined) ??
        'normal',
);
interface DomainTarget {
    id: string;
    label: string;
}

const audienceType = ref<
    | 'school_wide'
    | 'individual'
    | 'student'
    | 'guardian'
    | 'guardians_of_students'
    | 'grade'
    | 'section'
>('school_wide');
const memberIds = ref('');

// Phase 5B.1 §11/§12/§13/§25/§26: search-assisted picker for the three
// new domain audience types, mirroring the exact search-then-add-chip
// pattern already established in
// resources/js/Pages/App/Communications/Conversations.vue's
// participant picker. 'student' and 'guardians_of_students' both
// search Students (guardians_of_students' INPUT is Students -- its
// Guardian output is derived server-side); 'guardian' searches
// Guardians directly.
const selectedDomainTargets = ref<DomainTarget[]>([]);
const domainQuery = ref('');
const domainResults = ref<DomainTarget[]>([]);
const domainSearching = ref(false);
let domainSearchDebounce: ReturnType<typeof setTimeout> | undefined;

function domainSearchEndpoint(): string | null {
    if (audienceType.value === 'student' || audienceType.value === 'guardians_of_students') {
        return '/app/communications/audience/students/search';
    }
    if (audienceType.value === 'guardian') {
        return '/app/communications/audience/guardians/search';
    }
    return null;
}

function searchDomainTargets() {
    const endpoint = domainSearchEndpoint();
    const q = domainQuery.value.trim();
    clearTimeout(domainSearchDebounce);
    if (endpoint === null || q === '') {
        domainResults.value = [];
        return;
    }
    domainSearchDebounce = setTimeout(async () => {
        domainSearching.value = true;
        try {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();
            const results: DomainTarget[] = (body.students ??
                body.guardians ??
                []) as DomainTarget[];
            const selectedIds = new Set(selectedDomainTargets.value.map((t) => t.id));
            domainResults.value = results.filter((t) => !selectedIds.has(t.id));
        } finally {
            domainSearching.value = false;
        }
    }, 250);
}

function addDomainTarget(target: DomainTarget) {
    selectedDomainTargets.value.push(target);
    domainResults.value = domainResults.value.filter((t) => t.id !== target.id);
    domainQuery.value = '';
}

function removeDomainTarget(id: string) {
    selectedDomainTargets.value = selectedDomainTargets.value.filter((t) => t.id !== id);
}

watch(audienceType, () => {
    selectedDomainTargets.value = [];
    domainResults.value = [];
    domainQuery.value = '';
    selectedCohort.value = null;
    cohortResults.value = [];
    cohortQuery.value = '';
});

// Phase 5B.3 §6/§7/§34: the Grade/Section academic-cohort picker --
// mirrors the Student/Guardian search-then-select pattern above.
// `recipientKind` ('student'|'guardian') is a field on the single
// cohort selection, not a separate audience type (brief §5's
// composable-model decision -- see CommunicationAudienceType's
// docblock).
interface CohortTarget {
    id: string;
    label: string;
}

const cohortRecipientKind = ref<'student' | 'guardian'>('student');
const selectedCohort = ref<CohortTarget | null>(null);
const cohortQuery = ref('');
const cohortResults = ref<CohortTarget[]>([]);
const cohortSearching = ref(false);
let cohortSearchDebounce: ReturnType<typeof setTimeout> | undefined;

function cohortSearchEndpoint(): string | null {
    if (audienceType.value === 'grade') {
        return '/app/communications/audience/grade-levels/search';
    }
    if (audienceType.value === 'section') {
        return '/app/communications/audience/sections/search';
    }
    return null;
}

function searchCohort() {
    const endpoint = cohortSearchEndpoint();
    clearTimeout(cohortSearchDebounce);
    if (endpoint === null || !props.currentAcademicYear) {
        cohortResults.value = [];
        return;
    }
    cohortSearchDebounce = setTimeout(async () => {
        cohortSearching.value = true;
        try {
            const params = new URLSearchParams({ q: cohortQuery.value.trim() });
            if (audienceType.value === 'section') {
                params.set('academic_year_id', props.currentAcademicYear!.id);
            }
            const response = await fetch(`${endpoint}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();
            cohortResults.value = (body.gradeLevels ?? body.sections ?? []) as CohortTarget[];
        } finally {
            cohortSearching.value = false;
        }
    }, 250);
}

function selectCohort(target: CohortTarget) {
    selectedCohort.value = target;
    cohortResults.value = [];
    cohortQuery.value = '';
}
const emailSelected = ref(false);
const requirement = ref<'optional' | 'required'>('optional');
// Phase 5A.10 §40: never a default -- always an explicit choice, and
// never inherited from a template (brief §13: templates carry no
// dispatch-mode authority).
const dispatchMode = ref<'standard' | 'emergency'>('standard');
const emergencyJustification = ref('');
const emergencyAcknowledged = ref(false);
const submitting = ref(false);

// Phase 5A.10 §39: the backend independently enforces this invariant
// too -- this is a UX convenience, not the authorization boundary.
watch(dispatchMode, (mode) => {
    if (mode === 'emergency') {
        requirement.value = 'required';
    }
});

function submit() {
    submitting.value = true;
    const isEmergency = props.canDispatchEmergency && dispatchMode.value === 'emergency';
    router.post(
        '/app/communications/announcements',
        {
            title: title.value,
            body: body.value,
            priority: priority.value,
            audience_type: audienceType.value,
            member_user_ids:
                audienceType.value === 'individual'
                    ? memberIds.value
                          .split(',')
                          .map((id) => id.trim())
                          .filter(Boolean)
                    : [],
            domain_audience_member_ids:
                audienceType.value === 'student' ||
                audienceType.value === 'guardian' ||
                audienceType.value === 'guardians_of_students'
                    ? selectedDomainTargets.value.map((t) => t.id)
                    : [],
            academic_cohort:
                (audienceType.value === 'grade' || audienceType.value === 'section') &&
                props.currentAcademicYear &&
                selectedCohort.value
                    ? {
                          academic_year_id: props.currentAcademicYear.id,
                          grade_level_id:
                              audienceType.value === 'grade' ? selectedCohort.value.id : null,
                          section_id:
                              audienceType.value === 'section' ? selectedCohort.value.id : null,
                          recipient_kind: cohortRecipientKind.value,
                      }
                    : undefined,
            channels:
                props.emailChannelEnabled && emailSelected.value ? ['in_app', 'email'] : ['in_app'],
            source_template_id: props.template?.id ?? null,
            requirement: props.canMarkRequired ? requirement.value : 'optional',
            dispatch_mode: isEmergency ? 'emergency' : 'standard',
            emergency_justification: isEmergency ? emergencyJustification.value : null,
            emergency_acknowledged: isEmergency ? emergencyAcknowledged.value : undefined,
        },
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications/announcements"
            >← Announcements</a
        >
        <h1 class="mt-2 text-xl font-semibold">New Announcement</h1>
        <p class="mt-1 text-xs text-slate-500">
            Saved as a draft first -- you'll see an audience preview before publishing or
            scheduling.
        </p>
        <p v-if="template" class="mt-1 text-xs text-slate-400">
            Pre-filled from template. Editing here does not change the template.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-xs font-medium text-slate-500">Title</label>
                <input
                    v-model="title"
                    type="text"
                    required
                    maxlength="255"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                />
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500">Message</label>
                <textarea
                    v-model="body"
                    rows="5"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                ></textarea>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500">Priority</label>
                <select
                    v-model="priority"
                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                    <option value="critical">Critical</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500">Audience</label>
                <select
                    v-model="audienceType"
                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="school_wide">Entire School</option>
                    <option value="individual">Selected Members</option>
                    <option value="student">Students</option>
                    <option value="guardian">Guardians</option>
                    <option value="guardians_of_students">Guardians of Selected Students</option>
                    <option value="grade" :disabled="!currentAcademicYear">
                        Grade (Academic Cohort)
                    </option>
                    <option value="section" :disabled="!currentAcademicYear">
                        Section (Academic Cohort)
                    </option>
                </select>
                <p v-if="!currentAcademicYear" class="mt-1 text-xs text-slate-400">
                    Grade/Section audiences are unavailable -- this school has no active academic
                    year set.
                </p>
            </div>

            <div v-if="audienceType === 'individual'">
                <label class="block text-xs font-medium text-slate-500"
                    >Member user IDs (comma-separated)</label
                >
                <input
                    v-model="memberIds"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                />
            </div>

            <div
                v-if="
                    audienceType === 'student' ||
                    audienceType === 'guardian' ||
                    audienceType === 'guardians_of_students'
                "
            >
                <label class="block text-xs font-medium text-slate-500">
                    {{
                        audienceType === 'guardian'
                            ? 'Guardians'
                            : audienceType === 'guardians_of_students'
                              ? 'Students (their Guardians will be targeted)'
                              : 'Students'
                    }}
                </label>
                <p
                    v-if="audienceType === 'guardians_of_students'"
                    class="mt-1 text-xs text-slate-400"
                >
                    Only each Student's primary or legal Guardian is included. A Guardian shared by
                    multiple selected Students is targeted once.
                </p>
                <div v-if="selectedDomainTargets.length > 0" class="mt-1 flex flex-wrap gap-1">
                    <span
                        v-for="t in selectedDomainTargets"
                        :key="t.id"
                        class="flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
                    >
                        {{ t.label }}
                        <button
                            type="button"
                            class="text-slate-400 hover:text-slate-700"
                            @click="removeDomainTarget(t.id)"
                        >
                            ×
                        </button>
                    </span>
                </div>
                <div class="relative mt-1">
                    <input
                        v-model="domainQuery"
                        type="text"
                        :placeholder="
                            audienceType === 'guardian'
                                ? 'Search guardians by name…'
                                : 'Search students by name or student number…'
                        "
                        class="w-full rounded border border-slate-300 px-2 py-1 text-sm"
                        @input="searchDomainTargets"
                    />
                    <ul
                        v-if="domainResults.length > 0"
                        class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                    >
                        <li
                            v-for="result in domainResults"
                            :key="result.id"
                            class="cursor-pointer px-2 py-1 hover:bg-slate-50"
                            @click="addDomainTarget(result)"
                        >
                            {{ result.label }}
                        </li>
                    </ul>
                </div>
            </div>

            <div
                v-if="
                    (audienceType === 'grade' || audienceType === 'section') && currentAcademicYear
                "
            >
                <label class="block text-xs font-medium text-slate-500">
                    {{ audienceType === 'grade' ? 'Grade' : 'Section' }}
                </label>
                <p class="mt-1 text-xs text-slate-400">
                    Academic year: {{ currentAcademicYear.label }}. Recipients are the Students
                    currently enrolled in this {{ audienceType === 'grade' ? 'Grade' : 'Section' }}
                    for this academic year -- resolved fresh again at publication (and, for a
                    scheduled announcement, again at the scheduled time), never fixed to who's
                    enrolled today.
                </p>

                <div v-if="selectedCohort" class="mt-2 flex items-center gap-1">
                    <span
                        class="flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
                    >
                        {{ selectedCohort.label }}
                        <button
                            type="button"
                            class="text-slate-400 hover:text-slate-700"
                            @click="selectedCohort = null"
                        >
                            ×
                        </button>
                    </span>
                </div>
                <div v-else class="relative mt-1">
                    <input
                        v-model="cohortQuery"
                        type="text"
                        :placeholder="
                            audienceType === 'grade' ? 'Search grades…' : 'Search sections…'
                        "
                        class="w-full rounded border border-slate-300 px-2 py-1 text-sm"
                        @focus="searchCohort"
                        @input="searchCohort"
                    />
                    <ul
                        v-if="cohortResults.length > 0"
                        class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                    >
                        <li
                            v-for="result in cohortResults"
                            :key="result.id"
                            class="cursor-pointer px-2 py-1 hover:bg-slate-50"
                            @click="selectCohort(result)"
                        >
                            {{ result.label }}
                        </li>
                    </ul>
                </div>

                <div class="mt-2 space-y-1 text-sm text-slate-600">
                    <label class="flex items-center gap-2">
                        <input
                            v-model="cohortRecipientKind"
                            type="radio"
                            value="student"
                            class="border-slate-300"
                        />
                        Students
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="cohortRecipientKind"
                            type="radio"
                            value="guardian"
                            class="border-slate-300"
                        />
                        Guardians
                    </label>
                    <p v-if="cohortRecipientKind === 'guardian'" class="text-xs text-slate-400">
                        Only each Student's primary or legal Guardian is included. A Guardian shared
                        by multiple Students in this cohort is targeted once.
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500">Delivery</label>
                <div class="mt-1 space-y-1">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" checked disabled class="rounded border-slate-300" />
                        In-app
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm"
                        :class="emailChannelEnabled ? 'text-slate-600' : 'text-slate-400'"
                    >
                        <input
                            v-model="emailSelected"
                            type="checkbox"
                            :disabled="!emailChannelEnabled"
                            class="rounded border-slate-300"
                        />
                        Email
                        <span v-if="!emailChannelEnabled" class="text-xs text-slate-400"
                            >(not currently available for this school)</span
                        >
                    </label>
                </div>
            </div>

            <div v-if="canMarkRequired">
                <label class="block text-xs font-medium text-slate-500">Delivery policy</label>
                <div class="mt-1 space-y-1 text-sm text-slate-600">
                    <label class="flex items-start gap-2">
                        <input
                            v-model="requirement"
                            type="radio"
                            value="optional"
                            :disabled="dispatchMode === 'emergency'"
                            class="mt-0.5 border-slate-300"
                        />
                        <span>
                            Standard
                            <span class="block text-xs text-slate-400"
                                >Respects each recipient's optional-channel preferences.</span
                            >
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input
                            v-model="requirement"
                            type="radio"
                            value="required"
                            :disabled="dispatchMode === 'emergency'"
                            class="mt-0.5 border-slate-300"
                        />
                        <span>
                            Required communication
                            <span class="block text-xs text-slate-400"
                                >May reach recipients on a channel their preference would otherwise
                                suppress, where school policy still permits that channel for
                                required communication.</span
                            >
                        </span>
                    </label>
                    <p v-if="dispatchMode === 'emergency'" class="text-xs text-slate-400">
                        Locked to Required because this is an Emergency communication.
                    </p>
                </div>
            </div>

            <div v-if="canDispatchEmergency" class="rounded border border-red-200 bg-red-50 p-3">
                <label class="flex items-start gap-2 text-sm font-medium text-red-800">
                    <input
                        :checked="dispatchMode === 'emergency'"
                        type="checkbox"
                        class="mt-0.5 rounded border-red-300"
                        @change="
                            dispatchMode = ($event.target as HTMLInputElement).checked
                                ? 'emergency'
                                : 'standard'
                        "
                    />
                    Emergency communication
                </label>
                <p class="mt-1 text-xs text-red-700">
                    This will mark the announcement as an emergency communication and may bypass
                    configured quiet hours on channels where the school has explicitly enabled that
                    bypass. It will automatically become Required.
                </p>

                <div v-if="dispatchMode === 'emergency'" class="mt-3 space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-red-800"
                            >Internal justification</label
                        >
                        <textarea
                            v-model="emergencyJustification"
                            rows="2"
                            maxlength="500"
                            required
                            placeholder="Short internal reason -- not necessarily shown to recipients."
                            class="mt-1 w-full rounded border border-red-300 px-2 py-1 text-sm"
                        ></textarea>
                    </div>
                    <label class="flex items-start gap-2 text-xs text-red-800">
                        <input
                            v-model="emergencyAcknowledged"
                            type="checkbox"
                            required
                            class="mt-0.5 rounded border-red-300"
                        />
                        I understand this communication is intended for an emergency situation.
                    </label>
                </div>
            </div>

            <button
                type="submit"
                :disabled="
                    submitting ||
                    (dispatchMode === 'emergency' &&
                        (!emergencyJustification.trim() || !emergencyAcknowledged)) ||
                    ((audienceType === 'grade' || audienceType === 'section') && !selectedCohort)
                "
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
            >
                Save Draft
            </button>
        </form>
    </main>
</template>
