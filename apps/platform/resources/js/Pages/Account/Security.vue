<script setup lang="ts">
import { ref } from 'vue';
import { postJson } from '../../csrf';

const props = defineProps<{
    mfa: {
        enabled: boolean;
        factorType: string | null;
        confirmedAt: string | null;
        recoveryCodesRemaining: number;
    };
}>();

type Step = 'idle' | 'confirm-password' | 'begin' | 'confirm-code' | 'recovery-codes' | 'disable';
type PendingAction = 'enroll' | 'disable' | null;

const step = ref<Step>('idle');
const error = ref<string | null>(null);
const pendingAction = ref<PendingAction>(null);

const password = ref('');
const codeForm = ref('');
const disableCode = ref('');

const enrollment = ref<{ secret: string; otpAuthUri: string; qrCodeSvg: string } | null>(null);
const recoveryCodes = ref<string[]>([]);

function startEnrollment() {
    pendingAction.value = 'enroll';
    step.value = 'confirm-password';
    error.value = null;
}

// Every sensitive action (begin enrollment, disable) is gated behind
// the SAME fresh-password-confirmation step (section 12/20) -- this
// handler runs the shared /password-confirmation call, then branches
// to whichever action the user actually started.
async function confirmPasswordThenContinue() {
    try {
        await postJson('/app/account/security/password-confirmation', { password: password.value });
        password.value = '';

        if (pendingAction.value === 'enroll') {
            const result = await postJson<{
                secret: string;
                otpAuthUri: string;
                qrCodeSvg: string;
            }>('/app/account/security/mfa/begin');
            enrollment.value = result;
            step.value = 'begin';
        } else if (pendingAction.value === 'disable') {
            step.value = 'disable';
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong.';
    }
}

async function confirmEnrollment() {
    try {
        const result = await postJson<{ recoveryCodes: string[] }>(
            '/app/account/security/mfa/confirm',
            {
                code: codeForm.value,
            },
        );
        recoveryCodes.value = result.recoveryCodes;
        step.value = 'recovery-codes';
        codeForm.value = '';
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'That code is not valid.';
    }
}

function finishEnrollment() {
    step.value = 'idle';
    enrollment.value = null;
    recoveryCodes.value = [];
    // A full reload picks up the new `mfa.enabled` state from the server.
    window.location.reload();
}

function startDisable() {
    pendingAction.value = 'disable';
    step.value = 'confirm-password';
    error.value = null;
}

async function submitDisable() {
    try {
        await postJson('/app/account/security/mfa', { code: disableCode.value }, 'DELETE');
        disableCode.value = '';
        window.location.reload();
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'Could not disable MFA -- check your password and code.';
    }
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Account security</h1>
        <p class="mt-1 text-sm text-slate-600">Multi-factor authentication for your own account.</p>

        <p v-if="error" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

        <section v-if="step === 'idle'" class="mt-6 rounded border border-slate-200 p-4">
            <p v-if="props.mfa.enabled" class="text-sm text-slate-700">
                MFA is <strong>enabled</strong> ({{ props.mfa.recoveryCodesRemaining }} recovery
                codes remaining).
            </p>
            <p v-else class="text-sm text-slate-700">
                MFA is <strong>not enabled</strong> on this account.
            </p>

            <button
                v-if="!props.mfa.enabled"
                class="mt-3 rounded bg-slate-900 px-3 py-2 text-sm text-white"
                @click="startEnrollment"
            >
                Enable MFA
            </button>
            <button
                v-else
                class="mt-3 rounded border border-red-300 px-3 py-2 text-sm text-red-700"
                @click="startDisable"
            >
                Disable MFA
            </button>
        </section>

        <section
            v-else-if="step === 'confirm-password'"
            class="mt-6 rounded border border-slate-200 p-4"
        >
            <label class="block text-sm text-slate-600" for="confirm-password"
                >Confirm your password</label
            >
            <input
                id="confirm-password"
                v-model="password"
                type="password"
                autocomplete="current-password"
                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
            />
            <button
                class="mt-3 rounded bg-slate-900 px-3 py-2 text-sm text-white"
                @click="confirmPasswordThenContinue"
            >
                Continue
            </button>
        </section>

        <section
            v-else-if="step === 'begin' && enrollment"
            class="mt-6 rounded border border-slate-200 p-4"
        >
            <p class="text-sm text-slate-700">
                Scan this QR code with your authenticator app, then enter the 6-digit code.
            </p>
            <div class="mt-3" v-html="enrollment.qrCodeSvg" />
            <p class="mt-2 break-all text-xs text-slate-500">
                Manual entry: {{ enrollment.secret }}
            </p>

            <label class="mt-4 block text-sm text-slate-600" for="enroll-code"
                >Authentication code</label
            >
            <input
                id="enroll-code"
                v-model="codeForm"
                type="text"
                inputmode="numeric"
                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
            />
            <button
                class="mt-3 rounded bg-slate-900 px-3 py-2 text-sm text-white"
                @click="confirmEnrollment"
            >
                Confirm
            </button>
        </section>

        <section
            v-else-if="step === 'recovery-codes'"
            class="mt-6 rounded border border-slate-200 p-4"
        >
            <p class="text-sm font-medium text-slate-700">
                Save these recovery codes now -- they will not be shown again.
            </p>
            <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">
                <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
            </ul>
            <button
                class="mt-4 rounded bg-slate-900 px-3 py-2 text-sm text-white"
                @click="finishEnrollment"
            >
                I've saved my codes
            </button>
        </section>

        <section v-else-if="step === 'disable'" class="mt-6 rounded border border-slate-200 p-4">
            <label class="block text-sm text-slate-600" for="disable-code"
                >Current authentication or recovery code</label
            >
            <input
                id="disable-code"
                v-model="disableCode"
                type="text"
                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
            />
            <p class="mt-2 text-xs text-slate-500">
                Your password has already been confirmed for this action.
            </p>
            <button
                class="mt-3 rounded border border-red-300 px-3 py-2 text-sm text-red-700"
                @click="submitDisable"
            >
                Disable MFA
            </button>
        </section>
    </main>
</template>
