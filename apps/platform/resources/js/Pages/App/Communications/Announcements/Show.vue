<script setup lang="ts">
import { formatMoney } from '../../../../money';
import ErrorSummary from '../../../../Components/Form/ErrorSummary.vue';
import { formatDateTime } from '../../../../datetime';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';

interface AnnouncementDetail {
    id: string;
    title: string;
    body: string;
    createdByName: string | null;
    audienceType: string;
    recipientCount: number | null;
    status: string;
    priority: string;
    requirement: string;
    dispatchMode: string;
    emergencyJustification: string | null;
    scheduledAt: string | null;
    sourceTemplateId: string | null;
    publishedAt: string | null;
    createdAt: string | null;
}

interface EmailEligibility {
    eligible: number;
    missing: number;
    policySuppressed: number;
}

interface DomainAudiencePreview {
    studentCount: number;
    guardianCount: number;
    inAppReachable: number;
    guardianEmailEligible: number;
    guardianEmailUnavailable: number;
}

interface AcademicCohortPreview {
    cohortType: string;
    gradeLevelName: string | null;
    sectionName: string | null;
    subjectOfferingLabel: string | null;
    academicYearLabel: string | null;
    recipientKind: string;
    isDynamic: boolean;
}

// ORG.9D-2 (ADR 0076 §74): read under the preparer's own Finance authority,
// so the server sends these figures to the preparer only.
interface FeeReminderPreview {
    students: number;
    studentsWithoutRecipient: number;
    totalOutstanding: string;
    currency: string;
    asOf: string;
}

interface FeeReminderDetail {
    labels: { status: string; academicYear: string; campus: string; class: string };
    error: string | null;
}

interface AudiencePreview {
    count: number;
    categoryBreakdown: Record<string, number>;
    email: EmailEligibility | null;
    domain: DomainAudiencePreview | null;
    academicCohort: AcademicCohortPreview | null;
    feeReminder: FeeReminderPreview | null;
}

interface ChannelDeliveryRow {
    status: string;
    failureCode: string | null;
    count: number;
}

interface AttachmentSummary {
    id: string;
    displayName: string;
    mimeType: string;
    sizeBytes: number;
    createdAt: string | null;
}

interface ApprovalRequirement {
    required: boolean;
    reasons: string[];
}

interface LatestApprovalRequest {
    id: string;
    status: string;
    requestedByName: string | null;
    requestedAt: string;
    decidedByName: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
}

const UNAVAILABLE_FAILURE_CODES = [
    'recipient_email_missing',
    'recipient_email_invalid',
    'recipient_ineligible',
    'email_channel_disabled',
];

interface Props {
    announcement: AnnouncementDetail;
    // ORG.9D-1R1: false for a recipient-only reader -- the School
    // announcement list is a Hub (communications.view) page.
    canViewHub: boolean;
    requestedChannels: string[];
    emailChannelEnabled: boolean;
    schoolTimezone: string;
    preview: AudiencePreview | null;
    channelDeliverySummary: Record<string, ChannelDeliveryRow[]> | null;
    canEdit: boolean;
    canSchedule: boolean;
    canCancel: boolean;
    canAnnounce: boolean;
    attachments: AttachmentSummary[];
    canManageAttachments: boolean;
    canViewAudit: boolean;
    canViewAnalytics: boolean;
    // ORG.9D-1R1: null on the recipient view (no approval history shown).
    approvalRequirement: ApprovalRequirement | null;
    latestApprovalRequest: LatestApprovalRequest | null;
    canSubmitForApproval: boolean;
    canWithdrawApproval: boolean;
    canApprove: boolean;
    hasMfaFactor: boolean;
    feeReminder: FeeReminderDetail | null;
}

const props = defineProps<Props>();

