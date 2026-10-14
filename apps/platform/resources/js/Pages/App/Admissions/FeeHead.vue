<script setup lang="ts">
// SWC.3 (ADR 0073 section 19.6): the School's admission fee head (OPF.3).
// Every conversion records fee intent for this one head -- there is no
// per-application choice (ADR 0067 D7).
import { Head } from '@inertiajs/vue3';
import FeeHeadPanel from '../../../Components/Fees/FeeHeadPanel.vue';
import type { FeeHeadLabel } from '../../../support/feeIntent';

const props = defineProps<{
    feeHead: FeeHeadLabel | null;
    options: FeeHeadLabel[];
    canManage: boolean;
}>();
</script>

<template>
    <Head title="Admission fee" />
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/admissions">← Admissions</a>
        <h1 class="mt-2 text-xl font-semibold">Admission fee</h1>
        <p class="mt-1 text-sm text-slate-600">
            When an accepted application is converted, fee intent is recorded for this fee head. It
            is the same for every conversion in this School.
        </p>
        <FeeHeadPanel
            id="admission-fee-head"
            title="Admission fee head"
            action="/app/admissions/fee-head"
            :panel="{ feeHead: props.feeHead, options: props.options, canManage: props.canManage }"
            hint="A change applies to conversions from now on; earlier conversions keep what they recorded."
        />
    </main>
</template>
