<script setup lang="ts">
// SWC.3 (ADR 0073 section 19.6): on an admission application, the School's
// admission fee head and the fee intent its conversion recorded. Read-only:
// fee intent is not a charge or a payment, and nothing here changes it.
import { feeHeadText, outcomeLabel, type FeeHeadLabel } from '../../support/feeIntent';

const props = defineProps<{
    panel: {
        configured: FeeHeadLabel | null;
        intent: {
            feeHead: FeeHeadLabel | null;
            academicYearId: string;
            selectionOutcome: string;
            recordedAt: string | null;
        } | null;
    };
    converted: boolean;
}>();
</script>

<template>
    <section
        class="mt-8 rounded border border-slate-300 p-4"
        aria-labelledby="admission-fee-heading"
    >
        <h2 id="admission-fee-heading" class="font-semibold">Admission fee</h2>
        <p class="mt-1 text-sm">
            <span class="text-slate-600">School admission fee head:</span>
            {{ feeHeadText(props.panel.configured) }} (<a
                class="underline"
                href="/app/admissions/fee-head"
                >admission fee setting</a
            >)
        </p>
        <p v-if="!props.converted" class="mt-2 text-sm text-slate-600">
            Fee intent is recorded when this application is converted.
        </p>
        <p
            v-else-if="!props.panel.intent"
            class="mt-2 text-sm text-slate-600"
            data-testid="admission-fee-intent"
        >
            No admission fee intent was recorded at conversion (no fee head configured, or none
            applicable).
        </p>
        <p v-else class="mt-2 text-sm" data-testid="admission-fee-intent">
            <span class="text-slate-600">Recorded at conversion:</span>
            {{ feeHeadText(props.panel.intent.feeHead) }} —
            {{ outcomeLabel(props.panel.intent.selectionOutcome) }},
            {{ props.panel.intent.recordedAt?.slice(0, 10) }}. This is fee intent, not a charge;
            Finance creates the charge when it assesses fees.
        </p>
    </section>
</template>
