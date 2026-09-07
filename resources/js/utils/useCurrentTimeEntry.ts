import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { api } from '@/packages/api/src';
import type { TimeEntry } from '@/packages/api/src';
import dayjs, { Dayjs } from 'dayjs';
import utc from 'dayjs/plugin/utc';
import {
    getCurrentMembershipId,
    getCurrentOrganizationId,
    getCurrentUserId,
} from '@/utils/useUser';
import { useLocalStorage } from '@vueuse/core';
import { useNotificationsStore } from '@/utils/notification';
import { useQueryClient } from '@tanstack/vue-query';

dayjs.extend(utc);

const emptyTimeEntry = {
    id: '',
    description: '',
    user_id: '',
    start: '',
    end: null,
    duration: null,
    task_id: null,
    project_id: null,
    tags: [],
    billable: false,
    type: 'work',
    organization_id: '',
} as TimeEntry;

export type ResumeTimeEntryContext = {
    description: string | null;
    project_id: string | null;
    task_id: string | null;
    tags: string[];
    billable: boolean;
};

/**
 * Time entries are loaded newest-first. Ignore scheduled entries so resuming after a break
 * always uses the latest work entry that has actually started.
 */
export function getLastWorkTimeEntry(
    timeEntries: TimeEntry[],
    currentTime: Dayjs = dayjs().utc()
): TimeEntry | null {
    return (
        timeEntries.find(
            (entry) => entry.type === 'work' && !dayjs(entry.start).utc().isAfter(currentTime)
        ) ?? null
    );
}

