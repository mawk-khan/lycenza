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
            class="mx-auto flex max-w-5xl items-start justify-between gap-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-800"
            role="alert"
            tabindex="-1"
        >
            <p class="min-w-0 break-words">
                <span class="font-semibold">Not saved:</span>
                {{ message }}
            </p>
            <button
                type="button"
                class="shrink-0 rounded px-1 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-800"
                @click="dismissed = true"
            >
                Dismiss<span class="sr-only"> message</span>
            </button>
        </div>
    </div>
</template>
