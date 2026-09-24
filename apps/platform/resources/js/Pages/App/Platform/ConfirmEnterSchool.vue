<script setup lang="ts">
// Phase 0N.3 (ADR 0044): step 2 -- the exact match is named, the actor
// confirms explicitly and re-verifies MFA; only then is elevation started.
import { useForm } from '@inertiajs/vue3';

interface Props {
    group: { id: string; name: string } | null;
    target: string;
    schoolName: string;
    reason: { value: string; label: string };
    maxMinutes: number;
    mfaEnrolled: boolean;
    errors: Record<string, string>;
}

const props = defineProps<Props>();

const form = useForm({
    target: props.target,
    reason_code: props.reason.value,
    confirmed: false,
    code: '',
});

function submit() {
    form.post(props.group ? `/app/groups/${props.group.id}/elevation` : '/app/platform/elevation', {
        onFinish: () => form.reset('code'),
    });
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Confirm elevated access</h1>

        <dl class="mt-6 space-y-2 text-sm">
            <div>
                <dt class="text-slate-500">School</dt>
                <dd class="font-medium" data-testid="confirm-school">{{ schoolName }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Authority</dt>
                <dd data-testid="confirm-authority">
                    {{ group ? `School Group: ${group.name}` : 'Platform' }}
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Reason</dt>
                <dd>{{ reason.label }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Lasts</dt>
                <dd>{{ maxMinutes }} minutes from now, then ends automatically</dd>
            </div>
        </dl>

        <p class="mt-4 text-sm text-slate-600">
            Elevated access sets this School as your context only. It grants no School permissions,
            it cannot be extended, and any School you had selected is cleared.
        </p>

        <p
            v-if="!mfaEnrolled"
            role="status"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
        >
            Entering a School requires multi-factor authentication. Enroll a factor under
            <a class="underline" href="/app/account/security">Account security</a> first.
        </p>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <p v-if="errors.target" class="text-sm text-red-700">{{ errors.target }}</p>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.confirmed" type="checkbox" class="mt-1" />
                <span>I confirm I am entering {{ schoolName }} with elevated access.</span>
            </label>
            <p v-if="errors.confirmed" class="text-sm text-red-700">{{ errors.confirmed }}</p>
            <div>
                <label class="block text-sm text-slate-600" for="code">
                    Authentication code (or a recovery code)
                </label>
                <input
                    id="code"
                    v-model="form.code"
                    autocomplete="one-time-code"
                    inputmode="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="errors.code" class="mt-1 text-sm text-red-700">{{ errors.code }}</p>
            </div>
            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                >
                    Enter School
                </button>
                <a class="text-sm underline" href="/app">Cancel</a>
            </div>
        </form>
    </main>
</template>
