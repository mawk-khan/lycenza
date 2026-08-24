<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import HubNav from '@/Components/App/Communications/HubNav.vue';

interface ThreadSummary {
    id: string;
    threadType: string;
    subject: string | null;
    status: string;
    otherParticipantNames: string[];
    lastActivityAt: string | null;
    latestMessagePreview: string | null;
    hasAttachment: boolean;
    unread: boolean;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Participant {
    userId: string;
    name: string;
}

interface Props {
    threads: Paginated<ThreadSummary>;
    meta: { currentPage: number; lastPage: number; total: number };
    filters: { archived: boolean; q: string | null };
    totalUnreadCount: number;
    canSend: boolean;
    canAnnounce: boolean;
    canManage: boolean;
    canManageChannelPolicy: boolean;
    canManageTemplates: boolean;
    canApprove: boolean;
}

const props = defineProps<Props>();

const showCompose = ref(false);
const subject = ref('');
const threadType = ref<'direct' | 'group'>('direct');
const selectedParticipants = ref<Participant[]>([]);
const participantQuery = ref('');
const participantResults = ref<Participant[]>([]);
const searching = ref(false);
const submitting = ref(false);
let searchDebounce: ReturnType<typeof setTimeout> | undefined;

function searchParticipants() {
    const q = participantQuery.value.trim();
    clearTimeout(searchDebounce);
    if (q === '') {
        participantResults.value = [];
        return;
    }
    searchDebounce = setTimeout(async () => {
        searching.value = true;
        try {
            const response = await fetch(
                `/app/communications/participants/search?q=${encodeURIComponent(q)}`,
                { headers: { Accept: 'application/json' } },
            );
            const body = await response.json();
            const selectedIds = new Set(selectedParticipants.value.map((p) => p.userId));
            participantResults.value = (body.participants as Participant[]).filter(
                (p) => !selectedIds.has(p.userId),
            );
        } finally {
            searching.value = false;
        }
    }, 250);
}

function addParticipant(participant: Participant) {
    selectedParticipants.value.push(participant);
    participantResults.value = participantResults.value.filter(
        (p) => p.userId !== participant.userId,
    );
    participantQuery.value = '';
}

function removeParticipant(userId: string) {
    selectedParticipants.value = selectedParticipants.value.filter((p) => p.userId !== userId);
}

function submitCompose() {
    if (selectedParticipants.value.length === 0) {
        return;
    }
    submitting.value = true;
    router.post(
        '/app/communications/conversations',
        {
            subject: subject.value || null,
            thread_type: threadType.value,
            participant_user_ids: selectedParticipants.value.map((p) => p.userId),
        },
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function toggleArchivedView() {
    router.get(
        '/app/communications/conversations',
        { archived: props.filters.archived ? undefined : 1 },
        { preserveState: true },
    );
}

const searchInput = ref(props.filters.q ?? '');
function submitSearch() {
    router.get(
        '/app/communications/conversations',
        {
            q: searchInput.value || undefined,
            archived: props.filters.archived ? 1 : undefined,
        },
        { preserveState: true },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <a class="text-xs text-slate-400 underline" href="/app/communications"
                    >← Communication Hub</a
                >
                <h1 class="mt-1 text-xl font-semibold">Conversations</h1>
            </div>
            <button
                v-if="canSend"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
                @click="showCompose = !showCompose"
            >
                + New Message
            </button>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-[160px_1fr]">
            <HubNav
                active="conversations"
                :total-unread-count="totalUnreadCount"
                :can-announce="canAnnounce"
                :can-manage="canManage"
                :can-manage-templates="canManageTemplates"
                :can-approve="canApprove"
            />

            <div>
                <form
                    v-if="showCompose"
                    class="mb-6 space-y-3 rounded border border-slate-200 p-4"
                    @submit.prevent="submitCompose"
                >
                    <div>
                        <label class="block text-xs font-medium text-slate-500"
                            >Subject (optional)</label
                        >
                        <input
                            v-model="subject"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">Type</label>
                        <select
                            v-model="threadType"
                            class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                        >
                            <option value="direct">Direct</option>
                            <option value="group">Group</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">Participants</label>
                        <div
                            v-if="selectedParticipants.length > 0"
                            class="mt-1 flex flex-wrap gap-1"
                        >
                            <span
                                v-for="p in selectedParticipants"
                                :key="p.userId"
                                class="flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
                            >
                                {{ p.name }}
                                <button
                                    type="button"
                                    class="text-slate-400 hover:text-slate-700"
                                    @click="removeParticipant(p.userId)"
                                >
                                    ×
                                </button>
                            </span>
                        </div>
                        <div class="relative mt-1">
                            <input
                                v-model="participantQuery"
                                type="text"
                                placeholder="Search people by name…"
                                class="w-full rounded border border-slate-300 px-2 py-1 text-sm"
                                @input="searchParticipants"
                            />
                            <ul
                                v-if="participantResults.length > 0"
                                class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                            >
                                <li
                                    v-for="result in participantResults"
                                    :key="result.userId"
                                    class="cursor-pointer px-2 py-1 hover:bg-slate-50"
                                    @click="addParticipant(result)"
                                >
                                    {{ result.name }}
                                </li>
                            </ul>
                        </div>
                    </div>
                    <button
                        type="submit"
                        :disabled="submitting || selectedParticipants.length === 0"
                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    >
                        Start conversation
                    </button>
                </form>

                <div class="mb-3 flex items-center gap-2">
                    <form class="flex flex-1 gap-1" @submit.prevent="submitSearch">
                        <input
                            v-model="searchInput"
                            type="text"
                            placeholder="Search conversations…"
                            class="w-full rounded border border-slate-300 px-2 py-1 text-sm"
                        />
                    </form>
                    <button
                        type="button"
                        class="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-600"
                        @click="toggleArchivedView"
                    >
                        {{ filters.archived ? 'Show active' : 'Show archived' }}
                    </button>
                </div>

                <div
                    v-if="threads.data.length === 0"
                    class="rounded border border-dashed border-slate-300 p-8 text-center"
                >
                    <p class="text-sm text-slate-500">
                        {{
                            filters.q
                                ? 'No matching conversations.'
                                : filters.archived
                                  ? 'No archived conversations.'
                                  : 'No conversations yet.'
                        }}
                    </p>
                    <p
                        v-if="canSend && !filters.archived && !filters.q"
                        class="mt-1 text-xs text-slate-400"
                    >
                        Start one with "New Message" above.
                    </p>
                </div>

                <ul v-else class="divide-y divide-slate-200 rounded border border-slate-200">
                    <li v-for="thread in threads.data" :key="thread.id">
                        <a
                            class="block px-4 py-3 hover:bg-slate-50"
                            :class="{ 'bg-slate-50': thread.unread }"
                            :href="`/app/communications/${thread.id}`"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span
                                    class="truncate text-sm"
                                    :class="thread.unread ? 'font-semibold' : 'font-medium'"
                                >
                                    {{
                                        thread.subject ||
                                        thread.otherParticipantNames.join(', ') ||
                                        `${thread.threadType === 'group' ? 'Group' : 'Direct'} conversation`
                                    }}
                                </span>
                                <span
                                    class="flex shrink-0 items-center gap-1 text-xs text-slate-400"
                                >
                                    <span v-if="thread.hasAttachment" title="Has attachment"
                                        >📎</span
                                    >
                                    <span
                                        v-if="thread.unread"
                                        class="h-1.5 w-1.5 rounded-full bg-slate-900"
                                    ></span>
                                    {{ thread.lastActivityAt }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ thread.latestMessagePreview ?? 'No messages yet.' }}
                            </p>
                        </a>
                    </li>
                </ul>

                <div
                    v-if="meta.lastPage > 1"
                    class="mt-4 flex items-center justify-between text-xs text-slate-400"
                >
                    <Link
                        v-if="meta.currentPage > 1"
                        :href="`/app/communications/conversations?page=${meta.currentPage - 1}`"
                        class="underline"
                        >← Newer</Link
                    >
                    <span v-else></span>
                    <span
                        >Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{
                            meta.total
                        }}
                        total)</span
                    >
                    <Link
                        v-if="meta.currentPage < meta.lastPage"
                        :href="`/app/communications/conversations?page=${meta.currentPage + 1}`"
                        class="underline"
                        >Older →</Link
                    >
                    <span v-else></span>
                </div>
            </div>
        </div>

        <nav class="mt-8 text-sm">
            <a class="underline" href="/app">← Back to dashboard</a>
        </nav>
    </main>
</template>
