<script setup lang="ts">
import { computed } from 'vue';
import { relationshipLabel as labelFor } from '../relationshipTypes';

interface Props {
    relationshipType: string;
    isPrimary: boolean;
    isLegalGuardian: boolean;
    isEmergencyContact: boolean;
    isAuthorizedPickup: boolean;
}

const props = defineProps<Props>();

const relationshipLabel = computed(() => labelFor(props.relationshipType));

// Compact, meaningful labels only -- never a noisy list of every
// boolean (this checkpoint's brief, "avoid displaying every boolean as
// noisy text").
const flags = computed(() => {
    const list: string[] = [];
    if (props.isPrimary) list.push('Primary');
    if (props.isLegalGuardian) list.push('Legal guardian');
    if (props.isEmergencyContact) list.push('Emergency contact');
    if (props.isAuthorizedPickup) list.push('Authorized pickup');
    return list;
});
</script>

<template>
    <p class="text-sm text-ink-muted">
        {{ relationshipLabel }}
        <span v-if="flags.length" class="text-ink-muted">·</span>
        <span v-if="flags.length" class="text-ink-muted">{{ flags.join(' · ') }}</span>
    </p>
</template>
