<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 12): one School Group as its Group Admin sees
// it. ORG.12D (ADR 0080 §9, OD-12.11): the network overview and the
// member-School directory -- each School's name, code, lifecycle and the
// Group's own classification (relationship, Region, Zone). No School data,
// no count of anything inside a School. The only School-facing action is
// entering ONE member School through elevated access (Group Admin only),
// which grants no School permissions.
// Phase 0N.11 (ADR 0048): a link to the Group curriculum coverage report
// when the actor holds group.reporting.view.
// ORG.12E (ADR 0080 §10): Network roles. A read-only Network Viewer sees only
// its Region or Zone; a whole-Group Network Manager gets the governance
// workspace (Regions, Zones, classification, placement, removal) -- none of
// it is School access.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';
import GroupDirectory from '../../../Components/Groups/GroupDirectory.vue';
import GroupOverviewPanel from '../../../Components/Groups/GroupOverviewPanel.vue';
import NetworkGovernanceWorkspace from '../../../Components/Groups/NetworkGovernanceWorkspace.vue';
import type {
    DirectoryRow,
    GovernanceScope,
    GroupOverview,
    Option,
    Paginated,
} from '../../../groupNetwork';

const props = defineProps<{
    group: { id: string; name: string; status: string };
    overview: GroupOverview;
    directory: Paginated<DirectoryRow>;
    filters: Record<string, string | undefined>;
    relationships: Option[];
    reasons: Option[];
    scope: GovernanceScope;
    canManage: boolean;
    canManageOperators: boolean;
    hasMfaFactor: boolean;
    canViewReports: boolean;
    networkBrand: {
        label: string | null;
        accent: string | null;
        version: number;
        reasons: Option[];
    };
}>();

// ORG.12F (ADR 0080 §11.7): the Network brand. Only Schools that chose it show
// it; changing it needs a reason and a fresh code.
const brandForm = useForm({
    display_label: props.networkBrand.label ?? '',
    primary_accent: props.networkBrand.accent ?? '',
    version: props.networkBrand.version,
    reason_code: '',
    mfa_code: '',
});
function saveBrand() {
    brandForm.put(`/app/groups/${props.group.id}/network/brand`, {
        preserveScroll: true,
        onFinish: () => brandForm.reset('mfa_code'),
    });
}
const brandError = computed(() => (brandForm.errors as Record<string, string | undefined>).brand);

