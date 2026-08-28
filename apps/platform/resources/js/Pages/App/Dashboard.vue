<script setup lang="ts">
import { router } from '@inertiajs/vue3';

interface Membership {
    schoolId: string;
    schoolName: string;
    isActive: boolean;
}

interface Props {
    activeSchool: { id: string; name: string } | null;
    memberships: Membership[];
    nav: {
        canViewSchoolSettings: boolean;
        canManageSchoolSettings: boolean;
        canManagePlatformSchools: boolean;
        canViewStudents: boolean;
        canViewGuardians: boolean;
        canViewCommunications: boolean;
        canViewEnrollments: boolean;
        canViewEnrollmentRollovers: boolean;
        canViewFinance: boolean;
    };
}

defineProps<Props>();

function activate(schoolId: string) {
    router.post(`/app/schools/${schoolId}/activate`);
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">School OS — Phase 0B</h1>

        <section class="mt-6">
            <h2 class="text-sm font-medium text-slate-500">Active School</h2>
            <p class="mt-1">{{ activeSchool ? activeSchool.name : 'None selected' }}</p>
        </section>

        <section v-if="memberships.length > 1 || !activeSchool" class="mt-6">
            <h2 class="text-sm font-medium text-slate-500">Switch School</h2>
            <ul class="mt-2 space-y-1">
                <li v-for="m in memberships" :key="m.schoolId">
                    <button
                        class="text-sm underline disabled:no-underline disabled:text-slate-400"
                        :disabled="m.isActive"
                        @click="activate(m.schoolId)"
                    >
                        {{ m.schoolName }}<span v-if="m.isActive"> (active)</span>
                    </button>
                </li>
            </ul>
        </section>

        <nav class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Navigation</h2>
            <ul class="mt-2 space-y-1 text-sm">
                <li v-if="nav.canViewSchoolSettings">
                    <a class="underline" href="/app/settings">School settings</a>
                </li>
                <li v-if="activeSchool">
                    <a class="underline" href="/app/school-setup">School setup</a>
                </li>
                <li v-if="nav.canViewStudents">
                    <a class="underline" href="/app/students">Students</a>
                </li>
                <li v-if="nav.canViewGuardians">
                    <a class="underline" href="/app/guardians">Guardians</a>
                </li>
                <li v-if="nav.canViewCommunications">
                    <a class="underline" href="/app/communications">Communication Hub</a>
                </li>
                <li v-if="nav.canViewEnrollments">
                    <a class="underline" href="/app/enrollments">Enrollments</a>
                </li>
                <li v-if="nav.canViewEnrollmentRollovers">
                    <a class="underline" href="/app/enrollment-rollovers">Enrollment Rollovers</a>
                </li>
                <li v-if="nav.canViewFinance">
                    <a class="underline" href="/app/finance">Finance</a>
                </li>
            </ul>
        </nav>
    </main>
</template>
