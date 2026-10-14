<script setup lang="ts">
// SWC.2 (ADR 0073 section 8): a form-level summary for errors that belong
// to no single visible field (a domain refusal on an action button). It is
// a polite live region, so the message is announced when it appears.
const props = defineProps<{ errors: Record<string, string | undefined>; fields: string[] }>();

const messages = () => props.fields.map((field) => props.errors[field]).filter(Boolean) as string[];
</script>

<template>
    <div
        v-if="messages().length"
        role="status"
        aria-live="polite"
        class="mt-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800"
        data-testid="error-summary"
    >
        <p v-for="message in messages()" :key="message">{{ message }}</p>
    </div>
</template>
