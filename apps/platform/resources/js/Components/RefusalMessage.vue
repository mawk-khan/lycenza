<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * LC-1 (Local Product Completion M1): the business rule that refused the
 * last action on this page, when the page itself has no field for it.
 * WebDomainRefusalResponder returns an expected domain refusal as the form
 * error `refusal` (the same request is still refused and nothing is saved);
 * this shows it once, in the signed-in layout, as escaped text. Field-level
 * errors stay with their forms. Focus moves here so a keyboard or screen
 * reader user lands on the explanation, not on a form that looks unchanged.
 */
const page = usePage<{ errors?: Record<string, string> }>();
const message = computed(() => page.props.errors?.refusal ?? null);
const dismissed = ref(false);
const box = ref<HTMLElement | null>(null);

watch(message, async (value) => {
    dismissed.value = false;
    if (value) {
        await nextTick();
        box.value?.focus();
    }
});
</script>

<template>
    <div v-if="message && !dismissed" class="px-4 pt-4 sm:px-8">
        <div
            ref="box"
            class="notice notice-error mx-auto max-w-5xl justify-between focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-danger"
            role="alert"
            tabindex="-1"
        >
            <span aria-hidden="true" class="font-semibold text-danger">✕</span>
            <p class="min-w-0 flex-1 break-words">
                <span class="font-semibold text-danger">Not saved:</span>
                {{ message }}
            </p>
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
