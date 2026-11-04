<script setup lang="ts">
// Phase 0N.9 (ADR 0047 sections 3-4): create a School in `provisioning`
// together with its bootstrap School Administrator -- an exact, existing,
// enabled account (never your own). Creating does not activate the School.
import { useForm } from '@inertiajs/vue3';

defineProps<{ mfaEnrolled: boolean }>();

const form = useForm({
    name: '',
    slug: '',
    code: '',
    admin: '',
    confirmed: false,
    mfa_code: '',
});

function submit() {
    form.post('/app/platform/schools', { onFinish: () => form.reset('mfa_code') });
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/platform/schools">← Schools</a>
        <h1 class="mt-2 text-xl font-semibold">Create a School</h1>
        <p class="mt-1 text-sm text-ink-muted">
            The School starts as <strong>provisioning</strong>: nobody can use it until it is
            activated. The person you name becomes its first School Administrator through an
            ordinary membership. You do not become a member.
        </p>

        <p
            v-if="!mfaEnrolled"
            role="status"
            class="mt-6 rounded-xs border border-accent-300 bg-accent-100 px-3 py-2 text-sm"
        >
            Creating a School requires multi-factor authentication. Enroll a factor under
            <a class="underline" href="/app/account/security">Account security</a> first.
        </p>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="name">Name</label>
                <input id="name" v-model="form.name" class="input mt-1" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-danger">
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="slug">Slug</label>
                <input id="slug" v-model="form.slug" class="input mt-1" />
                <p v-if="form.errors.slug" class="mt-1 text-sm text-danger">
                    {{ form.errors.slug }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="code">Code (optional)</label>
                <input id="code" v-model="form.code" class="input mt-1" />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="admin">
                    First School Administrator (exact email or account id)
                </label>
                <input id="admin" v-model="form.admin" class="input mt-1" />
                <p v-if="form.errors.admin" class="mt-1 text-sm text-danger">
                    {{ form.errors.admin }}
                </p>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.confirmed" type="checkbox" class="mt-1" />
                <span
                    >I confirm creating this School as provisioning, with that person as its first
                    School Administrator.</span
                >
            </label>
            <p v-if="form.errors.confirmed" class="text-sm text-danger">
                {{ form.errors.confirmed }}
            </p>
            <div>
                <label class="block text-sm text-ink-muted" for="mfa_code">
                    Authentication code (or a recovery code)
                </label>
                <input
                    id="mfa_code"
                    v-model="form.mfa_code"
                    autocomplete="one-time-code"
                    class="input mt-1"
                />
                <p v-if="form.errors.mfa_code" class="mt-1 text-sm text-danger">
                    {{ form.errors.mfa_code }}
                </p>
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary">
                Create School
            </button>
        </form>
    </main>
</template>
