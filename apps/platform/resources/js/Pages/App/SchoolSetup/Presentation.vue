<script setup lang="ts">
// ORG.12F (ADR 0080 §11.5): the School's presentation profile. Branding (the
// School's own label and accent, and an optional School Group brand it chose
// explicitly) and navigation presentation (order within a group, hide, plain
// label) for the implemented audiences. Navigation preferences never change
// permissions: an item a person may not open stays absent, and hiding an item
// leaves its page reachable for those who may open it.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface NavItem {
    key: string;
    group: string;
    groupLabel: string;
    defaultLabel: string;
    defaultOrder: number;
    hideable: boolean;
    required: string | null;
    hidden: boolean;
    order: number;
    label: string | null;
}
interface Profile {
    fingerprint: string;
    stale: number;
    items: NavItem[];
}

const props = defineProps<{
    school: { name: string };
    canManage: boolean;
    brandProfile: {
        label: string | null;
        accent: string;
        foreground: string;
        source: 'system' | 'network' | 'school' | 'mixed';
        network: { id: string; name: string } | null;
        attention: string | null;
        school: { label: string | null; accent: string | null };
        networkValues: { label: string | null; accent: string | null } | null;
        fingerprint: string;
    };
    brandSources: Array<{ id: string; name: string }>;
    profiles: Record<string, Profile>;
}>();

const audienceLabels: Record<string, string> = {
    staff_web: 'Staff navigation',
    guardian_portal: 'Guardian portal',
};
const tabs = computed(() => [
    { key: 'brand', label: 'Branding' },
    ...Object.keys(props.profiles).map((a) => ({ key: a, label: audienceLabels[a] ?? a })),
]);
const tab = ref('brand');

