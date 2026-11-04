<script setup lang="ts">
import { computed } from 'vue';

// Phase 1B.6 extended this from a fixed active/inactive binary to any
// registered status key (Enrollment's active/completed/withdrawn/
// transferred/cancelled) -- existing active/inactive callers
// (Student/Guardian) render byte-identical output to before. Phase
// 1B.7F extends it again with the rollover Plan status vocabulary
// (draft/validated/executing/completed_with_errors -- `completed`/
// `cancelled` already existed) AND the Item validation_result/
// execution_status vocabularies (ready/excluded/already_enrolled/
// review/blocked/succeeded/reconciled/skipped/failed) -- still one
// superset component, never a redesign; no value collides across the
// four status dimensions this now covers. Phase 1D.6 adds the
// AdmissionApplication lifecycle vocabulary (`draft` already existed
// above; `submitted`/`accepted`/`rejected`/`converted` are new -- no
// collision with any prior dimension).
type Status =
    | 'active'
    | 'inactive'
    // Phase 8A closure correction -- Employee.record_status's second
    // value (not 'inactive'; archival is a distinct concept from a
    // simple active/inactive toggle elsewhere in this union).
    | 'archived'
    | 'completed'
    | 'withdrawn'
    | 'transferred'
    | 'cancelled'
    | 'draft'
    | 'validated'
    | 'executing'
    | 'completed_with_errors'
    | 'ready'
    | 'excluded'
    | 'already_enrolled'
    | 'review'
    | 'blocked'
    | 'succeeded'
    | 'reconciled'
    | 'skipped'
    | 'failed'
    | 'submitted'
    | 'accepted'
    | 'rejected'
    | 'converted'
    // Phase 10F: CanteenOrder lifecycle (`cancelled` already existed
    // above; `pending`/`fulfilled` are new -- no collision with any
    // prior dimension).
    | 'pending'
    | 'fulfilled'
    // Phase 9.9: PayrollPeriod lifecycle (`draft` already existed
    // above -- `open`/`closed` are new). PayrollRun lifecycle (`draft`
    // already existed above -- `calculated`/`approved`/`posted` are
    // new). `reversed` is a derived PayrollRunPosting state, never a
    // real PayrollRun.status value (ADR 0034 -- a run's own status
    // never becomes "reversed").
    | 'open'
    | 'closed'
    | 'calculated'
    | 'approved'
    | 'posted'
    | 'reversed'
    // HR.12: separation case lifecycle (`approved`, `rejected`, `withdrawn`,
    // `cancelled`, `completed` already exist above).
    | 'requested'
    | 'finalized';

interface Props {
    status: Status;
}

const props = defineProps<Props>();

// DS-3 (ADR 0082 §9; DS-0 §4 "Status families"): every status belongs to one
// of six families, shown as a glyph + its word -- never colour alone. The
// palette is mono by design (OD-DS-1): the danger hue is reserved for errors
// and destructive actions, so a Cancelled or Failed status is a dark steel
// tag with a cross, not red. The glyph is decorative (aria-hidden); the label
// is what communicates state.
type Family = 'positive' | 'info' | 'progress' | 'attention' | 'negative' | 'neutral';

const FAMILIES: Record<Family, { glyph: string; tag: string }> = {
    positive: { glyph: '✓', tag: 'tag-positive' },
    info: { glyph: '●', tag: 'tag-info' },
    progress: { glyph: '◷', tag: 'tag-info' },
    attention: { glyph: '!', tag: 'tag-attention' },
    negative: { glyph: '✕', tag: 'tag-negative' },
    neutral: { glyph: '–', tag: 'tag-neutral' },
};

const STYLES: Record<Status, { label: string; family: Family }> = {
    active: { label: 'Active', family: 'positive' },
    inactive: { label: 'Inactive', family: 'neutral' },
    archived: { label: 'Archived', family: 'neutral' },
    completed: { label: 'Completed', family: 'info' },
    withdrawn: { label: 'Withdrawn', family: 'attention' },
    transferred: { label: 'Transferred', family: 'info' },
    cancelled: { label: 'Cancelled', family: 'negative' },
    // Rollover Plan statuses.
    draft: { label: 'Draft', family: 'neutral' },
    validated: { label: 'Validated', family: 'info' },
    executing: { label: 'Executing', family: 'progress' },
    completed_with_errors: { label: 'Completed with errors', family: 'attention' },
    // Rollover Item validation_result.
    ready: { label: 'Ready', family: 'positive' },
    excluded: { label: 'Excluded', family: 'neutral' },
    already_enrolled: { label: 'Already enrolled', family: 'info' },
    review: { label: 'Needs review', family: 'attention' },
    blocked: { label: 'Blocked', family: 'negative' },
    // Rollover Item execution_status.
    succeeded: { label: 'Succeeded', family: 'positive' },
    reconciled: { label: 'Reconciled', family: 'info' },
    skipped: { label: 'Skipped', family: 'neutral' },
    failed: { label: 'Failed', family: 'negative' },
    // AdmissionApplication lifecycle.
    submitted: { label: 'Submitted', family: 'progress' },
    accepted: { label: 'Accepted', family: 'positive' },
    rejected: { label: 'Rejected', family: 'negative' },
    converted: { label: 'Converted', family: 'info' },
    // CanteenOrder lifecycle.
    pending: { label: 'Pending', family: 'progress' },
    requested: { label: 'Awaiting decision', family: 'progress' },
    finalized: { label: 'Finalized', family: 'info' },
    fulfilled: { label: 'Fulfilled', family: 'positive' },
    // PayrollPeriod / PayrollRun lifecycle.
    open: { label: 'Open', family: 'info' },
    closed: { label: 'Closed', family: 'neutral' },
    calculated: { label: 'Calculated', family: 'info' },
    approved: { label: 'Approved', family: 'positive' },
    posted: { label: 'Posted', family: 'positive' },
    reversed: { label: 'Reversed', family: 'attention' },
};

// SIS 3.1: computed, so a status changed by an in-page Inertia visit (Submit,
// Accept, Discard ...) relabels the badge instead of keeping the first one.
const style = computed(() => {
    const status = STYLES[props.status];
    return { label: status.label, ...FAMILIES[status.family] };
});
</script>

<template>
    <span class="tag" :class="style.tag">
        <span aria-hidden="true">{{ style.glyph }}</span>
        {{ style.label }}
    </span>
</template>
