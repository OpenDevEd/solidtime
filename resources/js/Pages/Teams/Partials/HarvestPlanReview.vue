<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    applyHarvestFieldChoice,
    groupHarvestConflicts,
    type HarvestConflict,
} from '@/utils/harvestReview';
import { api } from '@/packages/api/src';
import { useNotificationsStore } from '@/utils/notification';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';

interface Plan {
    hash: string;
    counts: Record<string, Record<string, number>>;
    conflicts: HarvestConflict[];
    unmatched_existing: number;
    skipped_running: number;
}
interface Run {
    id: string;
    status: string;
    decisions?: Record<string, string>;
    summary: {
        plan?: Plan;
        can_import?: boolean;
        result?: Record<string, Record<string, number>>;
    } | null;
}
interface Change {
    entity: string;
    source_id: string;
    action: string;
    label: string;
    changes: Record<string, { before: unknown; after: unknown }>;
    after: Record<string, unknown>;
}
const props = defineProps<{ run: Run; endpoint: string }>();
const emit = defineEmits<{ updated: [] }>();
const decisions = ref<Record<string, string>>({ ...(props.run.decisions ?? {}) });
const showResolved = ref(false);
const conflicts = computed(() => groupHarvestConflicts(props.run.summary?.plan?.conflicts ?? []));
const unresolved = computed(() =>
    conflicts.value.filter((conflict) => !decisions.value[conflict.key])
);
const visibleConflicts = computed(() => (showResolved.value ? conflicts.value : unresolved.value));
const fieldConflicts = computed(() =>
    unresolved.value.filter(
        (conflict) =>
            conflict.candidates.length === 0 &&
            conflict.choices.includes('local') &&
            conflict.choices.includes('source')
    )
);
watch(
    () => props.run.id,
    () => {
        decisions.value = { ...(props.run.decisions ?? {}) };
        acknowledged.value = false;
        showResolved.value = false;
    }
);
function applyFieldChoice(choice: 'local' | 'source') {
    decisions.value = applyHarvestFieldChoice(conflicts.value, decisions.value, choice);
}
const acknowledged = ref(false);
const submitting = ref(false);
const changes = ref<Change[]>([]);
const page = ref(1);
const total = ref(0);
const { handleApiRequestNotifications } = useNotificationsStore();
const choiceLabels: Record<string, string> = {
    create: 'Create a separate record',
    local: 'Keep the Solidtime value',
    source: 'Use the Harvest value',
    keep: 'Keep unmatched local entries',
};
function choiceLabel(choice: string) {
    return choice.startsWith('alias:')
        ? `Use the same person as Harvest identity ${choice.slice(6)}`
        : (choiceLabels[choice] ?? choice);
}

