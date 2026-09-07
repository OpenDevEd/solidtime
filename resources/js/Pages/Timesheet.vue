<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useBreaksEnabled } from '@/packages/ui/src/utils/useBreaksEnabled';
import AppLayout from '@/Layouts/AppLayout.vue';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';
import TimesheetHeader from '@/Components/Timesheet/TimesheetHeader.vue';
import TimesheetGrid from '@/Components/Timesheet/TimesheetGrid.vue';
import TimesheetFooterActions from '@/Components/Timesheet/TimesheetFooterActions.vue';
import RemoveRowDialog from '@/Components/Timesheet/RemoveRowDialog.vue';
import BreakPlacementModal from '@/Components/Timesheet/BreakPlacementModal.vue';
import { useTimesheetQuery } from '@/utils/useTimesheetQuery';
import { useTimesheetGrid, makeRowKey, type TimesheetRow } from '@/utils/useTimesheetGrid';
import TimesheetEntryDetails from '@/Components/Timesheet/TimesheetEntryDetails.vue';
import { useTimeEntriesMutations } from '@/utils/useTimeEntriesMutations';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useTagsQuery } from '@/utils/useTagsQuery';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { useProjectsStore } from '@/utils/useProjects';
import { useClientsStore } from '@/utils/useClients';
import { useTagsStore } from '@/utils/useTags';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { getOrganizationCurrencyString } from '@/utils/money';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { canCreateProjects } from '@/utils/permissions';
import {
    formatHumanReadableDuration,
    getLocalizedDateFromTimestamp,
    getLocalizedDayJs,
    getDayJsInstance,
} from '@/packages/ui/src/utils/time';
import { getBreakPlacementHint } from '@/packages/ui/src/utils/breakPlacement';
import { useTimesheetWeek } from '@/utils/timesheet/useTimesheetWeek';
import { useTimesheetCellMutations } from '@/utils/timesheet/useTimesheetCellMutations';
import { useTimesheetRowMutations } from '@/utils/timesheet/useTimesheetRowMutations';
import { useTimesheetRowDeletion } from '@/utils/timesheet/useTimesheetRowDeletion';
import { useCopyLastWeek } from '@/utils/timesheet/useCopyLastWeek';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import type { CreateClientBody, CreateProjectBody, Project, Client, Tag } from '@/packages/api/src';

// ── Week state ────────────────────────────────────────────────────
const {
    weekStart,
    weekEnd,
    weekDays,
    weekNumber,
    isCurrentWeek,
    todayDate,
    goToPreviousWeek,
    goToNextWeek,
    goToCurrentWeek,
} = useTimesheetWeek();

// ── Data fetching ─────────────────────────────────────────────────
// The query fetches one padding day on each side of the week so that entries
// crossing midnight at the week edges are known to the break-placement solver.
const { data, isPending } = useTimesheetQuery(weekStart, weekEnd);
const allTimeEntries = computed(() => data.value?.data ?? []);
// The grid and week-scoped features only see entries starting in the visible week.
const timeEntries = computed(() => {
    const weekDaySet = new Set(weekDays.value);
    return allTimeEntries.value.filter((entry) =>
        weekDaySet.has(getLocalizedDateFromTimestamp(entry.start))
    );
});

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { clients } = useClientsQuery();
const { tags } = useTagsQuery();
const { now: currentTimerNow } = storeToRefs(useCurrentTimeEntryStore());

