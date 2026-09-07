<script setup lang="ts">
import { computed, inject, type ComputedRef } from 'vue';
import { useBreaksEnabled } from '@/packages/ui/src/utils/useBreaksEnabled';
import { XMarkIcon } from '@heroicons/vue/16/solid';
import { Coffee } from '@lucide/vue';
import TimesheetCell from './TimesheetCell.vue';
import TimeTrackerProjectTaskDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import TimeEntryRowTagDropdown from '@/packages/ui/src/TimeEntry/TimeEntryRowTagDropdown.vue';
import BillableToggleButton from '@/packages/ui/src/Input/BillableToggleButton.vue';
import type {
    CreateClientBody,
    CreateProjectBody,
    Project,
    Task,
    Client,
    Tag,
    Organization,
} from '@/packages/api/src';
import type { TimesheetRow, TimesheetRowKey } from '@/utils/useTimesheetGrid';
import {
    makeCellStatusKey,
    type CellSaveStatus,
} from '@/utils/timesheet/useTimesheetCellMutations';
import { Button } from '@/packages/ui/src/Buttons';
import { makeRowKey } from '@/utils/useTimesheetGrid';
import { PlayIcon, StopIcon } from '@heroicons/vue/16/solid';

const organization = inject<ComputedRef<Organization>>('organization');
const breaksEnabled = useBreaksEnabled();

const props = defineProps<{
    row: TimesheetRow;
    weekDays: string[];
    todayDate: string;
    projects: Project[];
    tasks: Task[];
    clients: Client[];
    tags: Tag[];
    currency: string;
    canCreateProject: boolean;
    enableEstimatedTime: boolean;
    createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
    createClient: (client: CreateClientBody) => Promise<Client | undefined>;
    createTag: (name: string) => Promise<Tag | undefined>;
    formatDuration: (seconds: number) => string;
    cellStatuses: Record<string, CellSaveStatus>;
    cellPendingSeconds: Record<string, number>;
    activeTimerKey: string | null;
    timerBusy: boolean;
    timerEnabled: boolean;
}>();

const emit = defineEmits<{
    removeRow: [key: TimesheetRowKey];
    cellUpdate: [dayIndex: number, newSeconds: number];
    projectTaskChange: [projectId: string | null, taskId: string | null];
    billableChange: [billable: boolean];
    tagsChange: [tags: string[]];
    details: [dayIndex: number];
    timer: [];
}>();

const selectedProject = computed({
    get: () => props.row.projectId,
    set: (val) => emit('projectTaskChange', val, selectedTask.value),
});

const selectedTask = computed({
    get: () => props.row.taskId,
    set: (val) => emit('projectTaskChange', selectedProject.value, val),
});

const rowTotalFormatted = computed(() => props.formatDuration(props.row.totalSeconds));

// A break row can survive after breaks are disabled (its entries are
// grandfathered). Those cells become read-only — creating/editing break time is
// rejected server-side — leaving the remove button as the only action.
const cellsReadonly = computed(() => props.row.type === 'break' && !breaksEnabled.value);
const timerActive = computed(
    () =>
        props.activeTimerKey ===
        makeRowKey(
            props.row.projectId,
            props.row.taskId,
            props.row.billable,
            props.row.tags,
            props.row.type
        )
);

function hasRunningEntry(dayIndex: number): boolean {
    const cell = props.row.cells.get(dayIndex);
    if (!cell) return false;
    return cell.entries.some((e) => e.end === null);
}
</script>

<template>
    <div data-testid="timesheet_row" class="contents group">
        <!-- Project/Task column -->
        <div
            class="flex items-center gap-1 border-t border-default-background-separator bg-default-background pl-4 pr-3 py-2 md:sticky md:left-0 md:z-10">
            <div
                v-if="row.type === 'break'"
                class="flex flex-1 items-center gap-1.5 min-w-0 px-2 py-1 text-sm text-text-secondary">
                <Coffee class="w-4 h-4" />
                <span>Break</span>
            </div>
            <div v-else class="flex-1 min-w-0">
                <TimeTrackerProjectTaskDropdown
                    v-model:project="selectedProject"
                    v-model:task="selectedTask"
                    :projects="projects"
                    :tasks="tasks"
                    :clients="clients"
                    :currency="currency"
                    :can-create-project="canCreateProject"
                    :enable-estimated-time="enableEstimatedTime"
                    :create-project="createProject"
                    :create-client="createClient"
                    :organization-billable-rate="organization?.billable_rate ?? null"
                    :no-project-value="null"
                    variant="ghost"
                    size="sm"
                    class="w-full">
                    <template #trigger="{ projectName, projectColor, taskName, clientName }">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-auto min-h-11 w-full min-w-0 justify-start gap-2 overflow-hidden py-1.5 text-left"
                            :title="
                                [projectName, taskName, clientName].filter(Boolean).join(' / ')
                            ">
                            <span
                                class="h-2.5 w-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: projectColor }" />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium text-text-primary"
                                    >{{ projectName }}</span
                                >
                                <span
                                    v-if="taskName || clientName"
                                    class="mt-0.5 block truncate text-xs font-normal text-text-secondary">
                                    {{ [taskName, clientName].filter(Boolean).join(' · ') }}
                                </span>
                            </span>
                        </Button>
                    </template>
                </TimeTrackerProjectTaskDropdown>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0 ml-auto">
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="timerBusy || !timerEnabled || cellsReadonly"
                    :aria-label="timerActive ? 'Stop row timer' : 'Start row timer for today'"
                    :title="timerActive ? 'Stop timer' : 'Start a new entry now for today'"
                    @click="emit('timer')">
                    <StopIcon v-if="timerActive" class="w-4 h-4 text-red-500" />
                    <PlayIcon v-else class="w-4 h-4" />
                </Button>
                <TimeEntryRowTagDropdown
                    v-if="row.type !== 'break'"
                    :create-tag="createTag"
                    :tags="tags"
                    :model-value="row.tags"
                    @changed="emit('tagsChange', $event)" />
                <BillableToggleButton
                    v-if="row.type !== 'break'"
                    :model-value="row.billable"
                    size="small"
                    faded
                    @changed="emit('billableChange', $event)" />
            </div>
        </div>

        <!-- Day cells -->
        <TimesheetCell
            v-for="(day, dayIndex) in weekDays"
            :key="day"
            :cell="row.cells.get(dayIndex)"
            :day-index="dayIndex"
            :date="day"
            :is-today="day === todayDate"
            :has-running-entry="hasRunningEntry(dayIndex)"
            :readonly="cellsReadonly"
            :save-status="cellStatuses[makeCellStatusKey(row.key, dayIndex)]"
            :pending-seconds="cellPendingSeconds[makeCellStatusKey(row.key, dayIndex)]"
            @details="emit('details', dayIndex)"
            @update="(seconds) => emit('cellUpdate', dayIndex, seconds)" />

        <!-- Row total -->
        <div
            data-testid="timesheet_row_total"
            class="flex items-center justify-end border-t border-default-background-separator pl-3 pr-3 py-3 text-sm font-medium text-text-primary">
            {{ rowTotalFormatted }}
        </div>

        <!-- Remove action (the break row is permanent while breaks are enabled) -->
        <div
            class="flex items-center justify-center border-t border-default-background-separator pr-4 py-3">
            <Button
                v-if="!(row.type === 'break' && breaksEnabled)"
                variant="ghost"
                size="icon"
                aria-label="Remove row"
                class="h-6 w-6 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
                @click="emit('removeRow', row.key)">
                <XMarkIcon class="h-3.5 w-3.5 text-icon-default" />
            </Button>
        </div>
    </div>
</template>
