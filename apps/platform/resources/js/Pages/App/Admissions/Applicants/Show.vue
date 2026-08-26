<script setup lang="ts">
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface Ref {
    id: string;
    name: string;
}

interface ApplicationRow {
    id: string;
    status: 'draft' | 'submitted' | 'accepted' | 'rejected' | 'withdrawn' | 'converted';
    academicYear: Ref;
    campus: Ref;
    gradeLevel: Ref;
    createdAt: string;
}

interface Props {
    applicant: {
        id: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        dateOfBirth: string;
        createdAt: string;
    };
    applications: ApplicationRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

function fullName(): string {
    return [props.applicant.firstName, props.applicant.middleName, props.applicant.lastName]
        .filter(Boolean)
        .join(' ');
}

function applicationContext(a: ApplicationRow): string {
    return `${a.gradeLevel.name} · ${a.campus.name} · ${a.academicYear.name}`;
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/admissions/applicants">← Applicants</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ fullName() }}</h1>
                <p class="mt-1 text-sm text-slate-500">Applicant</p>
            </div>
            <a
                v-if="canManage"
                :href="`/app/admissions/applicants/${applicant.id}/applications/create`"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                New application
            </a>
        </div>

        <section class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Identity</h2>
            <dl
                class="mt-2 grid grid-cols-1 gap-x-8 gap-y-3 rounded border border-slate-200 p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-slate-500">Name</dt>
                    <dd class="mt-0.5">{{ fullName() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Date of birth</dt>
                    <dd class="mt-0.5">{{ applicant.dateOfBirth }}</dd>
                </div>
            </dl>
        </section>

        <section class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Application history</h2>
            <p v-if="applications.length === 0" class="mt-3 text-sm text-slate-500">
                No Admission Applications yet.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="a in applications"
                    :key="a.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <a class="font-medium underline" :href="`/app/admissions/${a.id}`">{{
                            applicationContext(a)
                        }}</a>
                        <p class="mt-0.5 text-slate-500">Applied {{ a.createdAt.slice(0, 10) }}</p>
                    </div>
                    <StatusBadge :status="a.status" />
                </li>
            </ul>
        </section>
    </main>
</template>
