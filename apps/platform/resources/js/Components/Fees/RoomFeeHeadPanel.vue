<script setup lang="ts">
// SWC.3 (ADR 0073 section 19.3): a Hostel room's own fee head, the Hostel's
// default, and which one governs new fee intent -- the OPF.2 precedence
// (room override, else Hostel default) as the server reports it.
import FeeHeadPanel from './FeeHeadPanel.vue';
import { feeHeadText, type FeeHeadLabel } from '../../support/feeIntent';

const props = defineProps<{
    roomId: string;
    panel: {
        feeHead: FeeHeadLabel | null;
        hostelDefault: FeeHeadLabel | null;
        governing: 'room' | 'hostel' | null;
        options: FeeHeadLabel[];
        canManage: boolean;
    };
}>();

const governingText = () =>
    props.panel.governing === 'room'
        ? `This room's own fee head: ${feeHeadText(props.panel.feeHead)}.`
        : props.panel.governing === 'hostel'
          ? `The Hostel's fee head: ${feeHeadText(props.panel.hostelDefault)}.`
          : 'No fee head: residencies in this room record no fee intent.';
</script>

<template>
    <div>
        <FeeHeadPanel
            id="room-fee-head"
            title="Room fee head (override)"
            :action="`/app/hostel-rooms/${props.roomId}/fee-head`"
            :panel="{
                feeHead: props.panel.feeHead,
                options: props.panel.options,
                canManage: props.panel.canManage,
            }"
            hint="Optional. Without one, the Hostel's fee head applies."
        />
        <p class="mt-2 text-sm" data-testid="room-fee-governing">
            <span class="font-medium">New residencies record:</span> {{ governingText() }}
        </p>
    </div>
</template>
