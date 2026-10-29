<script setup lang="ts">
import { computed } from 'vue';
import { integrationStatusPresentation, overviewOrder } from '../../../integrationReadiness';
import { brandSourceLabels, type OrganizationReport } from '../../../organizationReport';
import { overallStateLabels, statusPresentation } from '../../../setupReadiness';

/**
 * ORG.12H (ADR 0080 §13.2): School setup -> Organization report. A read-only
 * summary of how this School's control plane is configured, from each
 * owner's own records: setup (ORG.12A/B), integrations (ORG.12G),
 * presentation (ORG.12F) and access administration (ORG.12E). It changes
 * nothing, exports nothing and shows no person, record or amount.
 */
const props = defineProps<{ report: OrganizationReport }>();

const setup = computed(() => props.report.setup);
const integrationCounts = computed(() => {
    const section = props.report.integrations;
    if (!section || !section.available) return [];
    return overviewOrder
        .map((status) => ({ status, count: section.counts[status] ?? 0 }))
        .filter((entry) => entry.count > 0);
});
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Organization report</h1>
        <p class="mt-1 text-sm text-slate-600">
            How {{ report.school.name }} is configured, worked out from each module's own records
            each time you open this page. This report shows configuration only. It never gives
            access, and it shows no people, records or amounts.
        </p>

        <nav aria-label="Report sections" class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm">
            <a class="underline" href="#setup-heading">Setup summary</a>
            <a class="underline" href="#gaps-heading">Configuration gaps</a>
            <a v-if="report.integrations" class="underline" href="#integrations-heading"
                >Integrations</a
            >
            <a v-if="report.presentation" class="underline" href="#presentation-heading"
                >Presentation</a
            >
            <a v-if="report.access" class="underline" href="#access-heading"
                >Access administration</a
            >
        </nav>

        <!-- Setup summary -->
        <section aria-labelledby="setup-heading" class="mt-6">
            <h2 id="setup-heading" class="text-base font-semibold">Setup summary</h2>
            <p class="mt-1 text-sm" role="status">
                <span class="font-medium">{{ overallStateLabels[setup.overview.state] }}</span> ·
                Core: {{ setup.overview.core.configured }} of {{ setup.overview.core.total }} areas
                configured · Enabled modules: {{ setup.overview.optional.configured }} of
                {{ setup.overview.optional.total }} areas configured
            </p>
            <div
                class="relative mt-3 overflow-x-auto"
                tabindex="0"
                role="region"
                aria-label="Setup by category (scrolls sideways on small screens)"
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Setup areas by category
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="py-2 pr-3">Category</th>
                            <th scope="col" class="py-2 pr-3 text-right">Configured</th>
                            <th scope="col" class="py-2 pr-3 text-right">Incomplete</th>
                            <th scope="col" class="py-2 pr-3 text-right">Needs attention</th>
                            <th scope="col" class="py-2 pr-3 text-right">Blocked</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="category in setup.categories" :key="category.key">
                            <th scope="row" class="py-2 pr-3 font-normal">{{ category.label }}</th>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ category.configured }} of {{ category.applicable }}
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ category.incomplete }}
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ category.attention }}
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ category.blocked }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="setup.notEnabled.length" class="mt-2 text-xs text-slate-600">
                Optional modules not enabled (not counted, and never a gap):
                {{ setup.notEnabled.join(', ') }}.
            </p>
            <p v-if="setup.overview.hiddenAreas > 0" class="mt-1 text-xs text-slate-500">
                {{ setup.overview.hiddenAreas }} setup
                {{ setup.overview.hiddenAreas === 1 ? 'area is' : 'areas are' }} not shown because
                they need access you do not have.
            </p>
        </section>

        <!-- Configuration gaps -->
        <section aria-labelledby="gaps-heading" class="mt-8">
            <h2 id="gaps-heading" class="text-base font-semibold">Configuration gaps</h2>
            <div
                v-if="report.gaps.length"
                class="relative mt-3 overflow-x-auto"
                tabindex="0"
                role="region"
                aria-label="Configuration gaps (scrolls sideways on small screens)"
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Setup areas that are not configured, blocked first
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="py-2 pr-3">Area</th>
                            <th scope="col" class="py-2 pr-3">Owner</th>
                            <th scope="col" class="py-2 pr-3">Status</th>
                            <th scope="col" class="py-2 pr-3">Why</th>
                            <th scope="col" class="py-2 pr-3">
                                <span class="sr-only">Action</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="gap in report.gaps" :key="gap.key" class="align-top">
                            <th scope="row" class="py-2 pr-3 font-medium">
                                {{ gap.title }}
                                <span class="block text-xs font-normal text-slate-500"
                                    >{{ gap.category
                                    }}<template v-if="!gap.core"> · Optional module</template></span
                                >
                            </th>
                            <td class="py-2 pr-3">{{ gap.owner }}</td>
                            <td class="py-2 pr-3">
                                <span
                                    class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset"
                                    :class="statusPresentation[gap.status].classes"
                                >
                                    <span aria-hidden="true">{{
                                        statusPresentation[gap.status].icon
                                    }}</span>
                                    {{ statusPresentation[gap.status].label }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-slate-700">
                                {{ gap.explanation }}
                                <span v-if="gap.prerequisites.length" class="block text-xs"
                                    >Complete {{ gap.prerequisites.join(', ') }} first.</span
                                >
                                <span v-else-if="gap.hiddenPrerequisite" class="block text-xs"
                                    >A prerequisite setup area must be completed first.</span
                                >
                            </td>
                            <td class="py-2 pr-3 text-right">
                                <a
                                    v-if="gap.action.kind !== 'none' && gap.action.href"
                                    class="font-medium underline"
                                    :href="gap.action.href"
                                    :aria-label="`${gap.action.kind === 'configure' ? 'Configure' : 'View'} ${gap.title}`"
                                    >{{ gap.action.kind === 'configure' ? 'Configure' : 'View' }}</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="mt-2 text-sm text-slate-600">
                No configuration gaps in the areas you can see.
            </p>
        </section>

        <!-- Integrations -->
        <section v-if="report.integrations" aria-labelledby="integrations-heading" class="mt-8">
            <h2 id="integrations-heading" class="text-base font-semibold">Integrations</h2>
            <template v-if="report.integrations.available">
                <ul class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <li v-for="entry in integrationCounts" :key="entry.status">
                        <span aria-hidden="true">{{
                            integrationStatusPresentation[entry.status].icon
                        }}</span>
                        {{ entry.count }}
                        {{ integrationStatusPresentation[entry.status].label.toLowerCase() }}
                    </li>
                </ul>
                <p
                    v-if="report.integrations.verificationRequired.length"
                    class="mt-2 text-xs text-amber-900"
                >
                    Verify your sign-in to see:
                    {{ report.integrations.verificationRequired.join(', ') }}.
                </p>
                <a class="mt-2 inline-block text-sm underline" :href="report.integrations.href"
                    >Open Integrations &amp; devices</a
                >
            </template>
            <p v-else class="mt-2 text-sm text-slate-600">
                This summary could not be read right now.
            </p>
        </section>

        <!-- Presentation -->
        <section v-if="report.presentation" aria-labelledby="presentation-heading" class="mt-8">
            <h2 id="presentation-heading" class="text-base font-semibold">Presentation</h2>
            <dl v-if="report.presentation.available" class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-600">Brand</dt>
                    <dd>
                        {{ brandSourceLabels[report.presentation.brandSource] }}
                        <template v-if="report.presentation.networkName"
                            >({{ report.presentation.networkName }})</template
                        >
                        <span v-if="report.presentation.attention" class="block text-amber-900">{{
                            report.presentation.attention
                        }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Navigation preferences</dt>
                    <dd>
                        Staff: {{ report.presentation.navigation.staff_web || 'none' }} · Guardian
                        portal: {{ report.presentation.navigation.guardian_portal || 'none' }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-slate-600">Logo and banner</dt>
                    <dd>Not available yet: there is no governed media pipeline.</dd>
                </div>
                <div class="sm:col-span-2 text-xs text-slate-500">
                    Presentation never changes who can open what.
                    <a class="underline" :href="report.presentation.href"
                        >Branding &amp; navigation</a
                    >
                </div>
            </dl>
            <p v-else class="mt-2 text-sm text-slate-600">
                This summary could not be read right now.
            </p>
        </section>

        <!-- Access administration -->
        <section v-if="report.access" aria-labelledby="access-heading" class="mt-8">
            <h2 id="access-heading" class="text-base font-semibold">Access administration</h2>
            <dl v-if="report.access.available" class="mt-2 grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-slate-600">Role templates</dt>
                    <dd class="text-lg font-semibold">{{ report.access.roleTemplates }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">Whole-School role assignments</dt>
                    <dd class="text-lg font-semibold">
                        {{ report.access.wholeSchoolAssignments }}
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Campus role assignments</dt>
                    <dd class="text-lg font-semibold">{{ report.access.campusAssignments }}</dd>
                </div>
                <div class="sm:col-span-3">
                    <dt class="text-slate-600">Sensitive data</dt>
                    <dd>
                        {{ report.access.sensitiveData.status }} ({{
                            report.access.sensitiveData.families
                        }}
                        families described)
                    </dd>
                </div>
                <div class="sm:col-span-3 text-xs text-slate-500">
                    <a class="underline" :href="report.access.href">Open the Access Center</a>
                </div>
            </dl>
            <p v-else class="mt-2 text-sm text-slate-600">
                This summary could not be read right now.
            </p>
        </section>
    </main>
</template>
