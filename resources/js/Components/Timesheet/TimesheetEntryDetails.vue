<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { DocumentTextIcon } from '@heroicons/vue/24/outline';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import type { TimeEntry } from '@/packages/api/src';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';

const show = defineModel('show', { default: false });
const props = defineProps<{
    entries: TimeEntry[];
    date: string;
    context?: string;
    formatDuration: (seconds: number) => string;
    saveNote: (id: string, description: string) => Promise<boolean>;
}>();
const drafts = ref<Record<string, string>>({});
const baseline = ref<Record<string, string>>({});
const saving = ref(false);
const error = ref(false);
const dirtyEntries = computed(() =>
    props.entries.filter((entry) => drafts.value[entry.id] !== baseline.value[entry.id])
);
watch(
    show,
    (open) => {
        if (open) {
            drafts.value = Object.fromEntries(
                props.entries.map((e) => [e.id, e.description ?? ''])
            );
            baseline.value = { ...drafts.value };
            error.value = false;
        }
    },
    { immediate: true }
);
async function save() {
    if (saving.value) return;
    saving.value = true;
    error.value = false;
    try {
        for (const entry of dirtyEntries.value) {
            const description = drafts.value[entry.id] ?? '';
            if (!(await props.saveNote(entry.id, description))) {
                error.value = true;
                return;
            }
            baseline.value[entry.id] = description;
        }
        show.value = false;
    } catch {
        error.value = true;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <DialogModal
        :show="show"
        max-width="lg"
        :closeable="!saving && !dirtyEntries.length"
        @close="show = false">
        <template #title>
            <div class="flex items-center gap-3">
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-default-background text-text-secondary">
                    <DocumentTextIcon class="h-5 w-5" aria-hidden="true" />
                </div>
                <div>
                    <h2 class="text-base font-semibold">Notes</h2>
                    <p class="mt-0.5 text-sm font-normal text-text-secondary">
                        {{ date ? getLocalizedDayJs(date).format('dddd, D MMMM') : '' }}
                    </p>
                </div>
            </div>
        </template>
        <template #content>
            <div class="space-y-4 max-h-[60vh] overflow-y-auto p-1">
                <p v-if="context" class="text-sm font-medium text-text-primary break-words">
                    {{ context }}
                </p>
                <p v-if="entries.length > 1" class="text-xs text-text-secondary">
                    This day has {{ entries.length }} time entries. Add notes to each below.
                </p>
                <section v-for="entry in entries" :key="entry.id" class="space-y-2">
                    <label
                        :for="`entry-note-${entry.id}`"
                        class="flex items-center justify-between gap-3 text-xs text-text-secondary">
                        <span>
                            {{ getLocalizedDayJs(entry.start).format('HH:mm') }}
                            <template v-if="entry.end"
                                >–{{ getLocalizedDayJs(entry.end).format('HH:mm') }}</template
                            >
                        </span>
                        <span class="rounded-md bg-default-background px-2 py-1 tabular-nums">{{
                            entry.end ? formatDuration(entry.duration ?? 0) : 'Running'
                        }}</span>
                        <span class="sr-only">Notes</span>
                    </label>
                    <textarea
                        :id="`entry-note-${entry.id}`"
                        v-model="drafts[entry.id]"
                        :rows="entries.length > 1 ? 3 : 5"
                        maxlength="5000"
                        :disabled="saving"
                        class="block w-full resize-y rounded-xl border border-input-border bg-input-background px-4 py-3 text-sm leading-relaxed text-text-primary placeholder:text-text-quaternary focus:border-transparent focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-60"
                        placeholder="What did you work on?" />
                </section>
                <p v-if="!entries.length" class="text-sm text-text-secondary">
                    No entries in this cell.
                </p>
                <p v-if="error" role="alert" class="text-sm text-red-500">
                    Couldn't save all notes. Your changes are still here. Try again.
                </p>
            </div>
        </template>
        <template #footer>
            <div class="flex items-center gap-2">
                <SecondaryButton :disabled="saving" @click="show = false">Cancel</SecondaryButton>
                <PrimaryButton
                    data-testid="save-notes"
                    :disabled="saving || !dirtyEntries.length"
                    @click="save">
                    {{ saving ? 'Saving…' : 'Save notes' }}
                </PrimaryButton>
            </div>
        </template>
    </DialogModal>
</template>
