<script setup lang="ts">
// Phase 1B.6 extends this from a fixed active/inactive binary to any
// registered status key (Enrollment's active/completed/withdrawn/
// transferred/cancelled) -- existing active/inactive callers
// (Student/Guardian) render byte-identical output to before; this is
// a superset, not a redesign.
type Status = 'active' | 'inactive' | 'completed' | 'withdrawn' | 'transferred' | 'cancelled';

interface Props {
    status: Status;
}

const props = defineProps<Props>();

const STYLES: Record<Status, { label: string; badge: string; dot: string }> = {
    active: { label: 'Active', badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    inactive: { label: 'Inactive', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' },
    completed: { label: 'Completed', badge: 'bg-sky-50 text-sky-700', dot: 'bg-sky-500' },
    withdrawn: { label: 'Withdrawn', badge: 'bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    transferred: {
        label: 'Transferred',
        badge: 'bg-violet-50 text-violet-700',
        dot: 'bg-violet-500',
    },
    cancelled: { label: 'Cancelled', badge: 'bg-red-50 text-red-700', dot: 'bg-red-500' },
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
