<script setup lang="ts">
// SIS.EXP-3: one Student's record documents. Staff only, behind current MFA
// (the files are Highly Sensitive). The server decides every permission;
// this page never builds a storage URL -- a download is an authorized
// request to the server, which re-checks authority each time.
// SIS 11.5: the requirement checklist is advisory (never a gate); verifying
// or rejecting needs its own permission and a fresh code; a rejected or
// outdated document is fixed by uploading a replacement.
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';
import Pagination from '../../../Components/Pagination.vue';
import { formatCalendarDate, formatDateTime } from '../../../datetime';

interface DocumentRow {
    id: string;
    category: string;
    categoryLabel: string;
    title: string;
    documentDate: string | null;
    uploadedAt: string;
    version: number;
    file: { name: string; mimeType: string; sizeBytes: number } | null;
    archived: { at: string; reason: string | null; reasonLabel: string | null } | null;
    verification: {
        status: string;
        label: string;
        reason: string | null;
        reasonLabel: string | null;
        at: string | null;
    } | null;
    fromAdmission: boolean;
}

interface ChecklistItem {
    category: string;
    label: string;
    required: boolean;
    state: string;
    stateLabel: string;
}

interface Props {
    student: { id: string; name: string; studentNumber: string; status: string };
    documents: {
        data: DocumentRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    compliance: ChecklistItem[];
    complianceSummary: {
        required: number;
        verified: number;
        missing: number;
        pending: number;
        rejected: number;
    };
    filters: { category: string; status: string; sort: string };
    options: {
        categories: { value: string; label: string; description: string }[];
        filterCategories: { value: string; label: string }[];
        archiveReasons: { value: string; label: string }[];
        rejectionReasons: { value: string; label: string }[];
        requirable: string[];
    };
    canManage: boolean;
    canVerify: boolean;
    hasMfaFactor: boolean;
    formKey: string;
    upload: { maxMb: number; accept: string };
}

const props = defineProps<Props>();

const base = `/app/students/${props.student.id}/documents`;
const filters = ref({ ...props.filters });

function applyFilters(): void {
    const query = Object.fromEntries(
        Object.entries(filters.value).filter(
            ([k, v]) =>
                v !== '' &&
                !(k === 'status' && v === 'active') &&
                !(k === 'sort' && v === 'newest'),
        ),
    );
    router.get(base, query, { preserveScroll: true, replace: true });
}

const uploadForm = useForm<{
    category: string;
    title: string;
    document_date: string;
    file: File | null;
    form_key: string;
    replaces: string;
    mfa_code: string;
}>({
    category: '',
    title: '',
    document_date: '',
    file: null,
    form_key: props.formKey,
    replaces: '',
    mfa_code: '',
});

const replacing = ref<DocumentRow | null>(null);

function startReplace(d: DocumentRow): void {
    replacing.value = d;
    uploadForm.category = d.category;
    uploadForm.replaces = d.id;
    document.getElementById('upload-heading')?.scrollIntoView();
    document.getElementById('upload-title')?.focus();
}

function cancelReplace(): void {
    replacing.value = null;
    uploadForm.replaces = '';
}
const fileInput = ref<HTMLInputElement | null>(null);

function onFile(event: Event): void {
    uploadForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitUpload(): void {
    uploadForm.post(base, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            replacing.value = null;
            uploadForm.form_key = props.formKey;
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
        onFinish: () => {
            uploadForm.mfa_code = '';
        },
    });
}

const editing = ref<string | null>(null);
const editForm = useForm({ category: '', title: '', document_date: '', version: 0, mfa_code: '' });

function startEdit(d: DocumentRow): void {
    archiving.value = null;
    deciding.value = null;
    editing.value = d.id;
    editForm.defaults({
        category: d.category,
        title: d.title,
        document_date: d.documentDate ?? '',
        version: d.version,
        mfa_code: '',
    });
    editForm.reset();
    editForm.clearErrors();
}

function submitEdit(d: DocumentRow): void {
    editForm.put(`${base}/${d.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
        onFinish: () => (editForm.mfa_code = ''),
    });
}

const archiving = ref<string | null>(null);
const archiveForm = useForm({ reason: '', version: 0, mfa_code: '' });

function startArchive(d: DocumentRow): void {
    editing.value = null;
    deciding.value = null;
    archiving.value = d.id;
    archiveForm.defaults({ reason: '', version: d.version, mfa_code: '' });
    archiveForm.reset();
    archiveForm.clearErrors();
}

function submitArchive(d: DocumentRow): void {
    archiveForm.post(`${base}/${d.id}/archive`, {
        preserveScroll: true,
        onSuccess: () => (archiving.value = null),
        onFinish: () => (archiveForm.mfa_code = ''),
    });
}

const deciding = ref<string | null>(null);
const decisionForm = useForm({ decision: 'verified', reason: '', version: 0, mfa_code: '' });

function decidable(d: DocumentRow): boolean {
    return (
        props.canVerify &&
        !d.archived &&
        props.options.requirable.includes(d.category) &&
        (d.verification === null || d.verification.status === 'pending')
    );
}

function startDecision(d: DocumentRow): void {
    editing.value = null;
    archiving.value = null;
    deciding.value = d.id;
    decisionForm.defaults({ decision: 'verified', reason: '', version: d.version, mfa_code: '' });
    decisionForm.reset();
    decisionForm.clearErrors();
}

function submitDecision(d: DocumentRow): void {
    decisionForm
        .transform((data) => ({
            ...data,
            reason: data.decision === 'rejected' ? data.reason : null,
        }))
        .post(`${base}/${d.id}/decision`, {
            preserveScroll: true,
            onSuccess: () => (deciding.value = null),
            onFinish: () => (decisionForm.mfa_code = ''),
        });
}

const stateClass: Record<string, string> = {
    verified: 'text-green-800',
    pending: 'text-amber-800',
    rejected: 'text-red-800',
    missing: 'text-red-800',
};

function size(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function fileType(mime: string): string {
    return { 'application/pdf': 'PDF', 'image/jpeg': 'JPEG', 'image/png': 'PNG' }[mime] ?? mime;
}

const selectedCategory = () =>
    props.options.categories.find((c) => c.value === uploadForm.category)?.description ?? '';
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← {{ student.name }}</a>
        <h1 class="mt-2 text-xl font-semibold">Documents</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ student.name }} · Student number {{ student.studentNumber }}
        </p>
        <p class="mt-2 max-w-3xl text-sm text-slate-500">
            Record documents for this Student. They are highly sensitive: only staff with document
            access can see them, and each download is recorded. Do not upload government identity
            documents, medical, caste, religion or disability certificates, or photographs.
        </p>

        <!-- SIS 11.5: requirement checklist (advisory) -->
        <section
            v-if="compliance.length > 0"
            class="mt-6 max-w-3xl rounded border border-slate-200 p-4"
            aria-labelledby="checklist-heading"
        >
            <h2 id="checklist-heading" class="text-base font-semibold">Document checklist</h2>
            <p class="mt-1 text-sm text-slate-600">
                {{ complianceSummary.verified }} of {{ complianceSummary.required }} required
                verified<template v-if="complianceSummary.missing > 0">
                    · {{ complianceSummary.missing }} missing</template
                ><template v-if="complianceSummary.pending > 0">
                    · {{ complianceSummary.pending }} pending verification</template
                ><template v-if="complianceSummary.rejected > 0">
                    · {{ complianceSummary.rejected }} rejected</template
                >. The checklist is guidance; it never blocks enrollment.
                <a v-if="canManage" class="underline" href="/app/students/document-requirements"
                    >Document requirements</a
                >
            </p>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                <li
                    v-for="item in compliance"
                    :key="item.category"
                    class="flex flex-wrap justify-between gap-2 py-2"
                >
                    <span
                        >{{ item.label }}
                        <span class="text-xs text-slate-500">{{
                            item.required ? 'Required' : 'Optional'
                        }}</span></span
                    >
                    <span :class="stateClass[item.state]" class="font-medium">{{
                        item.stateLabel
                    }}</span>
                </li>
            </ul>
        </section>

        <!-- Filters -->
        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="doc-filter-category"
                    >Category</label
                >
                <select
                    id="doc-filter-category"
                    v-model="filters.category"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="c in options.filterCategories" :key="c.value" :value="c.value">
                        {{ c.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="doc-filter-status">Status</label>
                <select
                    id="doc-filter-status"
                    v-model="filters.status"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                    <option value="all">All</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="doc-filter-sort">Sort by</label>
                <select
                    id="doc-filter-sort"
                    v-model="filters.sort"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="newest">Newest uploaded</option>
                    <option value="document_date">Document date</option>
                    <option value="category">Category</option>
                    <option value="title">Title</option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="documents.data.length === 0"
            class="mt-6"
            :title="filters.status === 'archived' ? 'No archived documents' : 'No documents yet'"
            :description="
                canManage
                    ? 'Upload a document below.'
                    : 'Documents appear here when a staff member uploads them.'
            "
        />

        <template v-else>
            <p class="mt-6 text-sm text-slate-500">
                {{ documents.from }}–{{ documents.to }} of {{ documents.total }}
            </p>
            <div class="relative mt-2 overflow-x-auto rounded border border-slate-200">
                <table class="min-w-[48rem] w-full divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        Documents for
                        {{
                            student.name
                        }}
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Document</th>
                            <th scope="col" class="px-3 py-2 font-medium">Document date</th>
                            <th scope="col" class="px-3 py-2 font-medium">Uploaded</th>
                            <th scope="col" class="px-3 py-2 font-medium">File</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <template v-for="d in documents.data" :key="d.id">
                            <tr>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ d.title }}</span>
                                    <span class="block text-xs text-slate-500">{{
                                        d.categoryLabel
                                    }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    {{ d.documentDate ? formatCalendarDate(d.documentDate) : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    {{ formatDateTime(d.uploadedAt) }}
                                </td>
                                <td class="px-3 py-2">
                                    <template v-if="d.file">
                                        <span class="break-all">{{ d.file.name }}</span>
                                        <span class="block text-xs text-slate-500"
                                            >{{ fileType(d.file.mimeType) }} ·
                                            {{ size(d.file.sizeBytes) }}</span
                                        >
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-3 py-2">
                                    <span v-if="d.archived"
                                        >Archived<span class="block text-xs text-slate-500">{{
                                            d.archived.reasonLabel
                                        }}</span></span
                                    >
                                    <span v-else>Active</span>
                                    <span
                                        v-if="d.verification"
                                        class="block text-xs"
                                        :class="stateClass[d.verification.status]"
                                        >{{ d.verification.label
                                        }}<template v-if="d.verification.reasonLabel">
                                            — {{ d.verification.reasonLabel }}</template
                                        ></span
                                    >
                                    <span
                                        v-if="d.fromAdmission"
                                        class="block text-xs text-slate-500"
                                        >From the admission application</span
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <a class="underline" :href="`${base}/${d.id}/download`"
                                        >Download<span class="sr-only"> {{ d.title }}</span></a
                                    >
                                    <template
                                        v-if="
                                            canManage &&
                                            !d.archived &&
                                            d.category !== 'issued_certificate'
                                        "
                                    >
                                        <button
                                            type="button"
                                            class="ml-3 underline"
                                            :aria-expanded="editing === d.id"
                                            @click="startEdit(d)"
                                        >
                                            Edit<span class="sr-only"> {{ d.title }}</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 underline"
                                            @click="startReplace(d)"
                                        >
                                            Replace<span class="sr-only"> {{ d.title }}</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 underline"
                                            :aria-expanded="archiving === d.id"
                                            @click="startArchive(d)"
                                        >
                                            Archive<span class="sr-only"> {{ d.title }}</span>
                                        </button>
                                    </template>
                                    <button
                                        v-if="decidable(d)"
                                        type="button"
                                        class="ml-3 underline"
                                        :aria-expanded="deciding === d.id"
                                        @click="startDecision(d)"
                                    >
                                        Verify or reject<span class="sr-only"> {{ d.title }}</span>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="editing === d.id">
                                <td colspan="6" class="bg-slate-50 px-3 py-3">
                                    <form
                                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                                        :aria-label="`Edit ${d.title}`"
                                        @submit.prevent="submitEdit(d)"
                                    >
                                        <div>
                                            <label
                                                class="block text-sm"
                                                :for="`edit-category-${d.id}`"
                                                >Category</label
                                            >
                                            <select
                                                :id="`edit-category-${d.id}`"
                                                v-model="editForm.category"
                                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                                :aria-invalid="!!editForm.errors.category"
                                                :aria-describedby="
                                                    editForm.errors.category
                                                        ? `edit-category-error-${d.id}`
                                                        : undefined
                                                "
                                            >
                                                <option
                                                    v-for="c in options.categories"
                                                    :key="c.value"
                                                    :value="c.value"
                                                >
                                                    {{ c.label }}
                                                </option>
                                            </select>
                                            <p
                                                v-if="editForm.errors.category"
                                                :id="`edit-category-error-${d.id}`"
                                                class="mt-1 text-sm text-red-700"
                                            >
                                                {{ editForm.errors.category }}
                                            </p>
                                        </div>
                                        <div>
                                            <label class="block text-sm" :for="`edit-title-${d.id}`"
                                                >Title</label
                                            >
                                            <input
                                                :id="`edit-title-${d.id}`"
                                                v-model="editForm.title"
                                                maxlength="120"
                                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                                :aria-invalid="!!editForm.errors.title"
                                                :aria-describedby="
                                                    editForm.errors.title
                                                        ? `edit-title-error-${d.id}`
                                                        : undefined
                                                "
                                            />
                                            <p
                                                v-if="editForm.errors.title"
                                                :id="`edit-title-error-${d.id}`"
                                                class="mt-1 text-sm text-red-700"
                                            >
                                                {{ editForm.errors.title }}
                                            </p>
                                        </div>
                                        <div>
                                            <label class="block text-sm" :for="`edit-date-${d.id}`"
                                                >Document date (optional)</label
                                            >
                                            <input
                                                :id="`edit-date-${d.id}`"
                                                v-model="editForm.document_date"
                                                type="date"
                                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                                :aria-invalid="!!editForm.errors.document_date"
                                                :aria-describedby="
                                                    editForm.errors.document_date
                                                        ? `edit-date-error-${d.id}`
                                                        : undefined
                                                "
                                            />
                                            <p
                                                v-if="editForm.errors.document_date"
                                                :id="`edit-date-error-${d.id}`"
                                                class="mt-1 text-sm text-red-700"
                                            >
                                                {{ editForm.errors.document_date }}
                                            </p>
                                        </div>
                                        <MfaCodeField
                                            :id="`edit-mfa-${d.id}`"
                                            v-model="editForm.mfa_code"
                                            :has-mfa-factor="hasMfaFactor"
                                            action="Changing a document's details"
                                            :error="editForm.errors.mfa_code"
                                        />
                                        <div
                                            class="flex items-end gap-3 sm:col-span-2 lg:col-span-4"
                                        >
                                            <button
                                                type="submit"
                                                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                                :disabled="editForm.processing || !hasMfaFactor"
                                            >
                                                Save details
                                            </button>
                                            <button
                                                type="button"
                                                class="text-sm underline"
                                                @click="editing = null"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <tr v-if="deciding === d.id">
                                <td colspan="6" class="bg-slate-50 px-3 py-3">
                                    <form
                                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                                        :aria-label="`Verify or reject ${d.title}`"
                                        @submit.prevent="submitDecision(d)"
                                    >
                                        <p
                                            class="text-sm text-slate-600 sm:col-span-2 lg:col-span-4"
                                        >
                                            Check the file against the original before deciding. A
                                            decision is final; a rejected document is fixed by
                                            uploading a replacement.
                                        </p>
                                        <fieldset>
                                            <legend class="text-sm">Decision</legend>
                                            <label class="mt-1 flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="decisionForm.decision"
                                                    type="radio"
                                                    value="verified"
                                                />
                                                Verified
                                            </label>
                                            <label class="mt-1 flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="decisionForm.decision"
                                                    type="radio"
                                                    value="rejected"
                                                />
                                                Rejected
                                            </label>
                                        </fieldset>
                                        <div v-if="decisionForm.decision === 'rejected'">
                                            <label
                                                class="block text-sm"
                                                :for="`decision-reason-${d.id}`"
                                                >Reason</label
                                            >
                                            <select
                                                :id="`decision-reason-${d.id}`"
                                                v-model="decisionForm.reason"
                                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                                :aria-invalid="!!decisionForm.errors.reason"
                                                :aria-describedby="
                                                    decisionForm.errors.reason
                                                        ? `decision-reason-error-${d.id}`
                                                        : undefined
                                                "
                                            >
                                                <option value="" disabled>Choose a reason</option>
                                                <option
                                                    v-for="r in options.rejectionReasons"
                                                    :key="r.value"
                                                    :value="r.value"
                                                >
                                                    {{ r.label }}
                                                </option>
                                            </select>
                                            <p
                                                v-if="decisionForm.errors.reason"
                                                :id="`decision-reason-error-${d.id}`"
                                                class="mt-1 text-sm text-red-700"
                                            >
                                                {{ decisionForm.errors.reason }}
                                            </p>
                                        </div>
                                        <MfaCodeField
                                            :id="`decision-mfa-${d.id}`"
                                            v-model="decisionForm.mfa_code"
                                            :has-mfa-factor="hasMfaFactor"
                                            action="Verifying or rejecting a document"
                                            :error="decisionForm.errors.mfa_code"
                                        />
                                        <div
                                            class="flex items-end gap-3 sm:col-span-2 lg:col-span-4"
                                        >
                                            <button
                                                type="submit"
                                                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                                :disabled="decisionForm.processing || !hasMfaFactor"
                                            >
                                                Record decision
                                            </button>
                                            <button
                                                type="button"
                                                class="text-sm underline"
                                                @click="deciding = null"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <tr v-if="archiving === d.id">
                                <td colspan="6" class="bg-slate-50 px-3 py-3">
                                    <form
                                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                                        :aria-label="`Archive ${d.title}`"
                                        @submit.prevent="submitArchive(d)"
                                    >
                                        <p
                                            class="text-sm text-slate-600 sm:col-span-2 lg:col-span-4"
                                        >
                                            Archiving is final. The file stays on record and can
                                            still be downloaded, but it can no longer be edited.
                                        </p>
                                        <div>
                                            <label
                                                class="block text-sm"
                                                :for="`archive-reason-${d.id}`"
                                                >Reason</label
                                            >
                                            <select
                                                :id="`archive-reason-${d.id}`"
                                                v-model="archiveForm.reason"
                                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                                :aria-invalid="!!archiveForm.errors.reason"
                                                :aria-describedby="
                                                    archiveForm.errors.reason
                                                        ? `archive-reason-error-${d.id}`
                                                        : undefined
                                                "
                                            >
                                                <option value="" disabled>Choose a reason</option>
                                                <option
                                                    v-for="r in options.archiveReasons"
                                                    :key="r.value"
                                                    :value="r.value"
                                                >
                                                    {{ r.label }}
                                                </option>
                                            </select>
                                            <p
                                                v-if="archiveForm.errors.reason"
                                                :id="`archive-reason-error-${d.id}`"
                                                class="mt-1 text-sm text-red-700"
                                            >
                                                {{ archiveForm.errors.reason }}
                                            </p>
                                        </div>
                                        <MfaCodeField
                                            :id="`archive-mfa-${d.id}`"
                                            v-model="archiveForm.mfa_code"
                                            :has-mfa-factor="hasMfaFactor"
                                            action="Archiving a document"
                                            :error="archiveForm.errors.mfa_code"
                                        />
                                        <div
                                            class="flex items-end gap-3 sm:col-span-2 lg:col-span-4"
                                        >
                                            <button
                                                type="submit"
                                                class="rounded bg-red-800 px-3 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                                                :disabled="archiveForm.processing || !hasMfaFactor"
                                            >
                                                Archive document
                                            </button>
                                            <button
                                                type="button"
                                                class="text-sm underline"
                                                @click="archiving = null"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <Pagination class="mt-4" :links="documents.links" />
        </template>

        <!-- Upload -->
        <section v-if="canManage" class="mt-10" aria-labelledby="upload-heading">
            <h2 id="upload-heading" class="text-base font-semibold">Upload a document</h2>
            <p class="mt-1 text-sm text-slate-500">
                PDF, JPEG or PNG, up to {{ upload.maxMb }} MB. Files are not scanned for malware, so
                only upload files you trust.
            </p>
            <p
                v-if="replacing"
                class="mt-2 flex flex-wrap items-center gap-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900"
                role="status"
            >
                Replacing “{{ replacing.title }}”. The current document is archived as superseded
                when the new one is uploaded.
                <button type="button" class="underline" @click="cancelReplace">
                    Cancel replacement
                </button>
            </p>
            <form class="mt-4 grid max-w-3xl gap-4 sm:grid-cols-2" @submit.prevent="submitUpload">
                <div>
                    <label class="block text-sm text-slate-700" for="upload-category"
                        >Category</label
                    >
                    <select
                        id="upload-category"
                        v-model="uploadForm.category"
                        :disabled="replacing !== null"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!uploadForm.errors.category"
                        aria-describedby="upload-category-help upload-category-error"
                    >
                        <option value="" disabled>Choose a category</option>
                        <option v-for="c in options.categories" :key="c.value" :value="c.value">
                            {{ c.label }}
                        </option>
                    </select>
                    <p id="upload-category-help" class="mt-1 text-xs text-slate-500">
                        {{ selectedCategory() }}
                    </p>
                    <p
                        v-if="uploadForm.errors.category"
                        id="upload-category-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ uploadForm.errors.category }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="upload-title">Title</label>
                    <input
                        id="upload-title"
                        v-model="uploadForm.title"
                        maxlength="120"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!uploadForm.errors.title"
                        :aria-describedby="
                            uploadForm.errors.title ? 'upload-title-error' : undefined
                        "
                    />
                    <p
                        v-if="uploadForm.errors.title"
                        id="upload-title-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ uploadForm.errors.title }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="upload-date"
                        >Document date (optional)</label
                    >
                    <input
                        id="upload-date"
                        v-model="uploadForm.document_date"
                        type="date"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!uploadForm.errors.document_date"
                        :aria-describedby="
                            uploadForm.errors.document_date ? 'upload-date-error' : undefined
                        "
                    />
                    <p
                        v-if="uploadForm.errors.document_date"
                        id="upload-date-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ uploadForm.errors.document_date }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="upload-file">File</label>
                    <input
                        id="upload-file"
                        ref="fileInput"
                        type="file"
                        :accept="upload.accept"
                        class="mt-1 block w-full text-sm"
                        :aria-invalid="!!uploadForm.errors.file"
                        :aria-describedby="uploadForm.errors.file ? 'upload-file-error' : undefined"
                        @change="onFile"
                    />
                    <p
                        v-if="uploadForm.errors.file"
                        id="upload-file-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ uploadForm.errors.file }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <MfaCodeField
                        id="upload-mfa"
                        v-model="uploadForm.mfa_code"
                        :has-mfa-factor="hasMfaFactor"
                        action="Uploading a document"
                        :error="uploadForm.errors.mfa_code"
                    />
                </div>
                <p v-if="uploadForm.errors.form_key" class="text-sm text-red-700 sm:col-span-2">
                    {{ uploadForm.errors.form_key }}
                </p>
                <p v-if="uploadForm.errors.replaces" class="text-sm text-red-700 sm:col-span-2">
                    {{ uploadForm.errors.replaces }}
                </p>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="uploadForm.processing || !hasMfaFactor"
                    >
                        Upload document
                    </button>
                </div>
            </form>
        </section>
    </main>
</template>