export const useCurrentTimeEntryStore = defineStore('currentTimeEntry', () => {
    const currentTimeEntry = useLocalStorage<TimeEntry>(
        `solidtime/current-time-entry/${getCurrentUserId()}`,
        { ...emptyTimeEntry },
        { deep: true }
    );
    const { handleApiRequestNotifications } = useNotificationsStore();
    const queryClient = useQueryClient();

    const rememberedProjects = useLocalStorage<
        Record<string, Pick<TimeEntry, 'project_id' | 'task_id' | 'billable'>>
    >('solidtime/timer-projects', {});
    const selectionKey = computed(() => `${getCurrentUserId()}:${getCurrentOrganizationId()}`);

    watch(
        () => [
            currentTimeEntry.value.project_id,
            currentTimeEntry.value.task_id,
            currentTimeEntry.value.billable,
        ],
        () => {
            const entry = currentTimeEntry.value;
            if (entry.type === 'break' || !entry.project_id) return;
            if (entry.organization_id && entry.organization_id !== getCurrentOrganizationId())
                return;
            rememberedProjects.value[selectionKey.value] = {
                project_id: entry.project_id,
                task_id: entry.task_id,
                billable: entry.billable,
            };
        },
        { flush: 'sync', immediate: true }
    );

    function $reset() {
        currentTimeEntry.value = {
            ...emptyTimeEntry,
            ...rememberedProjects.value[selectionKey.value],
            organization_id: getCurrentOrganizationId() ?? '',
        };
    }

    watch(selectionKey, () => {
        if (!currentTimeEntry.value.id) $reset();
    });
    if (!currentTimeEntry.value.id) $reset();

    const now = ref<null | Dayjs>(null);
    const interval = ref<ReturnType<typeof setInterval> | null>(null);

    function startLiveTimer() {
        stopLiveTimer();
        now.value = dayjs().utc();
        interval.value = setInterval(() => {
            now.value = dayjs().utc();
        }, 1000);
    }

    function stopLiveTimer() {
        if (interval.value !== null) {
            clearInterval(interval.value);
        }
    }

    async function fetchCurrentTimeEntry() {
        const organizationId = getCurrentOrganizationId();
        if (organizationId) {
            try {
                const timeEntriesResponse = await api.getMyActiveTimeEntry({});
                if (timeEntriesResponse) {
                    if (timeEntriesResponse.data) {
                        currentTimeEntry.value = timeEntriesResponse.data;
                        if (
                            currentTimeEntry.value.start !== '' &&
                            currentTimeEntry.value.end === null
                        ) {
                            startLiveTimer();
                        }
                    } else {
                        // No active time entry on server
                        // Only reset if we had a previously started timer (has an ID)
                        // Don't reset if user is preparing a new time entry (no ID yet)
                        if (currentTimeEntry.value.id !== '') {
                            $reset();
                            stopLiveTimer();
                        }
                    }
                }
            } catch {
                // API error (e.g., 404 when no active time entry)
                // Only reset if we had a previously started timer (has an ID)
                // Don't reset if user is preparing a new time entry (no ID yet)
                if (currentTimeEntry.value.id !== '') {
                    $reset();
                    stopLiveTimer();
                }
            }
        } else {
            throw new Error(
                'Failed to fetch current time entry because organization ID is missing.'
            );
        }
    }

    async function startTimer() {
        const organization = getCurrentOrganizationId();
        const membership = getCurrentMembershipId();
        if (organization && membership) {
            const startTime =
                currentTimeEntry.value.start !== ''
                    ? currentTimeEntry.value.start
                    : dayjs().utc().format();
            const response = await handleApiRequestNotifications(
                () =>
                    api.createTimeEntry(
                        {
                            member_id: membership,
                            start: startTime,
                            description: currentTimeEntry.value?.description,
                            project_id: currentTimeEntry.value?.project_id,
                            task_id: currentTimeEntry.value?.task_id,
                            billable: currentTimeEntry.value.billable,
                            type: currentTimeEntry.value?.type ?? 'work',
                            tags: currentTimeEntry.value?.tags,
                        },
                        { params: { organization: organization } }
                    ),
                'Timer started!'
            );
            if (response?.data) {
                currentTimeEntry.value = response.data;
            }
        } else {
            throw new Error(
                'Failed to fetch current time entry because organization ID is missing.'
            );
        }
    }

    async function stopTimer(endTime?: string) {
        const user = getCurrentUserId();
        const organization = getCurrentOrganizationId();
        if (organization) {
            const currentDateTime = endTime ?? dayjs().utc().format();
            await handleApiRequestNotifications(
                () =>
                    api.updateTimeEntry(
                        {
                            user_id: user,
                            start: currentTimeEntry.value.start,
                            end: currentDateTime,
                        },
                        {
                            params: {
                                organization: organization,
                                timeEntry: currentTimeEntry.value.id,
                            },
                        }
                    ),
                'Timer stopped!'
            );
            $reset();
        } else {
            throw new Error('Failed to stop current timer because organization ID is missing.');
        }
    }

    async function startBreak() {
        const organization = getCurrentOrganizationId();
        const membership = getCurrentMembershipId();
        if (!organization || !membership) {
            throw new Error('Failed to start break because organization ID is missing.');
        }
        // One timestamp for both the work end and the break start, so the entries touch exactly
        const switchTime = dayjs().utc().format();
        if (isActive.value && currentTimeEntry.value.type !== 'break') {
            await stopTimer(switchTime);
        }
        startLiveTimer();
        const response = await handleApiRequestNotifications(
            () =>
                api.createTimeEntry(
                    {
                        member_id: membership,
                        start: switchTime,
                        billable: false,
                        type: 'break',
                    },
                    { params: { organization: organization } }
                ),
            'Break started!',
            'Your timer was stopped, but the break could not be started.'
        );
        if (response?.data) {
            currentTimeEntry.value = response.data;
        } else {
            stopLiveTimer();
        }
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
    }

    async function resumeWorkAfterBreak(context: ResumeTimeEntryContext) {
        if (isActive.value && currentTimeEntry.value.type === 'break') {
            stopLiveTimer();
            await stopTimer();
        }
        currentTimeEntry.value = {
            ...emptyTimeEntry,
            description: context.description ?? '',
            project_id: context.project_id,
            task_id: context.task_id,
            tags: context.tags ?? [],
            billable: context.billable,
        };
        startLiveTimer();
        await startTimer();
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
    }

    async function updateTimer() {
        const user = getCurrentUserId();
        const organization = getCurrentOrganizationId();
        if (organization) {
            const response = await handleApiRequestNotifications(
                () =>
                    api.updateTimeEntry(
                        {
                            description: currentTimeEntry.value.description,
                            user_id: user,
                            project_id: currentTimeEntry.value.project_id,
                            task_id: currentTimeEntry.value.task_id,
                            start: currentTimeEntry.value.start,
                            billable: currentTimeEntry.value.billable,
                            end: currentTimeEntry.value.end,
                            tags: currentTimeEntry.value.tags,
                        },
                        {
                            params: {
                                organization: organization,
                                timeEntry: currentTimeEntry.value.id,
                            },
                        }
                    ),
                'Time entry updated!'
            );
            if (response?.data) {
                if (response.data.end === null) {
                    currentTimeEntry.value = response.data;
                } else {
                    $reset();
                    stopLiveTimer();
                }
            }
        } else {
            throw new Error(
                'Failed to fetch current time entry because organization ID is missing.'
            );
        }
    }

    const isActive = computed(() => {
        if (currentTimeEntry.value) {
            return (
                currentTimeEntry.value.start !== '' &&
                currentTimeEntry.value.start !== null &&
                currentTimeEntry.value.end === null
            );
        }
        return false;
    });

    const isOnBreak = computed(() => {
        return isActive.value && currentTimeEntry.value.type === 'break';
    });

    async function setActiveState(newState: boolean) {
        if (newState) {
            startLiveTimer();
            await startTimer();
        } else {
            stopLiveTimer();
            await stopTimer();
        }
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
    }

    return {
        currentTimeEntry,
        fetchCurrentTimeEntry,
        updateTimer,
        isActive,
        isOnBreak,
        startBreak,
        resumeWorkAfterBreak,
        startLiveTimer,
        stopLiveTimer,
        now,
        setActiveState,
        $reset,
    };
});