// --- Branding ---------------------------------------------------------------
const brandForm = useForm({
    fingerprint: props.brandProfile.fingerprint,
    brand_source_group_id: props.brandProfile.network?.id ?? '',
    display_label: props.brandProfile.school.label ?? '',
    primary_accent: props.brandProfile.school.accent ?? '',
});
// Live preview only (never saved until submitted): the same per-field rule as the server.
const sourceValues = computed(() =>
    brandForm.brand_source_group_id !== '' &&
    props.brandProfile.network?.id === brandForm.brand_source_group_id
        ? props.brandProfile.networkValues
        : null,
);
const validAccent = (v: string) => /^#[0-9A-Fa-f]{6}$/.test(v);
const previewAccent = computed(() =>
    validAccent(brandForm.primary_accent)
        ? brandForm.primary_accent.toUpperCase()
        : (sourceValues.value?.accent ?? '#0F172A'),
);
const previewLabel = computed(
    () => brandForm.display_label.trim() || sourceValues.value?.label || props.school.name,
);
function luminance(hex: string) {
    const c = [1, 3, 5].map((i) => {
        const v = parseInt(hex.slice(i, i + 2), 16) / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}
const previewForeground = computed(() => {
    const l = luminance(previewAccent.value);
    return 1.05 / (l + 0.05) >= (l + 0.05) / (luminance('#111827') + 0.05) ? '#FFFFFF' : '#111827';
});
function saveBrand() {
    brandForm.put('/app/school-setup/presentation/brand', { preserveScroll: true });
}
function useNetworkValues() {
    brandForm.display_label = '';
    brandForm.primary_accent = '';
}

// --- Navigation -------------------------------------------------------------
const navForms = Object.fromEntries(
    Object.entries(props.profiles).map(([audience, profile]) => [
        audience,
        useForm({
            fingerprint: profile.fingerprint,
            items: profile.items
                .slice()
                .sort((a, b) => (a.group === b.group ? a.order - b.order : 0))
                .map((i) => ({ ...i, label: i.label ?? '' })),
        }),
    ]),
);
function groupsOf(audience: string) {
    const groups: Array<{ key: string; label: string; items: NavItem[] }> = [];
    for (const item of navForms[audience].items as NavItem[]) {
        let g = groups.find((x) => x.key === item.group);
        if (!g) {
            g = { key: item.group, label: item.groupLabel, items: [] };
            groups.push(g);
        }
        g.items.push(item);
    }
    return groups;
}
function move(audience: string, item: NavItem, delta: number) {
    const items = navForms[audience].items as NavItem[];
    const inGroup = items.filter((i) => i.group === item.group);
    const at = inGroup.indexOf(item);
    const to = at + delta;
    if (to < 0 || to >= inGroup.length) return;
    const other = inGroup[to];
    const a = items.indexOf(item);
    const b = items.indexOf(other);
    [items[a], items[b]] = [items[b], items[a]];
}
function saveNavigation(audience: string) {
    const form = navForms[audience];
    form.transform((data) => {
        const position: Record<string, number> = {};
        return {
            fingerprint: data.fingerprint,
            items: (data.items as NavItem[]).map((i) => {
                position[i.group] = (position[i.group] ?? -1) + 1;
                return {
                    key: i.key,
                    hidden: i.hidden,
                    order: position[i.group],
                    label: i.label || null,
                };
            }),
        };
    }).put(`/app/school-setup/presentation/navigation/${audience}`, { preserveScroll: true });
}
function resetNavigation(audience: string) {
    if (confirm('Reset this navigation to the defaults? Permissions are unchanged.')) {
        useForm({ fingerprint: props.profiles[audience].fingerprint }).delete(
            `/app/school-setup/presentation/navigation/${audience}`,
            { preserveScroll: true },
        );
    }
}
const err = (form: { errors: object }, key: string) =>
    (form.errors as Record<string, string | undefined>)[key];
const sourceText: Record<string, string> = {
    system: 'Lycenza default',
    network: 'School Group brand',
    school: 'This School',
    mixed: 'This School, with the School Group brand for the rest',
};
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Branding &amp; navigation</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            How {{ school.name }} looks to its staff and Guardians. These are presentation choices
            only: they never give or remove access. To see why someone can or cannot open a page,
            use the <a class="underline" href="/app/settings/access">Access Center</a>.
        </p>

        <div role="tablist" aria-label="Presentation sections" class="mt-6 flex flex-wrap gap-2">
            <button
                v-for="t in tabs"
                :id="`tab-${t.key}`"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="tab === t.key"
                :aria-controls="`panel-${t.key}`"
                class="rounded border px-3 py-1.5 text-sm"
                :class="
                    tab === t.key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300'
                "
                @click="tab = t.key"
            >
                {{ t.label }}
            </button>
        </div>

        <section
            v-show="tab === 'brand'"
            id="panel-brand"
            role="tabpanel"
            aria-labelledby="tab-brand"
            class="mt-4"
        >
            <div class="rounded border border-slate-200 p-4">
                <h2 class="text-base font-semibold">Effective branding</h2>
                <p class="mt-1 text-sm" data-testid="brand-source">
                    Source: <strong>{{ sourceText[brandProfile.source] }}</strong
                    ><template v-if="brandProfile.network">
                        ({{ brandProfile.network.name }})</template
                    >
                </p>
                <p v-if="brandProfile.attention" class="mt-2 text-sm text-amber-800" role="status">
                    {{ brandProfile.attention }}
                </p>
                <p class="mt-3 text-sm text-slate-600">Preview (not saved until you save):</p>
                <div
                    class="mt-1 rounded border-t-4 border-slate-200 bg-slate-50 px-3 py-2 text-sm"
                    :style="{ borderTopColor: previewAccent }"
                    data-testid="brand-preview"
                >
                    <span class="font-semibold">Lycenza</span>
                    <span class="ml-2 text-slate-600">{{ school.name }}</span>
                    <span
                        class="ml-2 rounded px-2 py-0.5 text-xs font-medium"
                        :style="{ backgroundColor: previewAccent, color: previewForeground }"
                        >{{ previewLabel }}</span
                    >
                </div>
                <p class="mt-2 text-xs text-slate-600">
                    Text on the accent is always shown in white or near-black, whichever is easier
                    to read. The School's name and legal identity never change.
                </p>
            </div>

            <form
                v-if="canManage"
                class="mt-4 grid gap-3 sm:grid-cols-2"
                @submit.prevent="saveBrand"
            >
                <div class="sm:col-span-2">
                    <label class="block text-sm text-slate-700" for="brand-source"
                        >School Group brand</label
                    >
                    <select
                        id="brand-source"
                        v-model="brandForm.brand_source_group_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2"
                    >
                        <option value="">None (Lycenza default)</option>
                        <option v-for="g in brandSources" :key="g.id" :value="g.id">
                            Use the {{ g.name }} brand
                        </option>
                    </select>
                    <p class="mt-1 text-xs text-slate-600">
                        Only School Groups this School belongs to. Joining a Group never applies its
                        brand by itself.
                    </p>
                    <p
                        v-if="brandForm.errors.brand_source_group_id"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ brandForm.errors.brand_source_group_id }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="brand-label"
                        >This School's brand label (optional)</label
                    >
                    <input
                        id="brand-label"
                        v-model="brandForm.display_label"
                        maxlength="60"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="brandForm.errors.display_label" class="mt-1 text-sm text-red-700">
                        {{ brandForm.errors.display_label }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="brand-accent"
                        >This School's accent colour (optional)</label
                    >
                    <input
                        id="brand-accent"
                        v-model="brandForm.primary_accent"
                        maxlength="7"
                        placeholder="#1D4E89"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                    <p v-if="brandForm.errors.primary_accent" class="mt-1 text-sm text-red-700">
                        {{ brandForm.errors.primary_accent }}
                    </p>
                </div>
                <p
                    v-if="err(brandForm, 'brand')"
                    class="text-sm text-red-700 sm:col-span-2"
                    role="alert"
                >
                    {{ err(brandForm, 'brand') }}
                </p>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="brandForm.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Save branding
                    </button>
                    <button
                        v-if="brandForm.brand_source_group_id"
                        type="button"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                        @click="useNetworkValues"
                    >
                        Use the School Group brand for label and colour
                    </button>
                </div>
            </form>
        </section>

        <section
            v-for="(profile, audience) in profiles"
            v-show="tab === audience"
            :id="`panel-${audience}`"
            :key="audience"
            role="tabpanel"
            :aria-labelledby="`tab-${audience}`"
            class="mt-4"
        >
            <p class="rounded border border-slate-200 bg-slate-50 p-3 text-sm" role="note">
                Navigation preferences do not change permissions. People only ever see the items
                they may open, and a hidden item's page stays reachable for those who may open it.
            </p>
            <p v-if="profile.stale" class="mt-2 text-sm text-amber-800">
                {{ profile.stale }} saved preference(s) refer to items that no longer exist. They
                are ignored; resetting removes them.
            </p>
            <form class="mt-4" @submit.prevent="saveNavigation(audience as string)">
                <div v-for="g in groupsOf(audience as string)" :key="g.key" class="mt-5">
                    <h2 class="text-sm font-semibold">{{ g.label }}</h2>
                    <ul
                        class="mt-2 divide-y divide-slate-100 rounded border border-slate-200 text-sm"
                    >
                        <li
                            v-for="(item, i) in g.items"
                            :key="item.key"
                            class="grid items-center gap-2 px-3 py-2 sm:grid-cols-[1fr_auto_14rem_auto]"
                            :data-testid="`nav-item-${item.key}`"
                        >
                            <span>
                                {{ item.label || item.defaultLabel }}
                                <span v-if="item.label" class="block text-xs text-slate-500"
                                    >Default: {{ item.defaultLabel }}</span
                                >
                                <span v-if="item.required" class="block text-xs text-slate-500">{{
                                    item.required
                                }}</span>
                            </span>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="item.hidden"
                                    type="checkbox"
                                    :disabled="!canManage || !item.hideable"
                                />
                                Hidden<span class="sr-only"> — {{ item.defaultLabel }}</span>
                            </label>
                            <span>
                                <label class="sr-only" :for="`label-${audience}-${item.key}`"
                                    >Label for {{ item.defaultLabel }}</label
                                >
                                <input
                                    :id="`label-${audience}-${item.key}`"
                                    v-model="item.label"
                                    :disabled="!canManage"
                                    maxlength="40"
                                    :placeholder="item.defaultLabel"
                                    class="w-full rounded border border-slate-300 px-2 py-1"
                                />
                            </span>
                            <span v-if="canManage" class="flex gap-1">
                                <button
                                    type="button"
                                    class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40"
                                    :disabled="i === 0"
                                    @click="move(audience as string, item, -1)"
                                >
                                    ↑<span class="sr-only"> Move {{ item.defaultLabel }} up</span>
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40"
                                    :disabled="i === g.items.length - 1"
                                    @click="move(audience as string, item, 1)"
                                >
                                    ↓<span class="sr-only"> Move {{ item.defaultLabel }} down</span>
                                </button>
                            </span>
                        </li>
                    </ul>
                </div>
                <p
                    v-if="err(navForms[audience as string], 'navigation')"
                    class="mt-3 text-sm text-red-700"
                    role="alert"
                >
                    {{ err(navForms[audience as string], 'navigation') }}
                </p>
                <div v-if="canManage" class="mt-4 flex flex-wrap gap-3">
                    <button
                        type="submit"
                        :disabled="navForms[audience as string].processing"
                        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Save navigation
                    </button>
                    <button
                        type="button"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                        @click="resetNavigation(audience as string)"
                    >
                        Reset to defaults
                    </button>
                </div>
            </form>
        </section>
    </main>
</template>
