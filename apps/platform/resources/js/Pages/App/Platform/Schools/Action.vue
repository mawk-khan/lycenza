<script setup lang="ts">
// Phase 0N.9 (ADR 0047 section 7): the review step of one lifecycle
// action -- what changes, explicit confirmation and a fresh
// authentication code. Nothing changes until this form is submitted.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    school: {
        id: string;
        name: string;
        slug: string;
        code: string | null;
        status: string;
        closedAt: string | null;
        closureReason: string | null;
    };
    action: 'activate' | 'suspend' | 'resume' | 'bootstrap-admin' | 'close' | 'reopen';
    targetStatus: string | null;
    reasons: { value: string; label: string }[];
    bootstrapAdmins: { name: string; email: string }[];
    mfaEnrolled: boolean;
}

const props = defineProps<Props>();

const titles: Record<Props['action'], string> = {
    activate: 'Activate School',
    suspend: 'Suspend School',
    resume: 'Resume School',
    'bootstrap-admin': 'Replace the bootstrap School Administrator',
    close: 'Close School',
    reopen: 'Reopen School',
};

const effects: Record<Props['action'], string> = {
    activate:
        'The School becomes operational: its members can select it. The bootstrap administrator can no longer be changed from the platform, ever.',
    suspend:
        'Nobody can use the School until it is resumed. Active elevated access into it ends now; messages and webhooks are held, not sent; nothing is deleted.',
    resume: 'The School becomes operational again. Held messages and webhooks continue; nothing is replayed or recreated.',
    'bootstrap-admin':
        "The current administrator's membership is suspended (kept as history) and the account you name becomes the School Administrator.",
    close: 'The School is frozen for good: nobody can use it, and active elevated access into it ends now. Nothing is deleted. Its records stay under their retention periods; no School deletion exists.',
    reopen: 'The closure is withdrawn and the School becomes operational again. Nothing is replayed or recreated.',
};

const title = computed(() => titles[props.action]);

const form = useForm({
    reason_code: '',
    admin: '',
    confirmed: false,
    mfa_code: '',
});

function submit() {
    form.post(`/app/platform/schools/${props.school.id}/${props.action}`, {
        onFinish: () => form.reset('mfa_code'),
    });
}

function schoolError(): string | undefined {
    return (form.errors as Record<string, string | undefined>).school;
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/platform/schools/${school.id}`"
            >← {{ school.name }}</a
        >
        <h1 class="mt-2 text-xl font-semibold">{{ title }}</h1>

        <dl class="mt-6 space-y-2 text-sm">
            <div>
                <dt class="text-slate-500">School</dt>
                <dd class="font-medium" data-testid="action-school">
                    {{ school.name }} ({{ school.slug }})
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Status</dt>
                <dd data-testid="action-transition">
                    {{ school.status
                    }}<template v-if="targetStatus"> → {{ targetStatus }}</template>
                </dd>
            </div>
            <div v-if="action === 'bootstrap-admin' && bootstrapAdmins.length">
                <dt class="text-slate-500">Current bootstrap School Administrator</dt>
                <dd v-for="a in bootstrapAdmins" :key="a.email">{{ a.name }} ({{ a.email }})</dd>
            </div>
        </dl>

        <p class="mt-4 text-sm text-slate-600">{{ effects[action] }}</p>
        <p v-if="schoolError()" class="mt-4 text-sm text-red-700" data-testid="action-error">
            {{ schoolError() }}
        </p>

        <p
            v-if="!mfaEnrolled"
            role="status"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
        >
            This action requires multi-factor authentication. Enroll a factor under
            <a class="underline" href="/app/account/security">Account security</a> first.
        </p>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <div v-if="action === 'suspend' || action === 'close'">
                <label class="block text-sm text-slate-600" for="reason_code">Reason</label>
                <select
                    id="reason_code"
                    v-model="form.reason_code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Choose a reason</option>
                    <option v-for="r in reasons" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
                <p v-if="form.errors.reason_code" class="mt-1 text-sm text-red-700">
                    {{ form.errors.reason_code }}
                </p>
            </div>
            <div v-if="action === 'bootstrap-admin'">
                <label class="block text-sm text-slate-600" for="admin">
                    New School Administrator (exact email or account id)
                </label>
                <input
                    id="admin"
                    v-model="form.admin"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.admin" class="mt-1 text-sm text-red-700">
                    {{ form.errors.admin }}
                </p>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.confirmed" type="checkbox" class="mt-1" />
                <span>I confirm: {{ title.toLowerCase() }} — {{ school.name }}.</span>
            </label>
            <p v-if="form.errors.confirmed" class="text-sm text-red-700">
                {{ form.errors.confirmed }}
            </p>
            <div>
                <label class="block text-sm text-slate-600" for="mfa_code">
                    Authentication code (or a recovery code)
                </label>
                <input
                    id="mfa_code"
                    v-model="form.mfa_code"
                    autocomplete="one-time-code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.mfa_code" class="mt-1 text-sm text-red-700">
                    {{ form.errors.mfa_code }}
                </p>
            </div>
            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                >
                    {{ title }}
                </button>
                <a class="text-sm underline" :href="`/app/platform/schools/${school.id}`">Cancel</a>
            </div>
        </form>
    </main>
</template>
