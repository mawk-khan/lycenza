<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { eventLabels, sourceLabels, type TaskRow } from '../../../../tasks';

/**
 * HR.13 — one staff task: its terms, its history (remarks and feedback are
 * kept, never overwritten) and the actions the server says this viewer may
 * take. The server re-checks every action.
 */
interface TaskDetail extends TaskRow {
    version: number;
    description: string | null;
    availableOn: string;
    assignedBy: string | null;
    returnCount: number;
    cancelReason: string | null;
    timeline: {
        id: string;
        kind: string;
        note: string | null;
        occurredAt: string;
        actor: string | null;
    }[];
    actions: {
        submit: boolean;
        review: boolean;
        canReturn: boolean;
        cancel: boolean;
        reassign: boolean;
    };
    cancelReasons: { value: string; label: string }[];
}

const props = defineProps<{ task: TaskDetail }>();
const t = props.task;
const submitForm = useForm({ version: t.version, remarks: '' });
const reviewForm = useForm({ version: t.version, decision: 'complete', feedback: '' });
const cancelForm = useForm({ version: t.version, reason: '', confirm: false as boolean });
const reassignForm = useForm({ version: t.version, employment_record_id: '' });
const open = ref<string | null>(null);
const candidates = ref<{ employmentRecordId: string; label: string }[]>([]);
const search = ref('');

function goBack(): void {
    window.history.back();
}

