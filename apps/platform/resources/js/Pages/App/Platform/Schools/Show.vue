<script setup lang="ts">
// Phase 0N.9 (ADR 0047): one School's platform metadata and the lifecycle
// actions its current status allows. The bootstrap administrator is shown
// and replaceable only while the School is provisioning; there is no
// membership administration, archive or delete here.
interface Props {
    school: {
        id: string;
        name: string;
        slug: string;
        code: string | null;
        status: string;
        closedAt: string | null;
        closureReason: string | null;
    };
    bootstrapAdmins: { name: string; email: string }[];
    actions: {
        activate: boolean;
        replaceBootstrapAdmin: boolean;
        suspend: boolean;
        resume: boolean;
        close: boolean;
        reopen: boolean;
    };
    notice: string | null;
}

defineProps<Props>();

const notices: Record<string, string> = {
    created: 'School created as provisioning.',
    activate: 'School activated.',
    suspend: 'School suspended.',
    resume: 'School resumed.',
    'bootstrap-admin': 'Bootstrap School Administrator replaced.',
    close: 'School closed. Nothing was deleted.',
    reopen: 'School reopened.',
};
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/platform/schools">← Schools</a>
        <h1 class="mt-2 text-xl font-semibold">{{ school.name }}</h1>

        <p
            v-if="notice && notices[notice]"
            role="status"
            class="mt-4 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm"
            data-testid="lifecycle-notice"
        >
            {{ notices[notice] }}
        </p>

        <dl class="mt-6 space-y-2 text-sm">
            <div>
                <dt class="text-slate-500">Status</dt>
                <dd class="font-medium" data-testid="school-status">{{ school.status }}</dd>
            </div>
            <div v-if="school.closedAt">
                <dt class="text-slate-500">Closed</dt>
                <dd data-testid="school-closure">
                    {{ school.closedAt }} ({{ school.closureReason }})
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Slug</dt>
                <dd>{{ school.slug }}</dd>
            </div>
            <div v-if="school.code">
                <dt class="text-slate-500">Code</dt>
                <dd>{{ school.code }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Identifier</dt>
                <dd class="font-mono text-xs">{{ school.id }}</dd>
            </div>
            <div v-if="bootstrapAdmins.length">
                <dt class="text-slate-500">Bootstrap School Administrator</dt>
                <dd v-for="a in bootstrapAdmins" :key="a.email" data-testid="bootstrap-admin">
                    {{ a.name }} ({{ a.email }})
                </dd>
            </div>
        </dl>

        <ul class="mt-6 space-y-1 text-sm" data-testid="lifecycle-actions">
            <li v-if="actions.activate">
                <a class="underline" :href="`/app/platform/schools/${school.id}/activate`"
                    >Activate…</a
                >
            </li>
            <li v-if="actions.replaceBootstrapAdmin">
                <a class="underline" :href="`/app/platform/schools/${school.id}/bootstrap-admin`"
                    >Replace the bootstrap School Administrator…</a
                >
            </li>
            <li v-if="actions.suspend">
                <a class="underline" :href="`/app/platform/schools/${school.id}/suspend`"
                    >Suspend…</a
                >
            </li>
            <li v-if="actions.resume">
                <a class="underline" :href="`/app/platform/schools/${school.id}/resume`">Resume…</a>
            </li>
            <li v-if="actions.close">
                <a class="underline" :href="`/app/platform/schools/${school.id}/close`">Close…</a>
            </li>
            <li v-if="actions.reopen">
                <a class="underline" :href="`/app/platform/schools/${school.id}/reopen`">Reopen…</a>
            </li>
        </ul>
        <p
            v-if="
                !actions.activate &&
                !actions.suspend &&
                !actions.resume &&
                !actions.close &&
                !actions.reopen
            "
            class="mt-4 text-sm"
        >
            No lifecycle change is available for this School.
        </p>
        <p v-if="school.status !== 'provisioning'" class="mt-4 text-sm text-slate-600">
            Its members and roles are managed by the School itself.
        </p>
    </main>
</template>