const mutations = useTimeEntriesMutations();
const timerStore = useCurrentTimeEntryStore();
const timerBusy = ref(false);
const activeTimerKey = computed(() => {
    const e = timerStore.currentTimeEntry;
    return timerStore.isActive && e.organization_id === getCurrentOrganizationId()
        ? makeRowKey(e.project_id, e.task_id, e.billable, e.tags ?? [], e.type)
        : null;
});
const timerEnabled = computed(
    () =>
        isCurrentWeek.value &&
        (!timerStore.isActive ||
            timerStore.currentTimeEntry.organization_id === getCurrentOrganizationId())
);
async function toggleRowTimer(row: TimesheetRow) {
    if (timerBusy.value || !timerEnabled.value || (row.type === 'break' && !breaksEnabled.value))
        return;
    timerBusy.value = true;
    try {
        const stopping =
            activeTimerKey.value ===
            makeRowKey(row.projectId, row.taskId, row.billable, row.tags, row.type);
        if (timerStore.isActive) await timerStore.setActiveState(false);
        if (!stopping) {
            await mutations.createTimeEntry({
                start: getDayJsInstance()().utc().format(),
                description: '',
                project_id: row.projectId,
                task_id: row.taskId,
                billable: row.billable,
                tags: row.tags,
                type: row.type,
            });
        }
        await timerStore.fetchCurrentTimeEntry();
    } catch {
        // Shared API notifications report the failure.
    } finally {
        timerBusy.value = false;
    }
}
const showDetails = ref(false);
const detailCell = ref<{ key: string; dayIndex: number } | null>(null);
const detailEntries = computed(() =>
    detailCell.value
        ? (rows.value
              .find((r) => r.key === detailCell.value?.key)
              ?.cells.get(detailCell.value.dayIndex)?.entries ?? [])
        : []
);
const detailDate = computed(() =>
    detailCell.value ? (weekDays.value[detailCell.value.dayIndex] ?? '') : ''
);
const detailContext = computed(() => {
    const row = rows.value.find((row) => row.key === detailCell.value?.key);
    return [
        projects.value.find((project) => project.id === row?.projectId)?.name,
        tasks.value.find((task) => task.id === row?.taskId)?.name,
    ]
        .filter(Boolean)
        .join(' / ');
});
function openDetails(row: TimesheetRow, dayIndex: number) {
    detailCell.value = { key: row.key, dayIndex };
    showDetails.value = true;
}
async function saveNote(id: string, description: string): Promise<boolean> {
    const response = await mutations.updateTimeEntries({ ids: [id], changes: { description } });
    if (timerStore.currentTimeEntry.id === id) await timerStore.fetchCurrentTimeEntry();
    return response !== undefined && response.error.length === 0;
}
watch(weekStart, () => {
    showDetails.value = false;
});

const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);
const breaksEnabled = useBreaksEnabled(organization);

// ── Grid computation ──────────────────────────────────────────────
const {
    rows,
    dayTotals,
    grandTotal,
    breakDayTotals,
    breakGrandTotal,
    addSlot,
    removeSlot,
    updateSlot,
    clearSlots,
} = useTimesheetGrid(timeEntries, weekDays, projects, tasks, currentTimerNow, breaksEnabled);

// Wipe slots on week navigation so the new week starts fresh — the
// grid's watcher will reseed from the newly fetched entries.
// flush: 'sync' so the wipe happens the moment weekStart is assigned, BEFORE
// the same flush recomputes `timeEntries` (it depends on weekDays) and lets
// the grid seed the new week — otherwise a cached (prefetched) week seeds
// first, gets wiped here, and nothing re-triggers the seeding afterwards.
watch(weekStart, () => clearSlots(), { flush: 'sync' });

// ── Formatters ────────────────────────────────────────────────────
const intervalFormat = computed(() => organization.value?.interval_format ?? 'hours-minutes');
const numberFormat = computed(() => organization.value?.number_format ?? 'point');

function formatDuration(seconds: number): string {
    if (seconds === 0) return '-';
    return formatHumanReadableDuration(seconds, intervalFormat.value, numberFormat.value);
}

const weekTotalFormatted = computed(() =>
    formatHumanReadableDuration(grandTotal.value, intervalFormat.value, numberFormat.value)
);

const weekRangeDisplay = computed(() => {
    const start = weekStart.value;
    const end = start.add(6, 'day');
    return start.month() === end.month()
        ? `${start.format('MMM D')} - ${end.format('D')}`
        : `${start.format('MMM D')} - ${end.format('MMM D')}`;
});

// ── Cell / row mutation handlers ──────────────────────────────────
const {
    handleCellUpdate,
    cellStatus,
    cellPendingSeconds,
    breakPlacementRequest,
    applyBreakPlacement,
    dismissBreakPlacement,
} = useTimesheetCellMutations(
    weekDays,
    allTimeEntries,
    rows,
    removeSlot,
    () => organization.value?.prevent_overlapping_time_entries ?? false
);

function breakPlanEntryLabel(id: string): string {
    const entry = allTimeEntries.value.find((e) => e.id === id);
    if (!entry) return '';
    if (entry.type === 'break') return 'Break';
    const project = projects.value.find((p) => p.id === entry.project_id);
    const task = tasks.value.find((t) => t.id === entry.task_id);
    return [project?.name ?? 'No Project', task?.name, entry.description]
        .filter((part): part is string => !!part)
        .join(' · ');
}

