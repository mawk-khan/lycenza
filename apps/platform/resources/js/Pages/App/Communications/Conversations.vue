<script setup lang="ts">
import { formatDateTime } from '../../../datetime';
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

// Phase 5D.1b §5/§13: one participant category per authenticated
// endpoint kind the backend already models
// (App\Domain\Communications\Domain\CommunicationParticipantKind) --
// 'membership' is an ordinary School member/staff pick, 'guardian'/
// 'student' are domain-provenance picks resolved server-side by
// ConversationParticipantAuthorizationService. The category only
// decides which search endpoint runs and how a result is labeled;
// backend authorization is untouched and unaware of it.
type ParticipantCategory = 'membership' | 'guardian' | 'student';

interface Participant {
    userId: string;
    name: string;
}

interface GuardianSearchResult {
    id: string;
    label: string;
    guardianOfNames: string[];
    accountLinked: boolean;
}

interface StudentSearchResult {
    id: string;
    label: string;
    gradeSectionLabel: string | null;
    accountLinked: boolean;
}

// Phase 5D.1b §13: the normalized shape every category's search result
// and every selected chip uses, regardless of which of the three
// distinct backend response shapes it came from. `accountLinked` is
// only ever present (and only ever matters) for guardian/student
// results -- see searchGuardianParticipants()/searchStudentParticipants()'s
// docblocks for what it means and where it comes from.
interface SearchResultItem {
    id: string;
    label: string;
    kind: ParticipantCategory;
    context: string | null;
    accountLinked: boolean;
}

interface SelectedParticipant {
    id: string;
    label: string;
    kind: ParticipantCategory;
    context: string | null;
}

interface Props {
    threads: Paginated<ThreadSummary>;
    meta: { currentPage: number; lastPage: number; total: number };
    filters: { archived: boolean; q: string | null };
    totalUnreadCount: number;
    canSend: boolean;
    canAnnounce: boolean;
    canViewHub: boolean;
    canManage: boolean;
    canManageChannelPolicy: boolean;
    canManageTemplates: boolean;
    canApprove: boolean;
    // Phase 5D.1b §8: the composer only offers a Guardian/Student
    // category when the actor genuinely holds the matching capability
    // -- backend remains authoritative regardless (a forged request
    // still fails exactly as the Phase 5D.1 tests prove).
    canSelectGuardianParticipants: boolean;
    canSelectStudentParticipants: boolean;
    guardianConversationsAllowedByPolicy: boolean;
    studentConversationsAllowedByPolicy: boolean;
}

const props = defineProps<Props>();

const showCompose = ref(false);
const subject = ref('');
const threadType = ref<'direct' | 'group'>('direct');
const selectedParticipants = ref<SelectedParticipant[]>([]);
const participantCategory = ref<ParticipantCategory>('membership');
const participantQuery = ref('');
const participantResults = ref<SearchResultItem[]>([]);
const searching = ref(false);
const submitting = ref(false);
const composeError = ref<string | null>(null);
let searchDebounce: ReturnType<typeof setTimeout> | undefined;

const showCategoryTabs = props.canSelectGuardianParticipants || props.canSelectStudentParticipants;

function categoryLabel(kind: ParticipantCategory): string {
    return kind === 'membership' ? 'Staff' : kind === 'guardian' ? 'Guardian' : 'Student';
}

// Phase 5D.1b §7: an honest empty state -- never a fabricated eligible
// result for demonstration purposes.
function emptyResultsMessage(kind: ParticipantCategory): string {
    if (kind === 'membership') {
        return 'No matching people found.';
    }
    return kind === 'guardian'
        ? 'No matching Guardian accounts found.'
        : 'No matching Student accounts found.';
}

function searchEndpointFor(kind: ParticipantCategory): string {
    if (kind === 'guardian') {
        return '/app/communications/participants/search/guardians';
    }
    if (kind === 'student') {
        return '/app/communications/participants/search/students';
    }
    return '/app/communications/participants/search';
}

// Phase 5D.1b §9: the current category may be genuinely unavailable
// (School policy off) even though the actor holds the capability --
// distinct from the category not being offered at all (§8, no
// capability). Only 'membership' is never policy-gated.
function categoryUnavailableReason(kind: ParticipantCategory): string | null {
    if (kind === 'guardian' && !props.guardianConversationsAllowedByPolicy) {
        return "Guardian private conversations are disabled by your school's communication policy.";
    }
    if (kind === 'student' && !props.studentConversationsAllowedByPolicy) {
        return "Student private conversations are disabled by your school's communication policy.";
    }
    return null;
}

