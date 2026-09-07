import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';
import { api } from '@/packages/api/src';
import type { TimeEntry } from '@/packages/api/src';
import { getLastWorkTimeEntry, useCurrentTimeEntryStore } from './useCurrentTimeEntry';

vi.mock('@/utils/useUser', () => ({
    getCurrentUserId: () => 'user-1',
    getCurrentOrganizationId: () => 'organization-1',
    getCurrentMembershipId: () => 'member-1',
}));
vi.mock('@/utils/notification', () => ({
    useNotificationsStore: () => ({
        handleApiRequestNotifications: (request: () => unknown) => request(),
    }),
}));
vi.mock('@tanstack/vue-query', () => ({ useQueryClient: () => ({ invalidateQueries: vi.fn() }) }));

dayjs.extend(utc);

function timeEntry(id: string, start: string, type: 'work' | 'break'): TimeEntry {
    return {
        id,
        start,
        end: null,
        duration: null,
        description: '',
        project_id: null,
        task_id: null,
        organization_id: 'organization-1',
        user_id: 'user-1',
        tags: [],
        billable: false,
        type,
    } as TimeEntry;
}

describe('getLastWorkTimeEntry', () => {
    it('returns the newest work entry that is not in the future', () => {
        const entries = [
            timeEntry('future-work', '2026-07-14T14:00:00Z', 'work'),
            timeEntry('break', '2026-07-14T12:00:00Z', 'break'),
            timeEntry('last-work', '2026-07-14T11:00:00Z', 'work'),
            timeEntry('older-work', '2026-07-14T10:00:00Z', 'work'),
        ];

        expect(getLastWorkTimeEntry(entries, dayjs.utc('2026-07-14T13:00:00Z'))?.id).toBe(
            'last-work'
        );
    });
});

describe('remembered timer project', () => {
    beforeEach(() => {
        localStorage.clear();
        setActivePinia(createPinia());
        vi.restoreAllMocks();
    });

    it('keeps project and task after stopping, then starts a fresh session for that project', async () => {
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = {
            ...timeEntry('entry-1', '2026-09-01T09:00:00Z', 'work'),
            project_id: 'project-1',
            task_id: 'task-1',
            billable: true,
        };
        vi.spyOn(api, 'updateTimeEntry').mockResolvedValue({ data: store.currentTimeEntry });
        const create = vi
            .spyOn(api, 'createTimeEntry')
            .mockResolvedValue({ data: store.currentTimeEntry });
        await store.setActiveState(false);
        expect(store.currentTimeEntry).toMatchObject({
            id: '',
            start: '',
            project_id: 'project-1',
            task_id: 'task-1',
            billable: true,
        });
        await store.setActiveState(true);
        expect(create).toHaveBeenCalledWith(
            expect.objectContaining({
                project_id: 'project-1',
                task_id: 'task-1',
                start: expect.not.stringContaining('2026-09-01'),
            }),
            expect.anything()
        );
        store.stopLiveTimer();
        store.$dispose();
    });

    it('persists the final project and task together when switching projects', async () => {
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry.project_id = 'project-1';
        store.currentTimeEntry.task_id = 'task-1';
        await nextTick();
        store.currentTimeEntry.project_id = 'project-2';
        store.currentTimeEntry.task_id = null;
        await nextTick();
        expect(
            JSON.parse(localStorage.getItem('solidtime/current-time-entry/user-1')!)
        ).toMatchObject({
            project_id: 'project-2',
            task_id: null,
        });
        expect(
            JSON.parse(localStorage.getItem('solidtime/timer-projects')!)['user-1:organization-1']
        ).toMatchObject({
            project_id: 'project-2',
            task_id: null,
        });
        store.currentTimeEntry.task_id = 'task-3';
        store.currentTimeEntry.project_id = 'project-3';
        await nextTick();
        expect(
            JSON.parse(localStorage.getItem('solidtime/current-time-entry/user-1')!)
        ).toMatchObject({
            project_id: 'project-3',
            task_id: 'task-3',
        });
        store.$dispose();
    });

    it('restores the selected project after reopening the store', async () => {
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry.project_id = 'project-2';
        store.$reset();
        await nextTick();
        store.$dispose();
        setActivePinia(createPinia());
        const reopened = useCurrentTimeEntryStore();
        expect(reopened.currentTimeEntry.project_id).toBe('project-2');
        expect(reopened.isActive).toBe(false);
        reopened.$dispose();
    });
});