function formatInSchoolTimezone(iso: string): string {
    try {
        return new Intl.DateTimeFormat(undefined, {
            timeZone: props.schoolTimezone,
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

const channelLabels: Record<string, string> = { in_app: 'In-app', email: 'Email' };

// Excludes 'suppressed' rows -- brief §21: a suppression was never an
// attempted delivery, so it must not inflate the "X / Y attempted"
// denominator (in_app can suppress a fully ineligible recipient, e.g.
// one whose membership lapsed between publish and this view).
function channelTotal(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter((row) => row.status !== 'suppressed')
        .reduce((sum, row) => sum + row.count, 0);
}

function channelSucceeded(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter((row) => row.status === 'delivered' || row.status === 'sent')
        .reduce((sum, row) => sum + row.count, 0);
}

function channelUnavailable(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter(
            (row) =>
                row.status === 'failed' &&
                row.failureCode !== null &&
                UNAVAILABLE_FAILURE_CODES.includes(row.failureCode),
        )
        .reduce((sum, row) => sum + row.count, 0);
}

function channelFailed(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter(
            (row) =>
                row.status === 'failed' &&
                (row.failureCode === null || !UNAVAILABLE_FAILURE_CODES.includes(row.failureCode)),
        )
        .reduce((sum, row) => sum + row.count, 0);
}

function channelPending(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter((row) => ['pending', 'queued', 'sending'].includes(row.status))
        .reduce((sum, row) => sum + row.count, 0);
}

// Brief §21: a policy SUPPRESSION is never attempted delivery -- kept
// as its own bucket, distinct from "failed" and "unavailable" (both of
// which mean a real send was attempted).
function channelSuppressed(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter((row) => row.status === 'suppressed')
        .reduce((sum, row) => sum + row.count, 0);
}
const publishing = ref(false);
const cancelling = ref(false);
const scheduling = ref(false);
const scheduledAtInput = ref('');
// Phase 5A.10 §22/§32: a deliberate, SEPARATE re-confirmation right
// before an Emergency publish -- distinct from the composer-time
// acknowledgement already captured when Emergency was first declared.
const publishAcknowledged = ref(false);
// SR.4 (ADR 0071 §26.7): an Emergency publish also needs a fresh code.
const emergencyMfaCode = ref('');
const page = usePage();
const pageErrors = computed(() => page.props.errors as Record<string, string | undefined>);
const emergencyMfaError = computed(
    () => (page.props.errors as Record<string, string>).mfa_code ?? null,
);

function publish() {
    publishing.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/publish`,
        props.announcement.dispatchMode === 'emergency'
            ? { acknowledged: publishAcknowledged.value, mfa_code: emergencyMfaCode.value }
            : {},
        {
            onFinish: () => {
                publishing.value = false;
                emergencyMfaCode.value = '';
            },
        },
    );
}

const submittingApproval = ref(false);
const withdrawingApproval = ref(false);

const approvalReasonLabels: Record<string, string> = {
    school_wide: 'School-wide or Staff audience',
    fee_reminder: 'Fee reminder (always reviewed)',
    required_communication: 'Marked Required',
    non_privileged_sender: 'Sender requires review',
};

function submitForApproval() {
    submittingApproval.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/submit-approval`,
        {},
        { onFinish: () => (submittingApproval.value = false) },
    );
}

function withdrawApproval() {
    withdrawingApproval.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/withdraw-approval`,
        {},
        { onFinish: () => (withdrawingApproval.value = false) },
    );
}

function cancelAnnouncement() {
    cancelling.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/cancel`,
        {},
        { onFinish: () => (cancelling.value = false) },
    );
}

function submitSchedule() {
    if (!scheduledAtInput.value) {
        return;
    }
    scheduling.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/schedule`,
        { scheduled_at: scheduledAtInput.value },
        { onFinish: () => (scheduling.value = false) },
    );
}

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const removingAttachmentId = ref<string | null>(null);

// Phase 5A.6 §46: Inertia's router.post() detects a FormData payload
// and switches to a multipart request automatically -- no separate
// AJAX/fetch endpoint is needed for this upload widget.
function uploadAttachment(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
        return;
    }

    const formData = new FormData();
    formData.append('file', file);

    uploading.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/attachments`,
        formData,
        {
            forceFormData: true,
            onFinish: () => {
                uploading.value = false;
                if (fileInput.value) {
                    fileInput.value.value = '';
                }
            },
        },
    );
}

function removeAttachment(attachmentId: string) {
    removingAttachmentId.value = attachmentId;
    router.delete(
        `/app/communications/announcements/${props.announcement.id}/attachments/${attachmentId}`,
        { onFinish: () => (removingAttachmentId.value = null) },
    );
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a
            v-if="canViewHub"
            class="text-xs text-ink-muted underline"
            href="/app/communications/announcements"
            >← Announcements</a
        >
        <a v-else class="text-xs text-ink-muted underline" href="/app/communications">← Inbox</a>

        <div class="mt-2 flex items-center gap-2">
            <h1 class="text-xl font-semibold">{{ announcement.title }}</h1>
            <span
                class="rounded-xs px-1.5 py-0.5 text-xs font-medium"
                :class="{
                    'tag-neutral': announcement.status === 'draft',
                    'tag-info':
                        announcement.status === 'pending_approval' ||
                        announcement.status === 'scheduled',
                    'tag-positive':
                        announcement.status === 'approved' || announcement.status === 'published',
                    'tag-negative': announcement.status === 'rejected',
                    'tag-attention': announcement.status === 'cancelled',
                }"
            >
                {{ announcement.status.replace('_', ' ') }}
            </span>
            <span
                v-if="announcement.dispatchMode === 'emergency'"
                class="rounded-xs bg-danger px-1.5 py-0.5 text-xs font-semibold text-white"
            >
                ⚠ emergency
            </span>
            <span
                v-if="announcement.requirement === 'required'"
                class="rounded-xs bg-accent-100 px-1.5 py-0.5 text-xs font-medium text-accent-strong"
            >
                required
            </span>
        </div>

        <div
            v-if="announcement.emergencyJustification"
            class="mt-3 rounded-xs border border-danger/40 bg-danger-tint p-3 text-xs text-danger"
        >
            <p class="font-medium">Internal justification (restricted)</p>
            <p class="mt-1 whitespace-pre-wrap">{{ announcement.emergencyJustification }}</p>
        </div>

        <div
            v-if="approvalRequirement?.required || latestApprovalRequest"
            class="mt-3 rounded-xs border border-line bg-graphite-100 p-3 text-xs"
        >
            <p
                v-if="announcement.status === 'pending_approval'"
                class="font-medium text-accent-strong"
            >
                Pending approval
                <template v-if="latestApprovalRequest">
                    · submitted by
                    {{ latestApprovalRequest.requestedByName ?? 'Unknown' }}</template
                >
            </p>
            <p v-else-if="announcement.status === 'approved'" class="font-medium text-ink">
                Approved
                <template v-if="latestApprovalRequest?.decidedByName">
                    · by {{ latestApprovalRequest.decidedByName }}</template
                >
            </p>
            <p v-else-if="announcement.status === 'rejected'" class="font-medium text-danger">
                Rejected
                <template v-if="latestApprovalRequest?.decidedByName">
                    · by {{ latestApprovalRequest.decidedByName }}</template
                >
            </p>
            <p v-else class="font-medium text-ink-muted">Approval required before publishing</p>

            <p
                v-if="announcement.status === 'rejected' && latestApprovalRequest?.decisionNote"
                class="mt-1 text-danger"
            >
                Reason: {{ latestApprovalRequest.decisionNote }}
            </p>

            <p
                v-if="approvalRequirement && approvalRequirement.reasons.length > 0"
                class="mt-1 text-ink-muted"
            >
                Required because:
                {{
                    approvalRequirement.reasons.map((r) => approvalReasonLabels[r] ?? r).join(', ')
                }}
            </p>

            <div class="mt-2 flex gap-3">
                <button
                    v-if="canSubmitForApproval"
                    type="button"
                    :disabled="submittingApproval"
                    class="btn btn-primary text-xs"
                    @click="submitForApproval"
                >
                    Submit for approval
                </button>
                <button
                    v-if="canWithdrawApproval"
                    type="button"
                    :disabled="withdrawingApproval"
                    class="btn btn-secondary text-xs text-ink-muted"
                    @click="withdrawApproval"
                >
                    Withdraw and edit
                </button>
                <a
                    v-if="canApprove && latestApprovalRequest"
                    class="rounded-xs border border-accent-300 px-3 py-1.5 text-xs font-medium text-accent-strong"
                    :href="`/app/communications/approvals/${latestApprovalRequest.id}`"
                >
                    Review approval
                </a>
            </div>
        </div>
        <p class="mt-1 text-xs text-ink-muted">
            by {{ announcement.createdByName ?? 'Unknown' }}
            <template v-if="announcement.sourceTemplateId"> · from a template</template>
            <template v-if="announcement.status === 'scheduled' && announcement.scheduledAt">
                · scheduled for {{ formatInSchoolTimezone(announcement.scheduledAt) }} ({{
                    schoolTimezone
                }})</template
            >
            <template v-if="announcement.publishedAt">
                · published {{ formatDateTime(announcement.publishedAt) }}</template
            >
        </p>

        <div class="mt-4 rounded-xs border border-line p-4">
            <p class="text-sm whitespace-pre-wrap">{{ announcement.body }}</p>
        </div>

        <div class="mt-4 rounded-xs border border-line p-4">
            <h2 class="text-sm font-semibold">Attachments</h2>

            <ul v-if="attachments.length > 0" class="mt-2 space-y-1.5">
                <li
                    v-for="attachment in attachments"
                    :key="attachment.id"
                    class="flex items-center justify-between gap-2 text-sm"
                >
                    <a
                        :href="`/app/communications/attachments/${attachment.id}/download`"
                        class="truncate text-ink underline"
                    >
                        {{ attachment.displayName }}
                    </a>
                    <span class="shrink-0 text-xs text-ink-muted">{{
                        formatFileSize(attachment.sizeBytes)
                    }}</span>
                    <button
                        v-if="canManageAttachments"
                        type="button"
                        :disabled="removingAttachmentId === attachment.id"
                        class="shrink-0 text-xs text-danger underline disabled:opacity-50"
                        @click="removeAttachment(attachment.id)"
                    >
                        Remove
                    </button>
                </li>
            </ul>
            <p v-else class="mt-1 text-xs text-ink-muted">No attachments.</p>

            <div v-if="canManageAttachments" class="mt-3 border-t border-line pt-3">
                <input
                    ref="fileInput"
                    type="file"
                    :disabled="uploading"
                    class="text-sm text-ink-muted"
                    @change="uploadAttachment"
                />
                <p class="mt-1 text-xs text-ink-muted">
                    PDF, JPEG, PNG, WEBP, Word, or Excel files only.
                </p>
            </div>
        </div>

        <div class="mt-4 rounded-xs border border-line p-4">
            <h2 class="text-sm font-semibold">Delivery channels</h2>
            <p class="mt-1 text-xs text-ink-muted">
                {{ requestedChannels.map((c) => channelLabels[c] ?? c).join(', ') }}
            </p>
        </div>

        <ErrorSummary :errors="pageErrors" :fields="['audience']" />

        <section
            v-if="feeReminder"
            class="mt-4 rounded-xs border border-line p-4"
            aria-labelledby="fee-reminder-selection"
        >
            <h2 id="fee-reminder-selection" class="text-sm font-semibold">Fee reminder</h2>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                <dt class="text-ink-muted">Who</dt>
                <dd>{{ feeReminder.labels.status }}</dd>
                <dt class="text-ink-muted">Academic year</dt>
                <dd>{{ feeReminder.labels.academicYear }}</dd>
                <dt class="text-ink-muted">Charge campus</dt>
                <dd>{{ feeReminder.labels.campus }}</dd>
                <dt class="text-ink-muted">Class</dt>
                <dd>{{ feeReminder.labels.class }}</dd>
            </dl>
            <p v-if="feeReminder.error" class="mt-2 text-xs text-danger" role="alert">
                {{ feeReminder.error }}
            </p>
            <dl
                v-if="preview?.feeReminder"
                class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-line pt-2 text-xs"
            >
                <dt class="text-ink-muted">Students who owe fees</dt>
                <dd>{{ preview.feeReminder.students }}</dd>
                <dt class="text-ink-muted">Guardian accounts to email</dt>
                <dd>{{ preview.count }}</dd>
                <dt class="text-ink-muted">Students with no guardian account (not emailed)</dt>
                <dd>{{ preview.feeReminder.studentsWithoutRecipient }}</dd>
                <dt class="text-ink-muted">Total outstanding</dt>
                <dd>
                    {{
                        formatMoney(
                            preview.feeReminder.totalOutstanding,
                            preview.feeReminder.currency,
                        )
                    }}
                </dd>
                <dt class="text-ink-muted">As of</dt>
                <dd>{{ preview.feeReminder.asOf }} ({{ schoolTimezone }})</dd>
                <dt class="text-ink-muted">Channel</dt>
                <dd>Email only · approval required</dd>
            </dl>
            <p class="mt-2 text-xs text-ink-muted">
                <template v-if="preview?.feeReminder"
                    >This preview is an estimate. Students, guardians and amounts are read again
                    when the reminder is sent; anyone who has paid by then is left out.</template
                >
                <template v-else-if="announcement.status !== 'published'"
                    >Figures are shown only to the person who prepared this reminder.</template
                >
            </p>
        </section>

        <div v-if="preview" class="mt-4 rounded-xs border border-line p-4">
            <h2 class="text-sm font-semibold">Audience</h2>
            <p class="mt-1 text-xs text-ink-muted">
                {{
                    {
                        school_wide: 'Entire School',
                        staff: 'Staff only (current employees)',
                        individual: 'Selected Members',
                        student: 'Students',
                        guardian: 'Guardians',
                        guardians_of_students: 'Guardians of Selected Students',
                        grade: 'Grade (Academic Cohort)',
                        section: 'Section (Academic Cohort)',
                        subject_offering: 'Subject Offering (Academic Cohort)',
                        fee_reminder: 'Fee reminder (guardians of Students who owe fees)',
                    }[announcement.audienceType] ?? announcement.audienceType
                }}
            </p>

            <div v-if="preview.academicCohort" class="mt-2 rounded-xs bg-graphite-100 p-2 text-xs">
                <p class="text-ink-muted">
                    <span v-if="preview.academicCohort.cohortType === 'grade_level'">
                        Grade: {{ preview.academicCohort.gradeLevelName }}
                    </span>
                    <span v-else-if="preview.academicCohort.cohortType === 'section'">
                        Section: {{ preview.academicCohort.sectionName }}
                    </span>
                    <span v-else>
                        Subject Offering: {{ preview.academicCohort.subjectOfferingLabel }}
                    </span>
                    <span v-if="preview.academicCohort.academicYearLabel">
                        &middot; {{ preview.academicCohort.academicYearLabel }}</span
                    >
                    &middot;
                    {{
                        preview.academicCohort.recipientKind === 'guardian'
                            ? 'Guardians'
                            : 'Students'
                    }}
                </p>
                <p class="mt-1 text-ink-muted">
                    Recipient counts reflect current enrollment and are re-resolved again at
                    publication{{
                        announcement.status === 'scheduled'
                            ? ' (and again at the scheduled send time)'
                            : ''
                    }}
                    -- a Student who joins or leaves this Grade/Section before then changes who
                    actually receives this message.
                </p>
            </div>

            <p class="mt-2 text-sm">Estimated recipients: {{ preview.count }}</p>
            <ul
                v-if="Object.keys(preview.categoryBreakdown).length > 0"
                class="mt-2 space-y-1 text-xs"
            >
                <li
                    v-for="(count, label) in preview.categoryBreakdown"
                    :key="label"
                    class="flex justify-between text-ink-muted"
                >
                    <span>{{ label }}</span>
                    <span>{{ count }}</span>
                </li>
            </ul>

            <div v-if="preview.email" class="mt-3 border-t border-line pt-2 text-xs">
                <div class="flex justify-between text-ink-muted">
                    <span>Email eligible</span>
                    <span>{{ preview.email.eligible }}</span>
                </div>
                <div v-if="preview.email.missing > 0" class="flex justify-between text-ink">
                    <span>Missing email</span>
                    <span>{{ preview.email.missing }}</span>
                </div>
                <div
                    v-if="preview.email.policySuppressed > 0"
                    class="flex justify-between text-ink-muted"
                >
                    <span>Optional email disabled (policy/preference)</span>
                    <span>{{ preview.email.policySuppressed }}</span>
                </div>
            </div>

            <div v-if="preview.domain" class="mt-3 border-t border-line pt-2 text-xs">
                <p class="text-ink-muted">
                    Students/Guardians have a different reachability profile from School members --
                    they have no in-app inbox unless a portal account is linked.
                </p>
                <div class="mt-1 flex justify-between text-ink-muted">
                    <span>In-app reachable</span>
                    <span>{{ preview.domain.inAppReachable }}</span>
                </div>
                <div
                    v-if="preview.domain.guardianCount > 0"
                    class="flex justify-between text-ink-muted"
                >
                    <span>Guardian email eligible</span>
                    <span>{{ preview.domain.guardianEmailEligible }}</span>
                </div>
                <div
                    v-if="preview.domain.guardianEmailUnavailable > 0"
                    class="flex justify-between text-ink"
                >
                    <span>Guardian email unavailable</span>
                    <span>{{ preview.domain.guardianEmailUnavailable }}</span>
                </div>
            </div>
        </div>

        <div
            v-else-if="announcement.recipientCount !== null"
            class="mt-4 rounded-xs border border-line p-4"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Delivered to</h2>
                <div v-if="canViewAnalytics || canViewAudit" class="flex gap-3 text-xs">
                    <a
                        v-if="canViewAnalytics"
                        class="text-ink-muted underline"
                        :href="`/app/communications/announcements/${announcement.id}/analytics`"
                        >View analytics</a
                    >
                    <a
                        v-if="canViewAudit"
                        class="text-ink-muted underline"
                        :href="`/app/communications/announcements/${announcement.id}/audit`"
                        >View audit</a
                    >
                </div>
            </div>
            <p class="mt-1 text-sm">
                {{ announcement.recipientCount }} recipients (resolved at publish time)
            </p>

            <div v-if="channelDeliverySummary" class="mt-3 space-y-3 border-t border-line pt-3">
                <div
                    v-for="(rows, channel) in channelDeliverySummary"
                    :key="channel"
                    class="text-xs"
                >
                    <p class="font-medium text-ink-muted">
                        {{ channelLabels[channel] ?? channel }}
                    </p>
                    <p v-if="channel === 'in_app'" class="mt-1 text-ink-muted">
                        {{ channelSucceeded(rows) }} / {{ channelTotal(rows) }}
                        <span v-if="channelSuppressed(rows) > 0" class="text-xs"
                            >({{ channelSuppressed(rows) }} ineligible)</span
                        >
                    </p>
                    <ul v-else class="mt-1 space-y-0.5 text-ink-muted">
                        <li v-if="channelSucceeded(rows) > 0">{{ channelSucceeded(rows) }} sent</li>
                        <li v-if="channelPending(rows) > 0">{{ channelPending(rows) }} pending</li>
                        <li v-if="channelFailed(rows) > 0" class="text-danger">
                            {{ channelFailed(rows) }} failed
                        </li>
                        <li v-if="channelUnavailable(rows) > 0">
                            {{ channelUnavailable(rows) }} unavailable
                        </li>
                        <li v-if="channelSuppressed(rows) > 0">
                            {{ channelSuppressed(rows) }} suppressed (policy/preference)
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div
            v-if="canSchedule && ['draft', 'approved', 'scheduled'].includes(announcement.status)"
            class="mt-6 rounded-xs border border-line p-4"
        >
            <h2 class="text-sm font-semibold">
                {{ announcement.status === 'scheduled' ? 'Reschedule' : 'Schedule for later' }}
            </h2>
            <p class="mt-1 text-xs text-ink-muted">
                Enter a time in the school's timezone ({{ schoolTimezone }}). The audience is
                resolved again, authoritatively, when the schedule becomes due -- this preview is
                only an estimate.
            </p>
            <div class="mt-2 flex items-center gap-2">
                <input v-model="scheduledAtInput" type="datetime-local" class="input w-auto" />
                <button
                    type="button"
                    :disabled="scheduling"
                    class="btn btn-secondary text-ink-muted"
                    @click="submitSchedule"
                >
                    {{ announcement.status === 'scheduled' ? 'Update schedule' : 'Schedule' }}
                </button>
            </div>
        </div>

        <div
            v-if="
                canEdit &&
                announcement.status === 'draft' &&
                announcement.dispatchMode === 'emergency'
            "
            class="mt-6 rounded-xs border border-danger/40 bg-danger-tint p-3"
        >
            <label class="flex items-start gap-2 text-xs text-danger">
                <input
                    v-model="publishAcknowledged"
                    type="checkbox"
                    required
                    class="mt-0.5 rounded-xs border-danger/40"
                />
                I understand this will publish an Emergency communication and may bypass configured
                quiet hours on channels the school has explicitly enabled that for.
            </label>
            <div class="mt-3">
                <MfaCodeField
                    id="emergency-publish-mfa"
                    v-model="emergencyMfaCode"
                    :has-mfa-factor="hasMfaFactor"
                    action="Publishing an Emergency communication"
                    :error="emergencyMfaError"
                />
            </div>
        </div>

        <div v-if="canEdit || canCancel" class="mt-6 flex gap-2">
            <button
                v-if="
                    canEdit &&
                    (announcement.status === 'draft' || announcement.status === 'approved')
                "
                type="button"
                :disabled="
                    publishing ||
                    (announcement.dispatchMode === 'emergency' &&
                        (!publishAcknowledged || !hasMfaFactor))
                "
                class="btn btn-primary"
                @click="publish"
            >
                Publish
            </button>
            <button
                v-if="canCancel"
                type="button"
                :disabled="cancelling"
                class="btn btn-secondary text-ink-muted"
                @click="cancelAnnouncement"
            >
                {{ announcement.status === 'scheduled' ? 'Cancel Schedule' : 'Cancel Draft' }}
            </button>
        </div>
    </main>
</template>
