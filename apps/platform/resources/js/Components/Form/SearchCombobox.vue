<script setup lang="ts" generic="T">
// UAT-C4 (UAT-A11Y-01, ADR 0075 section 17): the one search-and-pick control
// for staff forms -- a text input with a results listbox (WAI-ARIA combobox
// pattern, list autocomplete). The page still owns the label (`for` = id),
// the query, the search request and what a pick does; this component owns
// only the interaction: Arrow Down/Up move through the results, Enter picks
// the highlighted one, Escape dismisses the list, the mouse still works, and
// a polite live region announces how many results there are, that there are
// none, and what was picked. Focus stays in the input after a pick. Extra
// attributes (placeholder, class, autocomplete, ...) go to the input.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        id: string;
        options: T[];
        optionKey: (option: T) => string;
        /** Characters typed before "no results" may be announced. */
        minLength?: number;
        error?: string;
        listClass?: string;
        optionClass?: string;
    }>(),
    {
        minLength: 1,
        error: undefined,
        listClass:
            'absolute z-10 mt-1 max-h-72 w-full overflow-y-auto rounded border border-slate-200 bg-white text-sm shadow',
        optionClass: 'cursor-pointer px-3 py-2 hover:bg-slate-50',
    },
);

const query = defineModel<string>({ required: true });
const emit = defineEmits<{ select: [option: T] }>();

const input = ref<HTMLInputElement | null>(null);
const active = ref(-1);
const dismissed = ref(false);
const status = ref('');
let statusTimer: ReturnType<typeof setTimeout> | undefined;

const listId = computed(() => `${props.id}-results`);
const optionId = (index: number) => `${props.id}-option-${index}`;
const open = computed(() => !dismissed.value && props.options.length > 0);
const activeId = computed(() =>
    open.value && active.value >= 0 ? optionId(active.value) : undefined,
);
const describedBy = computed(() => (props.error ? `${props.id}-error` : undefined));

// Results change with every search: nothing is highlighted until the user moves.
watch(
    () => props.options,
    () => {
        active.value = -1;
        announceSoon();
    },
);
watch(query, announceSoon);

// Announce after the search has had time to answer, so "no results" is never
// read out for a request still in flight.
function announceSoon(): void {
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => {
        if (dismissed.value) {
            return;
        }
        const n = props.options.length;
        if (n > 0) {
            status.value = `${n} ${n === 1 ? 'result' : 'results'}. Use the up and down arrow keys to choose, then press Enter.`;
        } else if (query.value.trim().length >= props.minLength) {
            status.value = 'No matching results.';
        } else {
            status.value = '';
        }
    }, 600);
}
onBeforeUnmount(() => clearTimeout(statusTimer));

function onInput(): void {
    dismissed.value = false;
}

function move(step: number): void {
    const n = props.options.length;
    if (n === 0) {
        return;
    }
    dismissed.value = false;
    active.value = active.value < 0 ? (step > 0 ? 0 : n - 1) : (active.value + step + n) % n;
    nextTick(() =>
        document.getElementById(optionId(active.value))?.scrollIntoView({ block: 'nearest' }),
    );
}

function choose(index: number): void {
    const option = props.options[index];
    if (option === undefined) {
        return;
    }
    const label =
        document.getElementById(optionId(index))?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
    dismissed.value = true;
    active.value = -1;
    clearTimeout(statusTimer);
    status.value = label === '' ? 'Selected.' : `Selected: ${label}.`;
    emit('select', option);
    input.value?.focus();
}

function onKeydown(event: KeyboardEvent): void {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Enter':
            // Only a highlighted result is picked; otherwise Enter keeps its
            // ordinary meaning (submitting the form).
            if (open.value && active.value >= 0) {
                event.preventDefault();
                choose(active.value);
            }
            break;
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                dismissed.value = true;
                active.value = -1;
            }
            break;
    }
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        :id="id"
        ref="input"
        v-model="query"
        type="text"
        role="combobox"
        autocomplete="off"
        aria-autocomplete="list"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="listId"
        :aria-activedescendant="activeId"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="describedBy"
        v-bind="$attrs"
        @input="onInput"
        @keydown="onKeydown"
    />
    <ul v-show="open" :id="listId" role="listbox" :class="listClass">
        <!-- Combobox pattern: the keyboard stays on the input, which points at
             the highlighted option with aria-activedescendant; options are
             never tab stops, so their click is the pointer path only. -->
        <!-- eslint-disable-next-line vuejs-accessibility/click-events-have-key-events -->
        <li
            v-for="(option, index) in options"
            :id="optionId(index)"
            :key="optionKey(option)"
            role="option"
            tabindex="-1"
            :aria-selected="index === active ? 'true' : 'false'"
            :class="[
                optionClass,
                index === active
                    ? 'bg-slate-100 font-medium outline outline-2 -outline-offset-2 outline-amber-500'
                    : '',
            ]"
            @mousedown.prevent
            @click="choose(index)"
            @mousemove="active = index"
        >
            <slot name="option" :option="option" :active="index === active" />
        </li>
    </ul>
    <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
    <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-red-600">{{ error }}</p>
</template>
