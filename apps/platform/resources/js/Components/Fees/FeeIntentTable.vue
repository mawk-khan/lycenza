<script setup lang="ts">
import { formatDateTime } from '../../datetime';
// SWC.3 (ADR 0073 section 19): the fee intent OPF recorded for one source
// (a Transport assignment, a Hostel residency). Intent is an optional-fee
// selection for a year -- NOT a charge and NOT a payment; Finance creates
// charges when it assesses fees.
import {
    feeHeadText,
    outcomeLabel,
    reasonLabel,
    scopeLabel,
    type FeeIntentRow,
} from '../../support/feeIntent';

defineProps<{ rows: FeeIntentRow[]; caption: string; showScope?: boolean }>();
</script>

<template>
    <p v-if="rows.length === 0" class="text-sm text-slate-600">No fee intent recorded.</p>
    <div v-else class="relative overflow-x-auto">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead class="text-xs text-slate-600">
                <tr>
                    <th scope="col" class="pr-3 py-1">Academic year</th>
                    <th scope="col" class="pr-3 py-1">Fee head</th>
                    <th v-if="showScope" scope="col" class="pr-3 py-1">Mapped from</th>
                    <th scope="col" class="pr-3 py-1">Recorded because</th>
                    <th scope="col" class="pr-3 py-1">Result</th>
                    <th scope="col" class="pr-3 py-1">Recorded on</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="index">
                    <td class="pr-3 py-1">{{ row.academicYear ?? '—' }}</td>
                    <td class="pr-3 py-1">{{ feeHeadText(row.feeHead) }}</td>
                    <td v-if="showScope" class="pr-3 py-1">{{ scopeLabel(row.mappingScope) }}</td>
                    <td class="pr-3 py-1">{{ reasonLabel(row.linkReason) }}</td>
                    <td class="pr-3 py-1">{{ outcomeLabel(row.selectionOutcome) }}</td>
                    <td class="pr-3 py-1">{{ formatDateTime(row.recordedAt) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
