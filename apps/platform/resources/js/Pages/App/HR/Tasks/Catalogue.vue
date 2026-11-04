<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import { WEEKDAYS } from '../../../../tasks';

/**
 * HR.13 — task categories and recurring tasks. A recurring task's terms are
 * immutable versions: a change is a new version from a later date, so every
 * task keeps the version that created it. Schedules are calendar-neutral (no
 * holidays): daily on chosen weekdays, weekly on one weekday, monthly on a
 * day 1–28 or the last day, yearly on a date that exists every year.
 */
interface Version {
    id: string;
    versionNo: number;
    title: string;
    category: string | null;
    completionMode: string;
    frequency: string;
    weekdays: number | null;
    monthDay: number | null;
    lastDay: boolean;
    yearMonth: number | null;
    dueDays: number;
    staffTypes: string[];
    departmentId: string | null;
    positionId: string | null;
    effectiveFrom: string;
}

const props = defineProps<{
    categories: { id: string; code: string; label: string; status: string }[];
    templates: { id: string; code: string; status: string; versions: Version[] }[];
    departments: { id: string; label: string }[];
    positions: { id: string; label: string }[];
    frequencies: { value: string; label: string }[];
    today: string;
}>();

const activeCategories = computed(() => props.categories.filter((c) => c.status === 'active'));
const categoryForm = useForm({ code: '', label: '' });
const retireForm = useForm({});
const generateForm = useForm({});

const blank = () => ({
    code: '',
    title: '',
    description: '',
    category_id: '',
    completion_mode: 'self',
    frequency: 'daily',
    weekdays: 31,
    month_day: 1,
    last_day: false as boolean,
    year_month: 1,
    due_days: 0,
    applies_teaching: true as boolean,
    applies_non_teaching: true as boolean,
    applies_outsourced: false as boolean,
    department_id: '',
    position_id: '',
    effective_from: props.today,
});
const templateForm = useForm(blank());

function toggleDay(bit: number): void {
    if (templateForm.frequency === 'weekly') {
        templateForm.weekdays = bit;
    } else {
        templateForm.weekdays = templateForm.weekdays ^ bit;
    }
}