async function submit(action: 'plan' | 'confirm') {
    if (action === 'confirm' && !acknowledged.value) return;
    submitting.value = true;
    try {
        await handleApiRequestNotifications(() =>
            api.axios.post(
                `${props.endpoint}/${props.run.id}/${action}`,
                action === 'plan'
                    ? { decisions: decisions.value }
                    : {
                          plan_hash: props.run.summary?.plan?.hash,
                          acknowledge_limitations: acknowledged.value,
                      }
            )
        );
        emit('updated');
    } catch {
        // Shared handler displays the request error.
    } finally {
        submitting.value = false;
    }
}
async function loadChanges(nextPage = 1) {
    submitting.value = true;
    try {
        const response = await handleApiRequestNotifications(() =>
            api.axios.get<{ data: Change[]; total: number }>(
                `${props.endpoint}/${props.run.id}/changes`,
                { params: { page: nextPage } }
            )
        );
        changes.value = response.data.data;
        page.value = nextPage;
        total.value = response.data.total;
    } catch {
        // Shared handler displays the request error.
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <p v-if="run.status === 'completed'" role="status" class="font-semibold">
            Import completed. These results have been applied.
        </p>
        <template v-if="run.summary?.plan">
            <h4 class="font-semibold">
                {{ run.status === 'completed' ? 'Import results' : 'Planned changes' }}
            </h4>
            <p v-if="run.summary.plan.conflicts.length" class="text-sm">
                {{ unresolved.length }} decisions remaining ·
                {{ conflicts.length - unresolved.length }} selected. Rebuild the plan to validate
                your selections before importing.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr>
                            <th>Records</th>
                            <th>Create</th>
                            <th>Link</th>
                            <th>Alias</th>
                            <th>Update</th>
                            <th>Unchanged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(counts, entity) in run.summary.result ??
                            run.summary.plan.counts"
                            :key="entity"
                            class="border-t border-card-background-separator">
                            <td class="py-2">{{ entity.replaceAll('_', ' ') }}</td>
                            <td>{{ counts.create ?? 0 }}</td>
                            <td>{{ counts.link ?? 0 }}</td>
                            <td>{{ counts.alias ?? 0 }}</td>
                            <td>{{ counts.update ?? 0 }}</td>
                            <td>{{ counts.unchanged ?? 0 }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <template v-if="run.status === 'planned' && run.summary.plan.conflicts.length">
                <div
                    v-if="fieldConflicts.length"
                    class="rounded-lg border border-card-border p-4 space-y-3">
                    <p class="text-sm">
                        {{ fieldConflicts.length }} records have changes in both systems. Choose a
                        policy for the remaining records, or review them individually below.
                        Existing selections are preserved.
                    </p>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <button
                            type="button"
                            class="underline"
                            :disabled="submitting"
                            @click="applyFieldChoice('local')">
                            Keep Solidtime for all {{ fieldConflicts.length }}
                        </button>
                        <button
                            type="button"
                            class="underline"
                            :disabled="submitting"
                            @click="applyFieldChoice('source')">
                            Use Harvest for all {{ fieldConflicts.length }}
                        </button>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="showResolved" type="checkbox" />Show selected decisions for
                    review or editing</label
                >
                <div
                    v-for="(conflict, index) in visibleConflicts"
                    :key="`${conflict.key}:${index}`"
                    class="border-t border-card-background-separator pt-3 space-y-1">
                    <label :for="`harvest-conflict-${index}`" class="font-medium text-sm">{{
                        conflict.label
                    }}</label>
                    <p class="text-sm text-text-secondary">{{ conflict.message }}</p>
                    <select
                        v-if="conflict.choices.length || conflict.candidates.length"
                        :id="`harvest-conflict-${index}`"
                        v-model="decisions[conflict.key]"
                        class="w-full rounded-md border-input-border bg-input-background text-text-primary">
                        <option :value="undefined" disabled>Choose a resolution</option>
                        <option
                            v-for="candidate in conflict.candidates"
                            :key="candidate.id"
                            :value="candidate.id">
                            {{ candidate.name }} {{ candidate.email ? `(${candidate.email})` : '' }}
                        </option>
                        <option v-for="choice in conflict.choices" :key="choice" :value="choice">
                            {{ choiceLabel(choice) }}
                        </option>
                    </select>
                    <ul
                        v-if="conflict.key.startsWith('projects:')"
                        class="mt-2 space-y-2 text-xs text-text-secondary">
                        <li v-for="candidate in conflict.candidates" :key="candidate.id">
                            <span class="font-medium">{{ candidate.name }}</span>
                            <span class="block">{{ candidate.evidence?.join(' · ') }}</span>
                        </li>
                    </ul>
                </div>
            </template>
            <button
                type="button"
                class="underline text-sm"
                :disabled="submitting"
                @click="loadChanges()">
                Review field changes
            </button>
            <div v-if="changes.length" class="space-y-2">
                <p class="text-sm">
                    Page {{ page }} · {{ total.toLocaleString() }} changed or linked records
                </p>
                <details
                    v-for="change in changes"
                    :key="`${change.entity}:${change.source_id}`"
                    class="border-t border-card-background-separator pt-2">
                    <summary class="cursor-pointer text-sm">
                        {{ change.action }} · {{ change.entity }} · {{ change.label }}
                    </summary>
                    <pre class="text-xs whitespace-pre-wrap break-all p-2">{{
                        JSON.stringify(
                            change.action === 'update' ? change.changes : change.after,
                            null,
                            2
                        )
                    }}</pre>
                </details>
                <div class="flex gap-4 text-sm">
                    <button
                        type="button"
                        :disabled="page <= 1 || submitting"
                        @click="loadChanges(page - 1)">
                        Previous
                    </button>
                    <button
                        type="button"
                        :disabled="page * 100 >= total || submitting"
                        @click="loadChanges(page + 1)">
                        Next
                    </button>
                </div>
            </div>
        </template>
        <PrimaryButton
            v-if="['ready', 'planned', 'failed'].includes(run.status)"
            :disabled="submitting"
            @click="submit('plan')">
            {{ run.summary?.plan ? 'Rebuild plan with these decisions' : 'Build import plan' }}
        </PrimaryButton>
        <template v-if="run.status === 'planned' && run.summary?.can_import">
            <label class="flex items-start gap-2 text-sm"
                ><input v-model="acknowledged" type="checkbox" class="mt-1" />I reviewed the changes
                and limitations. Apply this plan to this organization, including the displayed
                updates to existing records.</label
            >
            <PrimaryButton :disabled="!acknowledged || submitting" @click="submit('confirm')"
                >Confirm import</PrimaryButton
            >
        </template>
    </div>
</template>
