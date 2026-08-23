<script setup lang="ts">
interface Props {
    status: 'active' | 'inactive';
}

defineProps<Props>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="
            status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'
        "
    >
        <!--
            Status is never conveyed by color alone (accessibility) --
            the dot is decorative (aria-hidden), the text label is what
            actually communicates state.
        -->
        <span
            aria-hidden="true"
            class="h-1.5 w-1.5 rounded-full"
            :class="status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'"
        />
        {{ status === 'active' ? 'Active' : 'Inactive' }}
    </span>
</template>