function schedule(v: Version): string {
    const days = (mask: number | null) =>
        WEEKDAYS.filter((d) => ((mask ?? 0) & d.bit) !== 0)
            .map((d) => d.label)
            .join(', ');
    switch (v.frequency) {
        case 'daily':
            return `Daily on ${days(v.weekdays)}`;
        case 'weekly':
            return `Weekly on ${days(v.weekdays)}`;
        case 'monthly':
            return v.lastDay ? 'Monthly on the last day' : `Monthly on day ${v.monthDay}`;
        default:
            return v.lastDay
                ? `Yearly on the last day of month ${v.yearMonth}`
                : `Yearly on ${v.monthDay}/${v.yearMonth}`;
    }
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr/tasks">← Staff tasks</a>
        <div class="mt-2 flex flex-wrap items-baseline justify-between gap-3">
            <h1 class="text-xl font-semibold">Recurring tasks and categories</h1>
            <button
                type="button"
                class="btn btn-secondary"
                :disabled="generateForm.processing"
                @click="generateForm.post('/app/hr/tasks/generate', { preserveScroll: true })"
            >
                Generate today's tasks now
            </button>
        </div>
        <p class="mt-1 text-sm text-ink-muted">
            Today's tasks are also generated automatically. Generation never creates a task twice
            and never fills in past days.
        </p>

        <section class="mt-8" aria-labelledby="categories-heading">
            <h2 id="categories-heading" class="text-base font-semibold">Categories</h2>
            <EmptyState
                v-if="categories.length === 0"
                class="mt-3"
                title="No categories yet"
                description="Add one below before adding tasks."
            />
            <ul v-else class="mt-3 space-y-1 text-sm">
                <li v-for="c in categories" :key="c.id" class="flex items-center gap-3">
                    <span
                        >{{ c.label }} <span class="text-ink-muted">({{ c.code }})</span></span
                    >
                    <span v-if="c.status === 'retired'" class="tag tag-neutral">Retired</span>
                    <button
                        v-else
                        type="button"
                        class="btn btn-ghost"
                        :disabled="retireForm.processing"
                        @click="
                            retireForm.post(`/app/hr/tasks/catalogue/categories/${c.id}/retire`, {
                                preserveScroll: true,
                            })
                        "
                    >
                        Retire {{ c.code }}
                    </button>
                </li>
            </ul>
            <form
                class="mt-3 flex flex-wrap items-end gap-3"
                @submit.prevent="
                    categoryForm.post('/app/hr/tasks/catalogue/categories', {
                        preserveScroll: true,
                        onSuccess: () => categoryForm.reset(),
                    })
                "
            >
                <div>
                    <label class="field-label" for="category-code">Code</label>
                    <input
                        id="category-code"
                        v-model="categoryForm.code"
                        required
                        maxlength="40"
                        class="input mt-1 w-auto"
                    />
                </div>
                <div>
                    <label class="field-label" for="category-label">Label</label>
                    <input
                        id="category-label"
                        v-model="categoryForm.label"
                        required
                        maxlength="80"
                        class="input mt-1 w-auto"
                    />
                </div>
                <button type="submit" class="btn btn-secondary" :disabled="categoryForm.processing">
                    Add category
                </button>
            </form>
        </section>

        <section class="mt-10" aria-labelledby="templates-heading">
            <h2 id="templates-heading" class="text-base font-semibold">Recurring tasks</h2>
            <EmptyState
                v-if="templates.length === 0"
                class="mt-3"
                title="No recurring tasks yet"
                description="Add one below."
            />
            <div v-for="tpl in templates" :key="tpl.id" class="frame mt-3 p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <h3 class="font-semibold">
                        {{ tpl.versions[0]?.title }}
                        <span class="text-ink-muted">({{ tpl.code }})</span>
                    </h3>
                    <span v-if="tpl.status === 'retired'" class="tag tag-neutral">Retired</span>
                    <button
                        v-else
                        type="button"
                        class="btn btn-ghost"
                        :disabled="retireForm.processing"
                        @click="
                            retireForm.post(`/app/hr/tasks/catalogue/templates/${tpl.id}/retire`, {
                                preserveScroll: true,
                            })
                        "
                    >
                        Retire {{ tpl.code }}
                    </button>
                </div>
                <table class="data-table mt-2 w-full">
                    <caption class="sr-only">
                        Versions of
                        {{
                            tpl.code
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Version</th>
                            <th scope="col">From</th>
                            <th scope="col">Schedule</th>
                            <th scope="col">Due</th>
                            <th scope="col">Staff types</th>
                            <th scope="col">Completion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="v in tpl.versions" :key="v.id">
                            <td>{{ v.versionNo }} · {{ v.title }}</td>
                            <td>{{ v.effectiveFrom }}</td>
                            <td>{{ schedule(v) }}</td>
                            <td>
                                {{ v.dueDays === 0 ? 'same day' : `${v.dueDays} day(s) later` }}
                            </td>
                            <td>{{ v.staffTypes.join(', ') }}</td>
                            <td>{{ v.completionMode === 'reviewed' ? 'Reviewed' : 'Self' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form
                class="mt-6 space-y-4 border-t border-line pt-6"
                @submit.prevent="
                    templateForm.post('/app/hr/tasks/catalogue/templates', {
                        preserveScroll: true,
                        onSuccess: () => templateForm.reset(),
                    })
                "
            >
                <h3 class="font-semibold">Add a recurring task</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="tpl-code">Code</label
                        ><input
                            id="tpl-code"
                            v-model="templateForm.code"
                            required
                            maxlength="40"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="field-label" for="tpl-title">Title</label
                        ><input
                            id="tpl-title"
                            v-model="templateForm.title"
                            required
                            maxlength="120"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="field-label" for="tpl-category">Category</label>
                        <select
                            id="tpl-category"
                            v-model="templateForm.category_id"
                            required
                            class="input mt-1"
                        >
                            <option value="" disabled>Choose a category</option>
                            <option v-for="c in activeCategories" :key="c.id" :value="c.id">
                                {{ c.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="tpl-mode">Completion</label>
                        <select
                            id="tpl-mode"
                            v-model="templateForm.completion_mode"
                            class="input mt-1"
                        >
                            <option value="self">Done on submission</option>
                            <option value="reviewed">Reviewed after submission</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="tpl-frequency">Frequency</label>
                        <select
                            id="tpl-frequency"
                            v-model="templateForm.frequency"
                            class="input mt-1"
                        >
                            <option v-for="f in frequencies" :key="f.value" :value="f.value">
                                {{ f.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="tpl-due">Due after (calendar days)</label
                        ><input
                            id="tpl-due"
                            v-model.number="templateForm.due_days"
                            type="number"
                            min="0"
                            max="366"
                            class="input mt-1"
                        />
                    </div>
                </div>
                <fieldset
                    v-if="templateForm.frequency === 'daily' || templateForm.frequency === 'weekly'"
                >
                    <legend class="field-label">
                        {{ templateForm.frequency === 'weekly' ? 'Weekday' : 'Weekdays' }}
                    </legend>
                    <label
                        v-for="d in WEEKDAYS"
                        :key="d.bit"
                        class="mr-3 inline-flex items-center gap-1"
                    >
                        <input
                            type="checkbox"
                            :checked="(templateForm.weekdays & d.bit) !== 0"
                            @change="toggleDay(d.bit)"
                        />
                        {{ d.label }}
                    </label>
                </fieldset>
                <div
                    v-if="
                        templateForm.frequency === 'monthly' || templateForm.frequency === 'yearly'
                    "
                    class="flex flex-wrap items-end gap-3"
                >
                    <div v-if="templateForm.frequency === 'yearly'">
                        <label class="field-label" for="tpl-month">Month</label>
                        <input
                            id="tpl-month"
                            v-model.number="templateForm.year_month"
                            type="number"
                            min="1"
                            max="12"
                            class="input mt-1 w-auto"
                        />
                    </div>
                    <div v-if="!templateForm.last_day">
                        <label class="field-label" for="tpl-day">Day</label>
                        <input
                            id="tpl-day"
                            v-model.number="templateForm.month_day"
                            type="number"
                            min="1"
                            :max="templateForm.frequency === 'monthly' ? 28 : 31"
                            class="input mt-1 w-auto"
                        />
                    </div>
                    <label class="inline-flex items-center gap-1"
                        ><input v-model="templateForm.last_day" type="checkbox" /> Last day of the
                        month</label
                    >
                </div>
                <fieldset>
                    <legend class="field-label">Applies to staff types</legend>
                    <label class="mr-3 inline-flex items-center gap-1"
                        ><input v-model="templateForm.applies_teaching" type="checkbox" />
                        Teaching</label
                    >
                    <label class="mr-3 inline-flex items-center gap-1"
                        ><input v-model="templateForm.applies_non_teaching" type="checkbox" />
                        Non-teaching</label
                    >
                    <label class="inline-flex items-center gap-1"
                        ><input v-model="templateForm.applies_outsourced" type="checkbox" />
                        Outsourced</label
                    >
                </fieldset>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="field-label" for="tpl-department"
                            >Only this department (optional)</label
                        >
                        <select
                            id="tpl-department"
                            v-model="templateForm.department_id"
                            class="input mt-1"
                        >
                            <option value="">Any department</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">
                                {{ d.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="tpl-position"
                            >Only this position (optional)</label
                        >
                        <select
                            id="tpl-position"
                            v-model="templateForm.position_id"
                            class="input mt-1"
                        >
                            <option value="">Any position</option>
                            <option v-for="p in positions" :key="p.id" :value="p.id">
                                {{ p.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="tpl-from">Takes effect</label
                        ><input
                            id="tpl-from"
                            v-model="templateForm.effective_from"
                            type="date"
                            :min="today"
                            class="input mt-1"
                        />
                    </div>
                </div>
                <p class="field-hint">
                    Every condition you set must match. A person without a staff type never matches.
                </p>
                <p
                    v-for="(message, key) in templateForm.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ message }}
                </p>
                <button type="submit" class="btn btn-primary" :disabled="templateForm.processing">
                    Add recurring task
                </button>
            </form>
        </section>
    </main>
</template>
