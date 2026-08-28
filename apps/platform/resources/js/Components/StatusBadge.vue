<script setup lang="ts">
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
    | 'converted';

interface Props {
    status: Status;
}

const props = defineProps<Props>();

const STYLES: Record<Status, { label: string; badge: string; dot: string }> = {
    active: { label: 'Active', badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    inactive: { label: 'Inactive', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
    archived: { label: 'Archived', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
    completed: { label: 'Completed', badge: 'bg-sky-50 text-sky-700', dot: 'bg-sky-500' },
    withdrawn: { label: 'Withdrawn', badge: 'bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    transferred: {
        label: 'Transferred',
        badge: 'bg-violet-50 text-violet-700',
        dot: 'bg-violet-500',
    },
    cancelled: { label: 'Cancelled', badge: 'bg-red-50 text-red-700', dot: 'bg-red-500' },
    // Rollover Plan statuses.
    draft: { label: 'Draft', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
    validated: { label: 'Validated', badge: 'bg-indigo-50 text-indigo-700', dot: 'bg-indigo-500' },
    executing: { label: 'Executing', badge: 'bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    completed_with_errors: {
        label: 'Completed with errors',
        badge: 'bg-orange-50 text-orange-700',
        dot: 'bg-orange-500',
    },
    // Rollover Item validation_result.
    ready: { label: 'Ready', badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    excluded: { label: 'Excluded', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
    already_enrolled: {
        label: 'Already enrolled',
        badge: 'bg-sky-50 text-sky-700',
        dot: 'bg-sky-500',
    },
    review: { label: 'Needs review', badge: 'bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    blocked: { label: 'Blocked', badge: 'bg-red-50 text-red-700', dot: 'bg-red-500' },
    // Rollover Item execution_status.
    succeeded: {
        label: 'Succeeded',
        badge: 'bg-emerald-50 text-emerald-700',
        dot: 'bg-emerald-500',
    },
    reconciled: { label: 'Reconciled', badge: 'bg-sky-50 text-sky-700', dot: 'bg-sky-500' },
    skipped: { label: 'Skipped', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
    failed: { label: 'Failed', badge: 'bg-red-50 text-red-700', dot: 'bg-red-500' },
    // AdmissionApplication lifecycle.
    submitted: { label: 'Submitted', badge: 'bg-sky-50 text-sky-700', dot: 'bg-sky-500' },
    accepted: { label: 'Accepted', badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    rejected: { label: 'Rejected', badge: 'bg-red-50 text-red-700', dot: 'bg-red-500' },
    converted: { label: 'Converted', badge: 'bg-violet-50 text-violet-700', dot: 'bg-violet-500' },
};

const style = STYLES[props.status];
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="style.badge"
    >
        <!--
            Status is never conveyed by color alone (accessibility) --
            the dot is decorative (aria-hidden), the text label is what
            actually communicates state.
        -->
        <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full" :class="style.dot" />
        {{ style.label }}
    </span>
</template>
