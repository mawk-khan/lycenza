<script setup lang="ts">
// ORG.9D-3 (ADR 0076 §75): create or edit a draft School calendar Event.
// Times are entered in the SCHOOL's timezone; the server converts and checks
// them (a time skipped by a daylight-saving change is refused). Editing an
// approved Event returns it to draft for a fresh approval.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ErrorSummary from '../../../../Components/Form/ErrorSummary.vue';
import type { SchoolEvent } from '../../../../schoolEvents';

const props = defineProps<{
    event: SchoolEvent | null;
    mode: 'create' | 'edit';
    schoolTimezone: string;
    gradeLevels: { id: string; name: string }[];
    sections: { id: string; label: string }[];
}>();

const errors = computed(() => usePage().props.errors as Record<string, string | undefined>);
const source = props.event;
const title = ref(source?.title ?? '');
const body = ref(source?.body ?? '');
const location = ref(source?.location ?? '');
const dateMode = ref<'all_day' | 'timed'>(source?.dateMode ?? 'all_day');
const startsOn = ref(source?.startsOn ?? '');
const endsOn = ref(source?.endsOn ?? '');
const startsAt = ref(source?.startsAt ?? '');
const endsAt = ref(source?.endsAt ?? '');
const audienceType = ref(source?.audienceType ?? 'school_wide');
const gradeLevelId = ref(source?.gradeLevelId ?? '');
const sectionId = ref(source?.sectionId ?? '');
const saving = ref(false);

function submit(): void {
    saving.value = true;
    const payload = {
        title: title.value,
        body: body.value,
        location: location.value || null,
        date_mode: dateMode.value,
        starts_on: dateMode.value === 'all_day' ? startsOn.value : null,
        ends_on: dateMode.value === 'all_day' ? endsOn.value || startsOn.value : null,
        starts_at: dateMode.value === 'timed' ? startsAt.value : null,
        ends_at: dateMode.value === 'timed' ? endsAt.value : null,
        audience_type: audienceType.value,
        grade_level_id: audienceType.value === 'grade' ? gradeLevelId.value : null,
        section_id: audienceType.value === 'section' ? sectionId.value : null,
    };
    const done = { onFinish: () => (saving.value = false) };
    if (props.mode === 'edit' && props.event) {
        router.put(`/app/communications/events/${props.event.id}`, payload, done);
    } else {
        router.post('/app/communications/events', payload, done);
    }
}
</script>

<template>
    <Head :title="mode === 'edit' ? 'Edit event' : 'New event'" />
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/communications/events">← Events</a>
        <h1 class="mt-2 text-xl font-semibold">
            {{ mode === 'edit' ? 'Edit event' : 'New event' }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Saved as a draft. It needs approval by someone else, and publishing only shows it on the
            calendar — no message is sent. An event is information only: it never closes the School,
            cancels classes or changes attendance.
        </p>

        <ErrorSummary :errors="errors" :fields="['event']" />

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <label class="block text-sm"
                >Title
                <input
                    v-model="title"
                    type="text"
                    required
                    maxlength="200"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                />
                <span v-if="errors.title" class="text-xs text-red-700" role="alert">{{
                    errors.title
                }}</span>
            </label>
            <label class="block text-sm"
                >Description
                <textarea
                    v-model="body"
                    rows="5"
                    required
                    maxlength="10000"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                ></textarea>
                <span v-if="errors.body" class="text-xs text-red-700" role="alert">{{
                    errors.body
                }}</span>
            </label>
            <label class="block text-sm"
                >Location (optional, shown as text; it does not book a room)
                <input
                    v-model="location"
                    type="text"
                    maxlength="200"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                />
                <span v-if="errors.location" class="text-xs text-red-700" role="alert">{{
                    errors.location
                }}</span>
            </label>

            <fieldset class="text-sm">
                <legend>When ({{ schoolTimezone }})</legend>
                <label class="mr-4"
                    ><input v-model="dateMode" type="radio" value="all_day" /> All day</label
                >
                <label><input v-model="dateMode" type="radio" value="timed" /> At a time</label>
                <div v-if="dateMode === 'all_day'" class="mt-2 flex flex-wrap gap-3">
                    <label
                        >First day
                        <input
                            v-model="startsOn"
                            type="date"
                            required
                            class="mt-1 block rounded border border-slate-300 px-2 py-1"
                    /></label>
                    <label
                        >Last day
                        <input
                            v-model="endsOn"
                            type="date"
                            class="mt-1 block rounded border border-slate-300 px-2 py-1"
                    /></label>
                </div>
                <div v-else class="mt-2 flex flex-wrap gap-3">
                    <label
                        >Starts
                        <input
                            v-model="startsAt"
                            type="datetime-local"
                            required
                            class="mt-1 block rounded border border-slate-300 px-2 py-1"
                    /></label>
                    <label
                        >Ends
                        <input
                            v-model="endsAt"
                            type="datetime-local"
                            required
                            class="mt-1 block rounded border border-slate-300 px-2 py-1"
                    /></label>
                </div>
                <p
                    v-for="f in ['date_mode', 'starts_on', 'ends_on', 'starts_at', 'ends_at']"
                    :key="f"
                >
                    <span v-if="errors[f]" class="text-xs text-red-700" role="alert">{{
                        errors[f]
                    }}</span>
                </p>
            </fieldset>

            <label class="block text-sm"
                >Who can see it
                <select
                    v-model="audienceType"
                    class="mt-1 block rounded border border-slate-300 px-2 py-1"
                >
                    <option value="school_wide">Whole School</option>
                    <option value="staff">Staff only (current employees)</option>
                    <option value="grade">Guardians of a Grade</option>
                    <option value="section">Guardians of a Section (this year)</option>
                </select>
            </label>
            <label v-if="audienceType === 'grade'" class="block text-sm"
                >Grade
                <select
                    v-model="gradeLevelId"
                    required
                    class="mt-1 block rounded border border-slate-300 px-2 py-1"
                >
                    <option v-for="g in gradeLevels" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
            </label>
            <label v-if="audienceType === 'section'" class="block text-sm"
                >Section
                <select
                    v-model="sectionId"
                    required
                    class="mt-1 block rounded border border-slate-300 px-2 py-1"
                >
                    <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
            </label>
            <p v-for="f in ['audience_type', 'grade_level_id', 'section_id']" :key="f">
                <span v-if="errors[f]" class="text-xs text-red-700" role="alert">{{
                    errors[f]
                }}</span>
            </p>

            <button
                type="submit"
                :disabled="saving"
                class="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50"
            >
                Save draft
            </button>
        </form>
    </main>
</template>