const pageUrl = `/app/groups/${props.group.id}`;
const scopeLabel = computed(() => {
    if (props.scope.type === 'whole_group') return 'the whole Group';
    const names = [
        ...props.overview.regions
            .filter((r) => props.scope.regionIds.includes(r.id))
            .map((r) => `Region ${r.name}`),
        ...props.overview.zones
            .filter((z) => props.scope.zoneIds.includes(z.id))
            .map((z) => `Zone ${z.name}`),
    ];
    return names.join(', ') || 'a limited part of the Group';
});
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <h1 class="text-xl font-semibold" data-testid="group-name">{{ group.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">School Group (network) — {{ group.status }}</p>
        <p class="mt-2 text-sm" data-testid="group-scope">
            You see <strong>{{ scopeLabel }}</strong
            >.
            <a class="ml-2 underline" :href="`${pageUrl}/access`">Network access</a>
        </p>

        <GroupOverviewPanel :overview="overview" />

        <NetworkGovernanceWorkspace
            v-if="canManage"
            :page-url="pageUrl"
            :action-base="`${pageUrl}/network`"
            :overview="overview"
            :directory="directory"
            :filters="filters"
            :relationships="relationships"
            :reasons="reasons"
            :has-mfa-factor="hasMfaFactor"
            :editable="group.status === 'active'"
        >
            <template #row-actions="{ school }">
                <a
                    v-if="school.canEnter"
                    class="mr-3 underline"
                    :href="`${pageUrl}/elevation?school=${school.id}`"
                    >Enter (elevated access)<span class="sr-only"> {{ school.name }}</span></a
                >
            </template>
        </NetworkGovernanceWorkspace>

        <GroupDirectory
            v-else
            :base-url="pageUrl"
            :directory="directory"
            :filters="filters"
            :relationships="relationships"
            :regions="overview.regions"
            :zones="overview.zones"
        >
            <template #actions="{ school }">
                <a
                    v-if="school.canEnter"
                    class="underline"
                    :href="`${pageUrl}/elevation?school=${school.id}`"
                    >Enter (elevated access)<span class="sr-only"> {{ school.name }}</span></a
                >
                <span v-else-if="school.isMember" class="text-slate-500"
                    >You are a member — select it from your School list</span
                >
            </template>
        </GroupDirectory>

        <section aria-labelledby="network-brand-heading" class="mt-8" data-testid="network-brand">
            <h2 id="network-brand-heading" class="text-base font-semibold">Network brand</h2>
            <p class="mt-1 text-sm text-slate-600">
                A label and accent colour that member Schools may choose to use. It never applies to
                a School by itself, and it never changes a School's name or access.
            </p>
            <p class="mt-2 text-sm">
                <span
                    v-if="networkBrand.accent"
                    class="mr-2 inline-block h-4 w-4 rounded align-middle"
                    :style="{ backgroundColor: networkBrand.accent }"
                    aria-hidden="true"
                ></span>
                {{ networkBrand.label ?? 'No label' }} · {{ networkBrand.accent ?? 'No accent' }}
            </p>
            <form
                v-if="canManage && group.status === 'active'"
                class="mt-3 grid gap-3 sm:grid-cols-2"
                @submit.prevent="saveBrand"
            >
                <div>
                    <label class="block text-sm text-slate-700" for="network-brand-label"
                        >Brand label</label
                    >
                    <input
                        id="network-brand-label"
                        v-model="brandForm.display_label"
                        maxlength="60"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="brandForm.errors.display_label" class="mt-1 text-sm text-red-700">
                        {{ brandForm.errors.display_label }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="network-brand-accent"
                        >Accent colour</label
                    >
                    <input
                        id="network-brand-accent"
                        v-model="brandForm.primary_accent"
                        maxlength="7"
                        placeholder="#1D4E89"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="brandForm.errors.primary_accent" class="mt-1 text-sm text-red-700">
                        {{ brandForm.errors.primary_accent }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="network-brand-reason"
                        >Reason</label
                    >
                    <select
                        id="network-brand-reason"
                        v-model="brandForm.reason_code"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="r in networkBrand.reasons" :key="r.code" :value="r.code">
                            {{ r.label }}
                        </option>
                    </select>
                    <p v-if="brandForm.errors.reason_code" class="mt-1 text-sm text-red-700">
                        {{ brandForm.errors.reason_code }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <MfaCodeField
                        id="network-brand-mfa"
                        v-model="brandForm.mfa_code"
                        :has-mfa-factor="hasMfaFactor"
                        action="change the Network brand"
                        :error="brandForm.errors.mfa_code"
                    />
                </div>
                <p v-if="brandError" class="text-sm text-red-700 sm:col-span-2" role="alert">
                    {{ brandError }}
                </p>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="brandForm.processing || !hasMfaFactor"
                        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Save Network brand
                    </button>
                </div>
            </form>
        </section>

        <p v-if="canViewReports" class="mt-6 text-sm" data-testid="group-reports">
            <a class="underline" :href="`${pageUrl}/reports/curriculum-coverage`"
                >Curriculum coverage report</a
            >
        </p>

        <p class="mt-6 text-sm text-slate-600">
            Network roles, Regions and Zones organise the network; they never give anyone access to
            a School. Entering a School uses elevated access (Group Admin only): at most 30 minutes,
            a fresh multi-factor code, and no School permissions.
        </p>
        <p class="mt-4 text-sm"><a class="underline" href="/app/groups">All your Groups</a></p>
    </main>
</template>
