<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId, getCurrentRole } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';
import Card from '@/Components/Common/Card.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import HarvestPlanReview from './HarvestPlanReview.vue';

interface Person {
    source_id: number;
    name: string;
    email: string;
    active: boolean;
    match: string;
    candidates: { id: string; name: string; email: string; role: string }[];
}
interface Summary {
    can_import?: boolean;
    company?: string;
    progress?: string;
    collected?: number;
    counts?: Record<string, number>;
    people?: Person[];
    existing_time_entries?: number;
    projects_active?: number;
    projects_archived?: number;
    time?: {
        seconds: number;
        zero: number;
        negative: number;
        running: number;
        missing_relations: number;
        currencies: Record<string, { entries: number; seconds: number }>;
    };
    warnings?: string[];
}
interface Run {
    id: string;
    status:
        | 'queued'
        | 'fetching'
        | 'ready'
        | 'planning'
        | 'planned'
        | 'apply_queued'
        | 'applying'
        | 'completed'
        | 'failed';
    summary: Summary | null;
    error: string | null;
}
const configured = ref(false);
const run = ref<Run | null>(null);
const submitting = ref(false);
const pollingError = ref(false);
const isAdmin = computed(() => ['owner', 'admin'].includes(getCurrentRole() ?? ''));
const busy = computed(() =>
    ['queued', 'fetching', 'planning', 'apply_queued', 'applying'].includes(run.value?.status ?? '')
);
const endpoint = `/v1/organizations/${getCurrentOrganizationId()}/harvest-imports`;
const { handleApiRequestNotifications } = useNotificationsStore();
let timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;

function schedulePoll() {
    if (!disposed && busy.value) timer = setTimeout(refresh, 2500);
}
async function refresh() {
    if (timer) clearTimeout(timer);
    try {
        const response = await api.axios.get<{ configured: boolean; run: Run | null }>(endpoint);
        if (disposed) return;
        configured.value = response.data.configured;
        run.value = response.data.run;
        pollingError.value = false;
        schedulePoll();
    } catch {
        pollingError.value = true;
    }
}
async function preview() {
    submitting.value = true;
    try {
        const response = await handleApiRequestNotifications(() =>
            api.axios.post<{ run: Run }>(endpoint)
        );
        run.value = response.data.run;
        schedulePoll();
    } catch {
        // The shared request handler displays the error.
    } finally {
        submitting.value = false;
    }
}
onMounted(() => {
    if (isAdmin.value) void refresh();
});
onUnmounted(() => {
    disposed = true;
    if (timer) clearTimeout(timer);
});
</script>

<template>
    <Card v-if="isAdmin" class="mb-4">
        <div class="px-4 py-5 sm:px-5 space-y-4">
            <h3 class="text-lg font-semibold text-text-primary">Harvest API import</h3>
            <p class="text-sm text-text-secondary">
                Fetch Harvest data, review matches and field changes, then confirm the import.
                Harvest stays read-only. Nothing in this organization changes until confirmation.
            </p>
            <p v-if="pollingError" role="alert" class="text-sm">
                Could not load the preview status.
                <button type="button" class="underline" @click="refresh">Retry</button>
            </p>
            <p v-else-if="!configured" class="text-sm text-text-secondary">
                The Harvest connection is not configured for this organization.
            </p>
            <template v-if="configured">
                <PrimaryButton :disabled="busy || submitting" @click="preview">
                    {{ busy ? 'Import worker is busy…' : 'Fetch Harvest preview' }}
                </PrimaryButton>
                <p v-if="busy" role="status" class="text-sm">
                    {{ run?.status }}
                    {{ run?.summary?.progress }}
                    <span v-if="run?.summary?.collected">{{ run.summary.collected }} records</span>
                </p>
                <p v-if="run?.error" role="alert" class="text-sm">{{ run.error }}</p>
                <template v-if="run?.summary?.counts && run.summary">
                    <h4 class="font-semibold">{{ run.summary.company }}</h4>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        <template v-for="(count, entity) in run.summary.counts" :key="entity">
                            <dt>{{ entity.replaceAll('_', ' ') }}</dt>
                            <dd>{{ count.toLocaleString() }}</dd>
                        </template>
                    </dl>
                    <p class="text-sm">
                        Projects: {{ run.summary.projects_active }} active,
                        {{ run.summary.projects_archived }} archived.
                    </p>
                    <p class="text-sm">
                        {{ run.summary.existing_time_entries?.toLocaleString() }} time entries
                        existed in this organization when the snapshot was fetched.
                    </p>
                    <div v-if="run.summary.time" class="text-sm space-y-1">
                        <p>Total hours: {{ (run.summary.time.seconds / 3600).toLocaleString() }}</p>
                        <p>
                            Zero-hour entries: {{ run.summary.time.zero }} · Negative adjustments:
                            {{ run.summary.time.negative }} · Running timers:
                            {{ run.summary.time.running }} · Missing relations:
                            {{ run.summary.time.missing_relations }}
                        </p>
                        <p
                            v-for="(totals, currency) in run.summary.time.currencies"
                            :key="currency">
                            {{ currency }}: {{ totals.entries.toLocaleString() }} entries,
                            {{ (totals.seconds / 3600).toLocaleString() }} hours
                        </p>
                    </div>
                    <details>
                        <summary class="cursor-pointer font-medium">Review people matching</summary>
                        <div class="overflow-x-auto mt-3">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Match</th>
                                        <th>Existing account candidates</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="person in run.summary.people"
                                        :key="person.source_id"
                                        class="border-t border-card-background-separator">
                                        <td class="py-2 pr-3">{{ person.name }}</td>
                                        <td class="pr-3">{{ person.email }}</td>
                                        <td class="pr-3">
                                            {{ person.active ? 'Active' : 'Archived' }}
                                        </td>
                                        <td>{{ person.match }}</td>
                                        <td class="py-2 pl-3">
                                            <div
                                                v-for="candidate in person.candidates"
                                                :key="candidate.id">
                                                {{ candidate.name }} · {{ candidate.email }} ·
                                                {{ candidate.role }}
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                    <ul class="list-disc pl-5 text-sm text-text-secondary space-y-1">
                        <li v-for="warning in run.summary.warnings" :key="warning">
                            {{ warning }}
                        </li>
                    </ul>
                </template>
                <HarvestPlanReview
                    v-if="run && !busy"
                    :key="`${run.id}:${run.status}`"
                    :run="run"
                    :endpoint="endpoint"
                    @updated="refresh" />
            </template>
        </div>
    </Card>
</template>