function switchCategory(kind: ParticipantCategory) {
    participantCategory.value = kind;
    participantQuery.value = '';
    participantResults.value = [];
}

// Phase 5D.1b §6/§7/§24/§25: normalizes each category's own narrow
// backend response shape into one common SearchResultItem -- never
// requests or reads a raw Guardian/Student/GuardianContact field
// beyond what searchGuardianParticipants()/searchStudentParticipants()
// already return.
function searchParticipants() {
    const q = participantQuery.value.trim();
    clearTimeout(searchDebounce);
    if (q === '' || categoryUnavailableReason(participantCategory.value) !== null) {
        participantResults.value = [];
        return;
    }
    const kind = participantCategory.value;
    searchDebounce = setTimeout(async () => {
        searching.value = true;
        try {
            const response = await fetch(`${searchEndpointFor(kind)}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();

            let results: SearchResultItem[];
            if (kind === 'membership') {
                results = (body.participants as Participant[]).map((p) => ({
                    id: p.userId,
                    label: p.name,
                    kind,
                    context: null,
                    accountLinked: true,
                }));
            } else if (kind === 'guardian') {
                results = (body.guardians as GuardianSearchResult[]).map((g) => ({
                    id: g.id,
                    label: g.label,
                    kind,
                    context:
                        g.guardianOfNames.length > 0
                            ? `Guardian of ${g.guardianOfNames.join(', ')}`
                            : null,
                    accountLinked: g.accountLinked,
                }));
            } else {
                results = (body.students as StudentSearchResult[]).map((s) => ({
                    id: s.id,
                    label: s.label,
                    kind,
                    context: s.gradeSectionLabel,
                    accountLinked: s.accountLinked,
                }));
            }

            const selectedIds = new Set(
                selectedParticipants.value.filter((p) => p.kind === kind).map((p) => p.id),
            );
            participantResults.value = results.filter((r) => !selectedIds.has(r.id));
        } finally {
            searching.value = false;
        }
    }, 250);
}

// Phase 5D.1b §10/§11: an unlinked identity, or one linked to an
// inactive membership, is never selectable here -- `accountLinked` is
// the exact same eligibility ConversationParticipantAuthorizationService
// itself re-checks at submit time (never reimplemented independently).
function addParticipant(result: SearchResultItem) {
    if (!result.accountLinked) {
        return;
    }
    selectedParticipants.value.push({
        id: result.id,
        label: result.label,
        kind: result.kind,
        context: result.context,
    });
    participantResults.value = participantResults.value.filter((r) => r.id !== result.id);
    participantQuery.value = '';
}

function removeParticipant(participant: SelectedParticipant) {
    selectedParticipants.value = selectedParticipants.value.filter(
        (p) => !(p.id === participant.id && p.kind === participant.kind),
    );
}

// Phase 5D.1b §14: the backend transaction is all-or-nothing -- a
// participant who became ineligible between search and submit surfaces
// as a server validation error on `participants` (see
// CommunicationHubController::store()); selected chips are
// deliberately left untouched here so the user can just remove the one
// that failed and resubmit, rather than losing their whole selection.
function submitCompose() {
    if (selectedParticipants.value.length === 0) {
        return;
    }
    submitting.value = true;
    composeError.value = null;
    router.post(
        '/app/communications/conversations',
        {
            subject: subject.value || null,
            thread_type: threadType.value,
            participant_user_ids: selectedParticipants.value
                .filter((p) => p.kind === 'membership')
                .map((p) => p.id),
            guardian_ids: selectedParticipants.value
                .filter((p) => p.kind === 'guardian')
                .map((p) => p.id),
            student_ids: selectedParticipants.value
                .filter((p) => p.kind === 'student')
                .map((p) => p.id),
        },
        {
            onFinish: () => {
                submitting.value = false;
            },
            onError: (errors: Record<string, string>) => {
                const first = Object.values(errors)[0];
                composeError.value =
                    typeof first === 'string' ? first : 'Could not start the conversation.';
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
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <div class="flex items-center justify-between">
            <div>
                <a class="text-xs text-ink-muted underline" href="/app/communications"
                    >← Communication Hub</a
                >
                <h1 class="mt-1 text-xl font-semibold">Conversations</h1>
            </div>
            <button v-if="canSend" class="btn btn-primary" @click="showCompose = !showCompose">
                + New Message
            </button>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-[160px_1fr]">
            <HubNav
                active="conversations"
                :total-unread-count="totalUnreadCount"
                :can-view-hub="canViewHub"
                :can-announce="canAnnounce"
                :can-manage="canManage"
                :can-manage-templates="canManageTemplates"
                :can-approve="canApprove"
            />

            <div>
                <form
                    v-if="showCompose"
                    class="mb-6 space-y-3 rounded-xs border border-line p-4"
                    @submit.prevent="submitCompose"
                >
                    <div>
                        <label class="block text-xs font-medium text-ink-muted"
                            >Subject (optional)</label
                        >
                        <input v-model="subject" type="text" class="input mt-1" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-muted">Type</label>
                        <select v-model="threadType" class="input mt-1 w-auto">
                            <option value="direct">Direct</option>
                            <option value="group">Group</option>
                        </select>
                    </div>
                    <div>
                        <span
                            id="participants-label"
                            class="block text-xs font-medium text-ink-muted"
                            >Participants</span
                        >

                        <div
                            v-if="selectedParticipants.length > 0"
                            class="mt-1 flex flex-wrap gap-1"
                        >
                            <span
                                v-for="p in selectedParticipants"
                                :key="`${p.kind}-${p.id}`"
                                class="flex max-w-full items-center gap-1 rounded-xs bg-graphite-200 px-2 py-0.5 text-xs text-ink"
                            >
                                <span class="truncate">
                                    {{ p.label }}
                                    <span
                                        class="ml-0.5 rounded-xs px-1 py-px text-[10px] font-medium"
                                        :class="{
                                            'bg-graphite-200 text-ink-muted':
                                                p.kind === 'membership',
                                            'bg-accent-100 text-accent-strong':
                                                p.kind === 'guardian',
                                            'bg-graphite-100 text-ink': p.kind === 'student',
                                        }"
                                        >{{ categoryLabel(p.kind) }}</span
                                    >
                                    <span
                                        v-if="p.context"
                                        class="block text-[10px] text-ink-muted"
                                        >{{ p.context }}</span
                                    >
                                </span>
                                <button
                                    type="button"
                                    class="shrink-0 text-ink-muted hover:text-ink"
                                    :aria-label="`Remove ${p.label}`"
                                    @click="removeParticipant(p)"
                                >
                                    ×
                                </button>
                            </span>
                        </div>

                        <!-- Phase 5D.1b §5: category tabs only appear when the
                             actor has more than one eligible participant type
                             -- otherwise the plain member search below is
                             shown alone, unchanged from before this checkpoint. -->
                        <div
                            v-if="showCategoryTabs"
                            role="tablist"
                            aria-label="Participant category"
                            class="mt-2 flex gap-1"
                        >
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="participantCategory === 'membership'"
                                class="rounded-xs px-2 py-1 text-xs font-medium"
                                :class="
                                    participantCategory === 'membership'
                                        ? 'bg-accent-strong text-white'
                                        : 'bg-graphite-200 text-ink-muted'
                                "
                                @click="switchCategory('membership')"
                            >
                                Members
                            </button>
                            <button
                                v-if="canSelectGuardianParticipants"
                                type="button"
                                role="tab"
                                :aria-selected="participantCategory === 'guardian'"
                                class="rounded-xs px-2 py-1 text-xs font-medium"
                                :class="
                                    participantCategory === 'guardian'
                                        ? 'bg-accent-strong text-white'
                                        : 'bg-graphite-200 text-ink-muted'
                                "
                                @click="switchCategory('guardian')"
                            >
                                Guardians
                            </button>
                            <button
                                v-if="canSelectStudentParticipants"
                                type="button"
                                role="tab"
                                :aria-selected="participantCategory === 'student'"
                                class="rounded-xs px-2 py-1 text-xs font-medium"
                                :class="
                                    participantCategory === 'student'
                                        ? 'bg-accent-strong text-white'
                                        : 'bg-graphite-200 text-ink-muted'
                                "
                                @click="switchCategory('student')"
                            >
                                Students
                            </button>
                        </div>

                        <!-- Phase 5D.1b §9: a School-policy-disabled category
                             is explained, never a silently empty list. -->
                        <p
                            v-if="categoryUnavailableReason(participantCategory)"
                            class="mt-1 rounded-xs bg-accent-100 p-2 text-xs text-ink"
                        >
                            {{ categoryUnavailableReason(participantCategory) }}
                        </p>
                        <template v-else>
                            <div class="relative mt-1">
                                <input
                                    v-model="participantQuery"
                                    type="text"
                                    aria-labelledby="participants-label"
                                    :aria-label="`Search ${participantCategory === 'membership' ? 'staff' : participantCategory === 'guardian' ? 'Guardians' : 'Students'} by name…`"
                                    :placeholder="
                                        participantCategory === 'membership'
                                            ? 'Search people by name…'
                                            : participantCategory === 'guardian'
                                              ? 'Search Guardians by name…'
                                              : 'Search Students by name…'
                                    "
                                    class="input"
                                    @input="searchParticipants"
                                />
                                <ul
                                    v-if="participantQuery.trim() !== ''"
                                    class="absolute z-10 mt-1 w-full rounded-xs border border-line bg-white text-sm shadow"
                                >
                                    <li
                                        v-if="!searching && participantResults.length === 0"
                                        class="px-2 py-1.5 text-xs text-ink-muted"
                                    >
                                        {{ emptyResultsMessage(participantCategory) }}
                                    </li>
                                    <li v-for="result in participantResults" :key="result.id">
                                        <button
                                            type="button"
                                            class="flex w-full flex-col items-start px-2 py-1 text-left"
                                            :class="
                                                result.accountLinked
                                                    ? 'cursor-pointer hover:bg-graphite-100'
                                                    : 'cursor-not-allowed text-ink-muted'
                                            "
                                            :disabled="!result.accountLinked"
                                            @click="addParticipant(result)"
                                        >
                                            <span>{{ result.label }}</span>
                                            <span
                                                v-if="result.context"
                                                class="text-xs text-ink-muted"
                                                >{{ result.context }}</span
                                            >
                                            <span
                                                v-if="!result.accountLinked"
                                                class="text-xs text-ink"
                                                >No Lycenza account linked</span
                                            >
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <!-- Phase 5D.1b §15: calm, non-alarming safeguarding
                                 context -- shown only while composing a Student
                                 conversation, never implementation detail. -->
                            <p
                                v-if="participantCategory === 'student'"
                                class="mt-1 text-xs text-ink-muted"
                            >
                                Student conversations are limited to Students with a linked, active
                                Lycenza account and are subject to your school's communication
                                policy.
                            </p>
                        </template>
                    </div>

                    <p v-if="composeError" class="text-xs text-danger">{{ composeError }}</p>

                    <button
                        type="submit"
                        :disabled="submitting || selectedParticipants.length === 0"
                        class="btn btn-primary"
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
                            class="input"
                        />
                    </form>
                    <button
                        type="button"
                        class="btn btn-secondary shrink-0 text-xs text-ink-muted"
                        @click="toggleArchivedView"
                    >
                        {{ filters.archived ? 'Show active' : 'Show archived' }}
                    </button>
                </div>

                <div
                    v-if="threads.data.length === 0"
                    class="rounded-xs border border-dashed border-control-line p-8 text-center"
                >
                    <p class="text-sm text-ink-muted">
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
                        class="mt-1 text-xs text-ink-muted"
                    >
                        Start one with "New Message" above.
                    </p>
                </div>

                <ul v-else class="divide-y divide-line rounded-xs border border-line">
                    <li v-for="thread in threads.data" :key="thread.id">
                        <a
                            class="block px-4 py-3 hover:bg-graphite-100"
                            :class="{ 'bg-graphite-100': thread.unread }"
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
                                    class="flex shrink-0 items-center gap-1 text-xs text-ink-muted"
                                >
                                    <span v-if="thread.hasAttachment" title="Has attachment"
                                        >📎</span
                                    >
                                    <span
                                        v-if="thread.unread"
                                        class="h-1.5 w-1.5 rounded-full bg-accent-strong"
                                    ></span>
                                    {{ formatDateTime(thread.lastActivityAt) }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ thread.latestMessagePreview ?? 'No messages yet.' }}
                            </p>
                        </a>
                    </li>
                </ul>

                <div
                    v-if="meta.lastPage > 1"
                    class="mt-4 flex items-center justify-between text-xs text-ink-muted"
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
