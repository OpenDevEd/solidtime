import { describe, expect, it } from 'vitest';
import {
    applyHarvestFieldChoice,
    groupHarvestConflicts,
    type HarvestConflict,
} from './harvestReview';

const field: HarvestConflict = {
    key: 'projects:1',
    label: 'Project',
    message: 'Name changed.',
    candidates: [],
    choices: ['local', 'source'],
};
describe('Harvest review', () => {
    it('combines field conflicts governed by the same record decision without mutating the plan', () => {
        const grouped = groupHarvestConflicts([field, { ...field, message: 'Rate changed.' }]);
        expect(grouped).toHaveLength(1);
        expect(grouped[0]?.message).toContain('Rate changed.');
        expect(field.message).toBe('Name changed.');
    });
    it('applies bulk field choices without overriding individual decisions or choosing identities', () => {
        const identity = {
            ...field,
            key: 'users:1',
            candidates: [{ id: 'user', name: 'Person' }],
            choices: ['create'],
        };
        expect(
            applyHarvestFieldChoice(
                [field, { ...field, key: 'projects:2' }, identity],
                { 'projects:2': 'local' },
                'source'
            )
        ).toEqual({ 'projects:1': 'source', 'projects:2': 'local' });
    });
});
