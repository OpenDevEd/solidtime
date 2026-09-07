<script setup lang="ts">
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { storeToRefs } from 'pinia';
import { computed } from 'vue';
import dayjs from 'dayjs';
import { formatDuration } from '@/packages/ui/src/utils/time';
import TimeTrackerStartStop from '@/packages/ui/src/TimeTrackerStartStop.vue';
import { getCurrentOrganizationId } from '@/utils/useUser';
import TimeTrackerProjectTaskDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { Button } from '@/packages/ui/src/Buttons';
import { ChevronDownIcon } from '@heroicons/vue/16/solid';
import { ref } from 'vue';

const store = useCurrentTimeEntryStore();
const { currentTimeEntry, now, isActive } = storeToRefs(store);
const { setActiveState } = store;
const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { clients } = useClientsQuery();
const busy = ref(false);
const selectedProject = computed(() =>
    projects.value.find((p) => p.id === currentTimeEntry.value.project_id)
);
const canStart = computed(() => !!selectedProject.value && !selectedProject.value.is_archived);

async function changeProject() {
    if (selectedProject.value) currentTimeEntry.value.billable = selectedProject.value.is_billable;
    if (currentTimeEntry.value.id) await store.updateTimer();
}

async function toggleTimer(active: boolean) {
    if (busy.value || (active && !canStart.value)) return;
    busy.value = true;
    try {
        await setActiveState(active);
    } catch {
        // The shared timer store displays the request error.
    } finally {
        busy.value = false;
    }
}

const currentTime = computed(() => {
    if (now.value && currentTimeEntry.value.start) {
        const startTime = dayjs(currentTimeEntry.value.start);
        const diff = now.value.diff(startTime, 's');
        return formatDuration(diff);
    }
    return formatDuration(0);
});

const isRunningInDifferentOrganization = computed(() => {
    return (
        currentTimeEntry.value.organization_id &&
        getCurrentOrganizationId() &&
        currentTimeEntry.value.organization_id !== getCurrentOrganizationId()
    );
});
</script>

<template>
    <div class="my-3 mx-1 rounded-lg bg-quaternary/50 p-2 relative">
        <div
            v-if="isRunningInDifferentOrganization"
            class="absolute w-full h-full backdrop-blur-sm z-10 flex items-center justify-center">
            <div
                class="w-full h-[calc(100%+10px)] absolute bg-default-background opacity-75 backdrop-blur-sm"></div>
            <div class="flex space-x-3 items-center w-full z-20 justify-center">
                <span class="text-xs text-center text-text-primary">
                    The Timer is running in a different organization.
                </span>
            </div>
        </div>
        <div class="min-w-0">
            <TimeTrackerProjectTaskDropdown
                v-model:project="currentTimeEntry.project_id"
                v-model:task="currentTimeEntry.task_id"
                :projects="projects"
                :tasks="tasks"
                :clients="clients"
                :can-create-project="false"
                :enable-estimated-time="false"
                :organization-billable-rate="null"
                :create-project="async () => undefined"
                :create-client="async () => undefined"
                :allow-no-project="false"
                currency=""
                empty-placeholder="Select project"
                align="start"
                @changed="changeProject">
                <template #trigger="{ projectName, taskName, projectColor }">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-auto w-full min-w-0 items-start justify-between gap-2 px-1 py-1.5 text-left text-xs hover:bg-quaternary"
                        :title="[projectName, taskName].filter(Boolean).join(' / ')"
                        aria-label="Select timer project">
                        <span
                            class="mt-1 size-2 shrink-0 rounded-full bg-text-tertiary"
                            :style="projectColor ? { backgroundColor: projectColor } : undefined" />
                        <span class="min-w-0 flex-1">
                            <span
                                class="line-clamp-2 whitespace-normal break-words font-medium leading-4 text-text-primary"
                                >{{
                                    projectName ||
                                    (currentTimeEntry.project_id
                                        ? 'Loading project…'
                                        : 'Select project')
                                }}</span
                            >
                            <span
                                v-if="taskName"
                                class="mt-1 block truncate font-normal text-text-tertiary"
                                >{{ taskName }}</span
                            >
                        </span>
                        <ChevronDownIcon class="mt-0.5 size-3.5 shrink-0 text-text-tertiary" />
                    </Button>
                </template>
            </TimeTrackerProjectTaskDropdown>
            <div class="mt-2 flex items-center gap-3 border-t border-card-border px-1 pt-3 pb-1">
                <TimeTrackerStartStop
                    :active="isActive"
                    :disabled="busy || (!isActive && !canStart)"
                    :aria-label="isActive ? 'Stop timer' : 'Start timer'"
                    :title="!isActive && !canStart ? 'Select a project to start' : undefined"
                    class="shrink-0 disabled:opacity-40 disabled:cursor-not-allowed"
                    size="base"
                    :variant="isActive ? 'primary' : 'secondary'"
                    @changed="toggleTimer" />
                <span class="text-text-primary font-medium text-xl tabular-nums tracking-tight">{{
                    currentTime
                }}</span>
            </div>
        </div>
    </div>
</template>
