<script setup lang="ts">
// Phase 0N.9 (ADR 0047): one School's platform metadata and the lifecycle
// actions its current status allows. The bootstrap administrator is shown
// and replaceable only while the School is provisioning; there is no
// membership administration, archive or delete here.
// ORG.3 (ADR 0076 §9.2): the School Registry overview -- non-business
// metadata only. Recent platform governance events appear only through
// the platform audit review; the School's own audit ledger never does.
import {
    administratorLabels,
    checkLabels,
    domainLabels,
    readinessStyles,
    warningLabels,
} from '../../../../schoolRegistry';

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
    registry: {
        legalName: string | null;
        closed: boolean;
        createdAt: string | null;
        timezone: string;
        locale: string;
        campuses: { active: number; inactive: number };
        campusList: { name: string; code: string; status: string }[];
        academicYear: string | null;
        administrator: string;
        domain: string;
        domainsEnabled: boolean;
        domainStates: Record<string, number>;
        groups: { id: string; name: string }[];
        state: string;
        checks: { key: string; passed: boolean }[];
        warnings: { key: string; severity: string }[];
    };
    audit: {
        available: boolean;
        reason: string | null;
        entries: {
            id: string;
            occurredAt: string;
            eventType: string;
            actorUserId: string | null;
        }[];
    };
    canManage: boolean;
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
    <main class="mx-auto max-w-4xl p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/platform/schools">← School Registry</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">{{ school.name }}</h1>
            <span
                class="rounded px-2 py-0.5 text-xs font-medium"
                :class="readinessStyles[registry.state]?.badge"
                data-testid="school-readiness"
                >{{ readinessStyles[registry.state]?.label ?? registry.state }}</span
            >
        </div>

        <p
            v-if="notice && notices[notice]"
            role="status"
            class="mt-4 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm"
            data-testid="lifecycle-notice"
        >
            {{ notices[notice] }}
        </p>

        <ul
            v-if="registry.warnings.length"
            class="mt-4 space-y-1 text-sm"
            data-testid="school-warnings"
        >
            <li
                v-for="w in registry.warnings"
                :key="w.key"
                class="rounded px-3 py-2"
                :class="
                    w.severity === 'warning'
                        ? 'border border-amber-200 bg-amber-50 text-amber-900'
                        : 'border border-slate-200 bg-slate-50 text-slate-700'
                "
            >
                {{ warningLabels[w.key] ?? w.key }}
            </li>
        </ul>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <section aria-labelledby="identity-heading">
                <h2 id="identity-heading" class="text-sm font-medium text-slate-500">Identity</h2>
                <dl class="mt-2 space-y-2 text-sm">
                    <div v-if="registry.legalName">
                        <dt class="text-slate-500">Legal name</dt>
                        <dd>{{ registry.legalName }}</dd>
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
                        <dt class="text-slate-500">Time zone · locale</dt>
                        <dd>{{ registry.timezone }} · {{ registry.locale }}</dd>
                    </div>
                    <div v-if="registry.groups.length">
                        <dt class="text-slate-500">School Groups</dt>
                        <dd>{{ registry.groups.map((g) => g.name).join(', ') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Identifier</dt>
                        <dd class="font-mono text-xs">{{ school.id }}</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="lifecycle-heading">
                <h2 id="lifecycle-heading" class="text-sm font-medium text-slate-500">Lifecycle</h2>
                <dl class="mt-2 space-y-2 text-sm">
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
                    <div v-if="registry.createdAt">
                        <dt class="text-slate-500">Created</dt>
                        <dd>{{ registry.createdAt.slice(0, 10) }}</dd>
                    </div>
                    <div v-if="bootstrapAdmins.length">
                        <dt class="text-slate-500">Bootstrap School Administrator</dt>
                        <dd
                            v-for="a in bootstrapAdmins"
                            :key="a.email"
                            data-testid="bootstrap-admin"
                        >
                            {{ a.name }} ({{ a.email }})
                        </dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="setup-heading">
                <h2 id="setup-heading" class="text-sm font-medium text-slate-500">
                    Setup (advisory)
                </h2>
                <ul class="mt-2 space-y-1 text-sm" data-testid="school-checks">
                    <li v-for="c in registry.checks" :key="c.key">
                        <span :aria-label="c.passed ? 'Done' : 'Missing'">{{
                            c.passed ? '✓' : '✗'
                        }}</span>
                        {{ checkLabels[c.key] ?? c.key }}
                    </li>
                </ul>
                <p class="mt-2 text-sm" data-testid="school-administrator">
                    {{ administratorLabels[registry.administrator] ?? registry.administrator }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    Readiness is a guide only. It never blocks activation.
                </p>
            </section>

            <section aria-labelledby="structure-heading">
                <h2 id="structure-heading" class="text-sm font-medium text-slate-500">Structure</h2>
                <dl class="mt-2 space-y-2 text-sm">
                    <div>
                        <dt class="text-slate-500">Active academic year</dt>
                        <dd data-testid="school-academic-year">
                            {{ registry.academicYear ?? 'None active' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Campuses</dt>
                        <dd data-testid="school-campuses">
                            {{ registry.campuses.active }} active,
                            {{ registry.campuses.inactive }} inactive
                        </dd>
                        <dd
                            v-for="c in registry.campusList"
                            :key="c.code"
                            class="text-xs text-slate-500"
                        >
                            {{ c.name }} ({{ c.code }}){{
                                c.status === 'active' ? '' : ' · inactive'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Custom domain</dt>
                        <dd data-testid="school-domain">
                            {{ domainLabels[registry.domain] ?? registry.domain
                            }}<template v-if="!registry.domainsEnabled">
                                (custom domains are not enabled)</template
                            >
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <section class="mt-8" aria-labelledby="audit-heading">
            <h2 id="audit-heading" class="text-sm font-medium text-slate-500">
                Recent platform events
            </h2>
            <p v-if="!audit.available && audit.reason === 'mfa'" class="mt-2 text-sm">
                Confirm your sign-in with MFA to see this School's platform events.
            </p>
            <p v-else-if="!audit.available" class="mt-2 text-sm text-slate-500">
                Platform audit review is not part of your access.
            </p>
            <p v-else-if="audit.entries.length === 0" class="mt-2 text-sm text-slate-500">
                No platform events for this School yet.
            </p>
            <ul v-else class="mt-2 space-y-1 text-sm" data-testid="school-audit">
                <li v-for="e in audit.entries" :key="e.id">
                    <span class="font-mono text-xs text-slate-500">{{ e.occurredAt }}</span>
                    {{ e.eventType }}
                </li>
            </ul>
        </section>

        <section v-if="canManage" class="mt-8" aria-labelledby="actions-heading">
            <h2 id="actions-heading" class="text-sm font-medium text-slate-500">
                Lifecycle actions
            </h2>
            <ul class="mt-2 space-y-1 text-sm" data-testid="lifecycle-actions">
                <li v-if="actions.activate">
                    <a class="underline" :href="`/app/platform/schools/${school.id}/activate`"
                        >Activate…</a
                    >
                </li>
                <li v-if="actions.replaceBootstrapAdmin">
                    <a
                        class="underline"
                        :href="`/app/platform/schools/${school.id}/bootstrap-admin`"
                        >Replace the bootstrap School Administrator…</a
                    >
                </li>
                <li v-if="actions.suspend">
                    <a class="underline" :href="`/app/platform/schools/${school.id}/suspend`"
                        >Suspend…</a
                    >
                </li>
                <li v-if="actions.resume">
                    <a class="underline" :href="`/app/platform/schools/${school.id}/resume`"
                        >Resume…</a
                    >
                </li>
                <li v-if="actions.close">
                    <a class="underline" :href="`/app/platform/schools/${school.id}/close`"
                        >Close…</a
                    >
                </li>
                <li v-if="actions.reopen">
                    <a class="underline" :href="`/app/platform/schools/${school.id}/reopen`"
                        >Reopen…</a
                    >
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
                class="mt-2 text-sm"
            >
                No lifecycle change is available for this School.
            </p>
        </section>
        <p v-if="school.status !== 'provisioning'" class="mt-4 text-sm text-slate-600">
            Its members and roles are managed by the School itself.
        </p>
    </main>
</template>
