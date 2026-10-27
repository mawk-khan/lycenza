import { ref } from 'vue';
import type ConfirmDialog from './Components/ConfirmDialog.vue';

/**
 * SIS 9.1: the accessible replacement for `window.confirm` -- one shared
 * ConfirmDialog per page (native <dialog>: focus moves in and returns to the
 * opener; Escape cancels), asked with a title, what it affects and a button
 * that names the action.
 *
 *   const { confirmDialog, confirmation, confirmAction, onConfirm, onClose } = useConfirmation();
 *   if (!(await confirmAction({ title, body, confirmLabel }))) return;
 *
 *   <ConfirmDialog ref="confirmDialog" :title="confirmation?.title ?? ''"
 *       :confirm-label="confirmation?.confirmLabel" :tone="confirmation?.tone ?? 'danger'"
 *       @confirm="onConfirm" @close="onClose">
 *       <p class="whitespace-pre-line">{{ confirmation?.body }}</p>
 *   </ConfirmDialog>
 *
 * `@close` is the dialog element's own close event (Cancel, Escape, or
 * after Confirm); a confirm has already settled the answer by then.
 */
export interface ConfirmationRequest {
    title: string;
    body: string;
    confirmLabel: string;
    tone?: 'danger' | 'neutral';
}

export function useConfirmation() {
    const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
    const confirmation = ref<ConfirmationRequest | null>(null);
    let resolver: ((confirmed: boolean) => void) | null = null;

    function settle(confirmed: boolean): void {
        const resolve = resolver;
        resolver = null;
        resolve?.(confirmed);
    }

    function confirmAction(request: ConfirmationRequest): Promise<boolean> {
        settle(false);
        confirmation.value = request;
        confirmDialog.value?.open();

        return new Promise((resolve) => {
            resolver = resolve;
        });
    }

    return {
        confirmDialog,
        confirmation,
        confirmAction,
        onConfirm: () => settle(true),
        onClose: () => settle(false),
    };
}
