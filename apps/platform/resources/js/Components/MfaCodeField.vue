<script setup lang="ts">
/**
 * SR.4 (ADR 0071 §26.7): the one authentication-code field for a
 * sensitive action (payroll approve/post/reverse, ledger post/reverse,
 * payment recording, concession decisions, sensitive HR and payroll
 * writes, Emergency publishing). The server re-verifies the code
 * (FreshMfaRequirement) -- this field only collects it. Without an
 * enrolled factor it explains how to enroll instead.
 */
interface Props {
    id: string;
    hasMfaFactor: boolean;
    action: string;
    error?: string | null;
}

defineProps<Props>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <div v-if="hasMfaFactor">
        <label class="block text-sm text-slate-700" :for="id">Authentication code</label>
        <input
            :id="id"
            v-model="model"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="32"
            class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 font-mono text-sm"
        />
        <p class="mt-1 text-xs text-slate-500">
            {{ action }} needs a current code from your authenticator app or an unused recovery
            code.
        </p>
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
    <p v-else class="text-sm text-red-800">
        {{ action }} needs multi-factor authentication. Enroll a factor under
        <a href="/app/account/security" class="underline">Account security</a> first.
    </p>
</template>
