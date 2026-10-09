<script setup lang="ts">
/**
 * POR.3 — one payment, as applied to this child only. A payment can cover
 * several children; this page shows only the part applied to this child,
 * never the payment total. The School's payment receipt is a separate
 * document (its number is shown for reference).
 */
interface Props {
    schoolName: string;
    payment: {
        student: { id: string; name: string };
        paymentId: string;
        settledOn: string;
        method: string | null;
        receiptNumber: string | null;
        currency: string;
        appliedTotal: string;
        lines: {
            description: string;
            feeHeadName: string | null;
            billingPeriodLabel: string | null;
            appliedAmount: string;
        }[];
    };
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-10">
        <a class="text-sm underline" :href="`/app/portal/fees/students/${payment.student.id}`"
            >Back to fees</a
        >
        <h1 class="mt-4 text-xl font-semibold text-slate-900">
            Payment applied to {{ payment.student.name }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ schoolName }} · {{ payment.settledOn }}
            <template v-if="payment.method"> · {{ payment.method }}</template>
            <template v-if="payment.receiptNumber">
                · School receipt {{ payment.receiptNumber }}</template
            >
        </p>
        <p class="mt-2 text-xs text-slate-500">
            Only the part of this payment applied to this child is shown here. This is not the
            receipt itself.
        </p>

        <table class="mt-6 w-full text-left text-sm">
            <thead class="text-xs text-slate-500">
                <tr>
                    <th class="py-2">Fee</th>
                    <th class="py-2 text-right">Applied ({{ payment.currency }})</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="(line, index) in payment.lines" :key="index">
                    <td class="py-2">
                        {{ line.feeHeadName ?? line.description }}
                        <template v-if="line.billingPeriodLabel">
                            · {{ line.billingPeriodLabel }}</template
                        >
                    </td>
                    <td class="py-2 text-right">{{ line.appliedAmount }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <td class="py-2">Applied to fees shown for {{ payment.student.name }}</td>
                    <td class="py-2 text-right">{{ payment.appliedTotal }}</td>
                </tr>
            </tfoot>
        </table>
    </main>
</template>
