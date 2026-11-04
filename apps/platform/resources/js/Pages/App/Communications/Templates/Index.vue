<script setup lang="ts">
import { formatDateTime } from '../../../../datetime';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TemplateSummary {
    id: string;
    name: string;
    templateType: string;
    status: string;
    createdByName: string | null;
    updatedAt: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    templates: Paginated<TemplateSummary>;
    filters: { status: string | null; search: string | null };
}

const props = defineProps<Props>();

const search = ref(props.filters.search ?? '');

function applySearch() {
    router.get(
        '/app/communications/templates',
        { status: props.filters.status ?? undefined, search: search.value || undefined },
        { preserveState: true },
    );
}

function statusHref(status: string | null): string {
    const params = new URLSearchParams();
    if (status) {
        params.set('status', status);
    }
    if (props.filters.search) {
        params.set('search', props.filters.search);
    }
    const query = params.toString();

    return query ? `/app/communications/templates?${query}` : '/app/communications/templates';
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <div class="flex items-center justify-between">
            <div>
                <a class="text-xs text-ink-muted underline" href="/app/communications"
                    >← Communication Hub</a
                >
                <h1 class="mt-1 text-xl font-semibold">Templates</h1>
            </div>
            <Link href="/app/communications/templates/create" class="btn btn-primary">
                + New Template
            </Link>
        </div>

        <div class="mt-4 flex items-center justify-between gap-4">
            <nav class="flex gap-1 text-xs">
                <a
                    v-for="tab in [
                        { label: 'All', value: null },
                        { label: 'Active', value: 'active' },
                        { label: 'Inactive', value: 'inactive' },
                    ]"
                    :key="tab.label"
                    :href="statusHref(tab.value)"
                    class="rounded-xs px-2 py-1"
                    :class="
                        (filters.status ?? null) === tab.value
                            ? 'bg-accent-strong font-medium text-white'
                            : 'text-ink-muted hover:bg-graphite-200'
                    "
                >
                    {{ tab.label }}
                </a>
            </nav>
            <form class="flex gap-1" @submit.prevent="applySearch">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by name"
                    class="input text-xs w-auto"
                />
                <button type="submit" class="btn btn-secondary text-xs text-ink-muted">
                    Search
                </button>
            </form>
        </div>

        <div
            v-if="templates.data.length === 0"
            class="mt-6 rounded-xs border border-dashed border-control-line p-8 text-center"
        >
            <p class="text-sm text-ink-muted">No templates yet.</p>
            <p class="mt-1 text-xs text-ink-muted">Start one with "New Template" above.</p>
        </div>

        <ul v-else class="mt-6 divide-y divide-line rounded-xs border border-line">
            <li v-for="template in templates.data" :key="template.id">
                <a
                    class="flex items-center justify-between px-4 py-3 hover:bg-graphite-100"
                    :href="`/app/communications/templates/${template.id}/edit`"
                >
                    <div>
                        <span class="text-sm font-medium">{{ template.name }}</span>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            by {{ template.createdByName ?? 'Unknown' }} · updated
                            {{ formatDateTime(template.updatedAt) }}
                        </p>
                    </div>
                    <span
                        class="rounded-xs px-1.5 py-0.5 text-xs font-medium"
                        :class="
                            template.status === 'active'
                                ? 'bg-graphite-100 text-ink'
                                : 'bg-graphite-200 text-ink-muted'
                        "
                    >
                        {{ template.status }}
                    </span>
                </a>
            </li>
        </ul>

        <p v-if="templates.last_page > 1" class="mt-4 text-xs text-ink-muted">
            Page {{ templates.current_page }} of {{ templates.last_page }} ({{ templates.total }}
            total)
        </p>
    </main>
</template>
