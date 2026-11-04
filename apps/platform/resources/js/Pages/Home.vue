<script setup lang="ts">
// SWC.1 (ADR 0073 section 7.1): the public, host-neutral product entry.
// No environment, request id, roadmap or module inventory -- only the
// product name (shared `product` prop) and the way in.
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<{ product: { name: string } }>();
const productName = computed(() => page.props.product.name);
</script>

<template>
    <Head title="Welcome" />
    <main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center p-8 font-sans text-ink">
        <h1 class="text-3xl font-semibold" data-testid="product-name">{{ productName }}</h1>
        <p class="mt-3 text-ink-muted">School management for staff, administrators and families.</p>
        <p class="mt-8">
            <a
                href="/login"
                class="btn btn-primary inline-block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-strong"
                data-testid="sign-in"
                >Sign in</a
            >
        </p>
    </main>
</template>
