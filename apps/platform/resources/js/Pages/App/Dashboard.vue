<script setup lang="ts">
import { router } from '@inertiajs/vue3';

interface Membership {
    schoolId: string;
    schoolName: string;
    isActive: boolean;
}

interface Props {
    // Set for one request when a School page sent the User back here
    // because no valid School was selected (RequireSchoolContext).
    schoolContextNotice: 'select' | 'not_saved' | null;
    platformAccount: boolean;
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
        canViewAdmissions: boolean;
        canViewFinance: boolean;
        canViewSubjectOfferings: boolean;
        canViewHr: boolean;
        canViewCanteenDirectory: boolean;
        canViewCanteenOrders: boolean;
        canViewCanteenSettings: boolean;
        canViewTimetablePeriods: boolean;
        canViewTimetableSchedule: boolean;
        canViewPayroll: boolean;
        canViewAnalytics: boolean;
        canViewAuditLog: boolean;
        canViewAutomation: boolean;
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

        <p
            v-if="schoolContextNotice && !activeSchool"
            role="status"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
            data-testid="school-context-notice"
        >
            <template v-if="schoolContextNotice === 'not_saved'"
                >Nothing was saved: no School is selected.
            </template>
            <template v-if="memberships.length > 0">Select a School to continue.</template>
            <template v-else>That page belongs to a School, and this account has none.</template>
        </p>

        <section class="mt-6">
            <h2 class="text-sm font-medium text-slate-500">Active School</h2>
            <p class="mt-1" data-testid="active-school">
                {{ activeSchool ? activeSchool.name : 'None selected' }}
            </p>
        </section>

        <section v-if="memberships.length === 0" class="mt-6" data-testid="no-school-state">
            <h2 class="text-sm font-medium text-slate-500">No School access</h2>
            <p class="mt-1 text-sm">
                This account is not an active member of any School, so no School pages or School
                data are available to it.
            </p>
            <p v-if="platformAccount" class="mt-2 text-sm" data-testid="platform-account">
                This is a platform account. Platform access does not include access to any School.
            </p>
        </section>

        <section v-else-if="memberships.length > 1 || !activeSchool" class="mt-6">
            <h2 class="text-sm font-medium text-slate-500">
                {{ activeSchool ? 'Switch School' : 'Select a School' }}
            </h2>
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
                <li v-if="nav.canViewAdmissions">
                    <a class="underline" href="/app/admissions">Admissions</a>
                </li>
                <li v-if="nav.canViewFinance">
                    <a class="underline" href="/app/finance">Finance</a>
                </li>
                <li v-if="nav.canViewSubjectOfferings">
                    <a class="underline" href="/app/subject-offerings">Subject Offerings</a>
                </li>
                <li v-if="nav.canViewHr">
                    <a class="underline" href="/app/hr">HR</a>
                </li>
                <li v-if="nav.canViewCanteenDirectory">
                    <a class="underline" href="/app/canteen-outlets">Canteen Outlets</a>
                </li>
                <li v-if="nav.canViewCanteenDirectory">
                    <a class="underline" href="/app/canteen-items">Canteen Items</a>
                </li>
                <li v-if="nav.canViewCanteenOrders">
                    <a class="underline" href="/app/canteen-orders">Canteen Orders</a>
                </li>
                <li v-if="nav.canViewCanteenSettings">
                    <a class="underline" href="/app/canteen-settings">Canteen Settings</a>
                </li>
                <li v-if="nav.canViewTimetablePeriods">
                    <a class="underline" href="/app/timetable-periods">Timetable Periods</a>
                </li>
                <li v-if="nav.canViewTimetableSchedule">
                    <a class="underline" href="/app/timetable-schedule">Timetable Schedule</a>
                </li>
                <li v-if="nav.canViewPayroll">
                    <a class="underline" href="/app/payroll">Payroll</a>
                </li>
                <li v-if="nav.canViewAnalytics">
                    <a class="underline" href="/app/analytics/curriculum-coverage"
                        >Analytics: Curriculum Coverage</a
                    >
                </li>
                <li v-if="nav.canViewAuditLog">
                    <a class="underline" href="/app/compliance/audit-log">Compliance: Audit log</a>
                </li>
                <li v-if="nav.canViewAutomation">
                    <a class="underline" href="/app/automation">Automation</a>
                </li>
            </ul>
        </nav>
    </main>
</template>
