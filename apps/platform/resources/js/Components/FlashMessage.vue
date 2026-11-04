<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * X-01 (OD-V11-7, ADR 0075 section 12): the success message a controller
 * flashes on redirect (`->with('flash' | 'status', ...)`), shared by
 * HandleInertiaRequests as `flash.success`. Rendered once, as escaped
 * text, in the signed-in layout. It lasts until the next visit (the
 * session flash is single-request) or until dismissed. Errors are not
 * shown here: they stay with their forms.
 */
const page = usePage<{ flash?: { success: string | null } }>();
const message = computed(() => page.props.flash?.success ?? null);
const dismissed = ref(false);

// A new message (a later action on the same page) shows again.
watch(message, () => (dismissed.value = false));
</script>

<template>
    <div v-if="message && !dismissed" class="px-4 pt-4 sm:px-8">
        <div class="notice notice-success mx-auto max-w-5xl justify-between" role="status">
            <span aria-hidden="true" class="font-semibold text-accent-strong">✓</span>
            <p class="min-w-0 flex-1 break-words">{{ message }}</p>
            <button
                type="button"
                class="shrink-0 rounded-xs px-1 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-strong"
                @click="dismissed = true"
            >
                Dismiss<span class="sr-only"> message</span>
            </button>
        </div>
    </div>
</template>