async function findPeople(): Promise<void> {
    const response = await fetch(`/app/hr/tasks/assignees?q=${encodeURIComponent(search.value)}`, {
        headers: { Accept: 'application/json' },
    });
    candidates.value = response.ok ? ((await response.json()).data ?? []) : [];
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <button type="button" class="text-sm underline" @click="goBack">← Back</button>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">{{ t.title }}</h1>
            <StatusBadge :status="t.status" />
            <span v-if="t.overdue" class="tag tag-attention"
                ><span aria-hidden="true">!</span> Overdue</span
            >
        </div>
        <p v-if="t.description" class="mt-2 whitespace-pre-line text-sm">{{ t.description }}</p>

        <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-ink-muted">Assigned to</dt>
                <dd>{{ t.assignee ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Category</dt>
                <dd>{{ t.category ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Source</dt>
                <dd>{{ sourceLabels[t.source] }}{{ t.assignedBy ? ` · ${t.assignedBy}` : '' }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Available from</dt>
                <dd>{{ t.availableOn }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Due</dt>
                <dd>{{ t.dueOn }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Completion</dt>
                <dd>
                    {{
                        t.completionMode === 'reviewed'
                            ? 'Reviewed by a manager or HR'
                            : 'Completed on submission'
                    }}
                </dd>
            </div>
            <div v-if="t.section || t.subjectOffering">
                <dt class="text-ink-muted">Academic context</dt>
                <dd>{{ [t.section, t.subjectOffering].filter(Boolean).join(' · ') }}</dd>
            </div>
            <div v-if="t.cancelReason">
                <dt class="text-ink-muted">Cancelled because</dt>
                <dd>{{ t.cancelReason }}</dd>
            </div>
        </dl>

        <section class="mt-8" aria-labelledby="task-actions">
            <h2 id="task-actions" class="text-base font-semibold">Actions</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Do not enter health, disciplinary or student information, and never a rating.
            </p>

            <form
                v-if="t.actions.submit"
                class="mt-3 space-y-2"
                @submit.prevent="
                    submitForm.post(`/app/my-tasks/${t.id}/submit`, { preserveScroll: true })
                "
            >
                <label class="field-label" for="task-remarks"
                    >Remarks (optional, 500 characters)</label
                >
                <textarea
                    id="task-remarks"
                    v-model="submitForm.remarks"
                    maxlength="500"
                    rows="3"
                    class="input mt-1"
                ></textarea>
                <p v-if="submitForm.errors.remarks" class="field-error" role="alert">
                    {{ submitForm.errors.remarks }}
                </p>
                <button type="submit" class="btn btn-primary" :disabled="submitForm.processing">
                    {{ t.completionMode === 'self' ? 'Mark task done' : 'Submit for review' }}
                </button>
            </form>

            <form
                v-if="t.actions.review"
                class="mt-3 space-y-2"
                @submit.prevent="
                    reviewForm.post(`/app/hr/tasks/${t.id}/review`, { preserveScroll: true })
                "
            >
                <fieldset>
                    <legend class="field-label">Decision</legend>
                    <label class="mr-4 inline-flex items-center gap-1"
                        ><input v-model="reviewForm.decision" type="radio" value="complete" />
                        Complete</label
                    >
                    <label v-if="t.actions.canReturn" class="inline-flex items-center gap-1"
                        ><input v-model="reviewForm.decision" type="radio" value="return" /> Return
                        for revision</label
                    >
                </fieldset>
                <label class="field-label" for="task-feedback"
                    >Feedback {{ reviewForm.decision === 'return' ? '(required)' : '(optional)' }},
                    500 characters</label
                >
                <textarea
                    id="task-feedback"
                    v-model="reviewForm.feedback"
                    maxlength="500"
                    rows="3"
                    class="input mt-1"
                ></textarea>
                <p v-if="reviewForm.errors.feedback" class="field-error" role="alert">
                    {{ reviewForm.errors.feedback }}
                </p>
                <button type="submit" class="btn btn-primary" :disabled="reviewForm.processing">
                    {{ reviewForm.decision === 'complete' ? 'Complete task' : 'Return task' }}
                </button>
            </form>

            <div class="mt-4 flex flex-wrap gap-3">
                <button
                    v-if="t.actions.reassign"
                    type="button"
                    class="btn btn-secondary"
                    :aria-expanded="open === 'reassign'"
                    @click="open = open === 'reassign' ? null : 'reassign'"
                >
                    Reassign task
                </button>
                <button
                    v-if="t.actions.cancel"
                    type="button"
                    class="btn btn-danger"
                    :aria-expanded="open === 'cancel'"
                    @click="open = open === 'cancel' ? null : 'cancel'"
                >
                    Cancel task
                </button>
            </div>

            <form
                v-if="open === 'reassign'"
                class="mt-3 space-y-2 border-t border-line pt-3"
                @submit.prevent="reassignForm.post(`/app/hr/tasks/${t.id}/reassign`)"
            >
                <label class="field-label" for="reassign-search">Find a person</label>
                <div class="flex gap-2">
                    <input
                        id="reassign-search"
                        v-model="search"
                        class="input mt-1"
                        @keydown.enter.prevent="findPeople"
                    />
                    <button type="button" class="btn btn-secondary" @click="findPeople">
                        Search
                    </button>
                </div>
                <label class="field-label" for="reassign-to">Reassign to</label>
                <select
                    id="reassign-to"
                    v-model="reassignForm.employment_record_id"
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
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="reassignForm.processing || !reassignForm.employment_record_id"
                >
                    Reassign task
                </button>
            </form>

            <form
                v-if="open === 'cancel'"
                class="mt-3 space-y-2 border-t border-line pt-3"
                @submit.prevent="
                    cancelForm.post(`/app/hr/tasks/${t.id}/cancel`, { preserveScroll: true })
                "
            >
                <label class="field-label" for="task-cancel-reason">Reason</label>
                <select
                    id="task-cancel-reason"
                    v-model="cancelForm.reason"
                    required
                    class="input mt-1 w-auto"
                >
                    <option value="" disabled>Choose a reason</option>
                    <option v-for="r in t.cancelReasons" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
                <label class="flex items-start gap-2 text-sm"
                    ><input v-model="cancelForm.confirm" type="checkbox" class="mt-1" />
                    <span>Cancel this task. It stays in the history.</span></label
                >
                <button
                    type="submit"
                    class="btn btn-danger"
                    :disabled="cancelForm.processing || !cancelForm.confirm"
                >
                    Cancel task
                </button>
            </form>
        </section>

        <section class="mt-10" aria-labelledby="task-history">
            <h2 id="task-history" class="text-base font-semibold">History</h2>
            <ol class="mt-3 space-y-2 text-sm">
                <li v-for="e in t.timeline" :key="e.id" class="border-l-2 border-line pl-3">
                    <span class="font-medium">{{ eventLabels[e.kind] ?? e.kind }}</span>
                    <span class="block text-ink-muted"
                        >{{ new Date(e.occurredAt).toLocaleString() }} ·
                        {{ e.actor ?? 'Scheduled' }}</span
                    >
                    <span v-if="e.note" class="mt-1 block whitespace-pre-line">{{ e.note }}</span>
                </li>
            </ol>
        </section>
    </main>
</template>