// Local dates (YYYY-MM-DD) that have a misplaced break. There is only one break
// row, so a flat set is enough — its cells show a warning for dates in the set.
const misplacedBreakDates = computed<Set<string>>(() => {
    const dates = new Set<string>();
    for (const entry of timeEntries.value) {
        if (entry.type !== 'break') continue;
        // Hint against the padded list so work just across midnight counts.
        if (getBreakPlacementHint(entry, allTimeEntries.value)?.misplaced) {
            dates.add(getLocalizedDayJs(entry.start).format('YYYY-MM-DD'));
        }
    }
    return dates;
});

const { handleRowIdentityChange, handleAddRow } = useTimesheetRowMutations(
    mutations,
    projects,
    rows,
    addSlot,
    updateSlot,
    removeSlot
);

const {
    showDeleteDialog,
    deleteRowEntryCount,
    deleteRowProjectName,
    requestRemoveRow,
    confirmDeleteRow,
} = useTimesheetRowDeletion(projects, mutations, removeSlot);

function handleRemoveRow(key: string) {
    const row = rows.value.find((r) => r.key === key);
    if (row) requestRemoveRow(row);
}

// ── Copy last week ────────────────────────────────────────────────
const { isCopyingLastWeek, copyLastWeekRows, copyLastWeekWithTime } = useCopyLastWeek(
    weekStart,
    weekDays,
    rows,
    timeEntries,
    addSlot,
    breaksEnabled
);

// ── Inline creation helpers (passed to TimesheetRow) ──────────────
async function createProject(project: CreateProjectBody): Promise<Project | undefined> {
    return await useProjectsStore().createProject(project);
}

async function createClient(body: CreateClientBody): Promise<Client | undefined> {
    return await useClientsStore().createClient(body);
}

async function createTag(name: string): Promise<Tag | undefined> {
    return await useTagsStore().createTag(name);
}
</script>

<template>
    <AppLayout title="Timesheet" data-testid="timesheet_view">
        <div class="pt-5 lg:pt-8 pb-4 lg:pb-6">
            <TimesheetHeader
                :is-current-week="isCurrentWeek"
                :week-number="weekNumber"
                :week-range-display="weekRangeDisplay"
                :week-total-formatted="weekTotalFormatted"
                @previous="goToPreviousWeek"
                @next="goToNextWeek"
                @current="goToCurrentWeek" />

            <TimesheetGrid
                v-if="!isPending"
                :rows="rows"
                :week-days="weekDays"
                :today-date="todayDate"
                :day-totals="dayTotals"
                :week-total-formatted="weekTotalFormatted"
                :break-day-totals="breakDayTotals"
                :break-grand-total="breakGrandTotal"
                :projects="projects"
                :tasks="tasks"
                :clients="clients"
                :tags="tags"
                :currency="getOrganizationCurrencyString()"
                :can-create-project="canCreateProjects()"
                :enable-estimated-time="isAllowedToPerformPremiumAction()"
                :create-project="createProject"
                :create-client="createClient"
                :create-tag="createTag"
                :format-duration="formatDuration"
                :cell-statuses="cellStatus"
                :cell-pending-seconds="cellPendingSeconds"
                :misplaced-break-dates="misplacedBreakDates"
                :active-timer-key="activeTimerKey"
                :timer-busy="timerBusy"
                :timer-enabled="timerEnabled"
                @details="openDetails"
                @timer="toggleRowTimer"
                @remove-row="handleRemoveRow"
                @cell-update="handleCellUpdate"
                @project-task-change="
                    (row, projectId, taskId) => handleRowIdentityChange(row, { projectId, taskId })
                "
                @billable-change="(row, billable) => handleRowIdentityChange(row, { billable })"
                @tags-change="(row, tags) => handleRowIdentityChange(row, { tags })"
                @add-row="handleAddRow" />

            <TimesheetFooterActions
                v-if="!isPending"
                :busy="isCopyingLastWeek"
                @copy-rows="copyLastWeekRows"
                @copy-with-time="copyLastWeekWithTime" />

            <div v-else class="flex justify-center items-center py-12">
                <LoadingSpinner />
            </div>
        </div>

        <RemoveRowDialog
            v-model:open="showDeleteDialog"
            :entry-count="deleteRowEntryCount"
            :project-name="deleteRowProjectName"
            @confirm="confirmDeleteRow" />
        <TimesheetEntryDetails
            v-model:show="showDetails"
            :entries="detailEntries"
            :date="detailDate"
            :context="detailContext"
            :format-duration="formatDuration"
            :save-note="saveNote" />

        <BreakPlacementModal
            :request="breakPlacementRequest"
            :apply="applyBreakPlacement"
            :entry-label="breakPlanEntryLabel"
            @cancel="dismissBreakPlacement" />
    </AppLayout>
</template>
