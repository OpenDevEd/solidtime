import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import type { TimeEntry } from '@/packages/api/src';
import TimesheetEntryDetails from './TimesheetEntryDetails.vue';

const first: TimeEntry = {
    id: 'first',
    start: '2026-09-07T09:00:00Z',
    end: '2026-09-07T10:00:00Z',
    duration: 3600,
    description: 'First note',
    task_id: null,
    project_id: null,
    organization_id: 'org',
    user_id: 'user',
    tags: [],
    billable: false,
    type: 'work',
};

describe('Timesheet entry notes', () => {
    it('keeps multiple entry notes separate and saves only the selected entry', async () => {
        const saveNote = vi.fn().mockResolvedValue(true);
        const wrapper = mount(TimesheetEntryDetails, {
            props: {
                show: false,
                entries: [first, { ...first, id: 'second', description: 'Second note' }],
                date: '2026-09-07',
                formatDuration: () => '1 h',
                saveNote,
            },
            global: {
                stubs: {
                    DialogModal: {
                        template: '<div><slot name="content"/><slot name="footer"/></div>',
                    },
                },
            },
        });
        await wrapper.setProps({ show: true });
        const inputs = wrapper.findAll('textarea');
        expect(inputs.map((input) => input.element.value)).toEqual(['First note', 'Second note']);
        await inputs[1]!.setValue('Second note edited');
        await wrapper.get('[data-testid="save-notes"]').trigger('click');
        await flushPromises();
        expect(saveNote).toHaveBeenCalledExactlyOnceWith('second', 'Second note edited');
        expect(inputs[0]!.element.value).toBe('First note');
        expect(wrapper.emitted('update:show')?.at(-1)).toEqual([false]);
        wrapper.unmount();
    });
    it('retains edited notes when saving fails and allows retry', async () => {
        const saveNote = vi.fn().mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        const wrapper = mount(TimesheetEntryDetails, {
            props: {
                show: true,
                entries: [first],
                date: '2026-09-07',
                formatDuration: () => '1 h',
                saveNote,
            },
            global: {
                stubs: {
                    DialogModal: {
                        template: '<div><slot name="content"/><slot name="footer"/></div>',
                    },
                },
            },
        });
        await wrapper.get('textarea').setValue('Edited note');
        await wrapper.get('[data-testid="save-notes"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
        expect(wrapper.get('textarea').element.value).toBe('Edited note');
        expect(wrapper.emitted('update:show')).toBeUndefined();
        await wrapper.get('[data-testid="save-notes"]').trigger('click');
        await flushPromises();
        expect(wrapper.emitted('update:show')?.at(-1)).toEqual([false]);
        wrapper.unmount();
    });
});
