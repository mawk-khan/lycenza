<script setup lang="ts">
// SIS 11.4 (D-11): the governed export controls of a registered report --
// "Download CSV" (an authenticated, CSRF-protected POST; the server checks
// the report AND export authority, the filters and the row ceiling, and
// audits it) and "Print view" (the same rules, as a print-ready page). Shown
// only when the server says this viewer may export; hiding is never the
// protection.
import { computed, ref } from 'vue';
import { xsrfToken } from '../../csrf';

const props = defineProps<{
    reportKey: string;
    // The report's current filters and sort, exactly as the page uses them.
    query: Record<string, string>;
}>();

const busy = ref(false);
const message = ref('');
const failed = ref(false);

const active = computed(() =>
    Object.fromEntries(Object.entries(props.query).filter(([, v]) => v !== '' && v !== null)),
);
const printUrl = computed(
    () =>
        `/app/students/reports/${props.reportKey}/print` +
        (Object.keys(active.value).length
            ? `?${new URLSearchParams(active.value).toString()}`
            : ''),
);

function filenameOf(disposition: string | null): string {
    const match = disposition?.match(/filename="?([^";]+)"?/i);
    return match ? match[1] : 'export.csv';
}

async function download(): Promise<void> {
    if (busy.value) {
        return;
    }
    busy.value = true;
    failed.value = false;
    message.value = 'Preparing the download…';
    try {
        const response = await fetch(`/app/students/reports/${props.reportKey}/export`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                // JSON first: a refusal or validation error then comes back as
                // JSON (422/403), never as a followed redirect to a page.
                Accept: 'application/json, text/csv',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ ...active.value, format: 'csv' }),
        });
        if (!response.ok || !(response.headers.get('Content-Type') ?? '').includes('text/csv')) {
            const payload = await response.json().catch(() => null);
            const errors = payload?.errors ? Object.values(payload.errors).flat() : [];
            throw new Error(
                (errors[0] as string | undefined) ??
                    (response.status === 403
                        ? 'You do not have permission to export this report.'
                        : 'The download could not be prepared. Try again.'),
            );
        }
        const blob = await response.blob();
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filenameOf(response.headers.get('Content-Disposition'));
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(link.href);
        message.value = `Downloaded ${link.download}.`;
    } catch (e) {
        failed.value = true;
        message.value = e instanceof Error ? e.message : 'The download failed.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <button
            type="button"
            class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50 disabled:opacity-60"
            :disabled="busy"
            :aria-busy="busy"
            @click="download"
        >
            {{ busy ? 'Preparing…' : 'Download CSV' }}
        </button>
        <a
            class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
            :href="printUrl"
            target="_blank"
            rel="noopener"
            >Print view<span class="sr-only"> (opens in a new tab)</span></a
        >
        <p
            v-if="message"
            class="w-full text-sm"
            :class="failed ? 'text-red-700' : 'text-slate-600'"
            :role="failed ? 'alert' : 'status'"
        >
            {{ message }}
        </p>
    </div>
</template>
