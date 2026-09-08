export interface HarvestConflict {
    key: string;
    label: string;
    message: string;
    candidates: { id: string; name: string; email?: string | null; evidence?: string[] }[];
    choices: string[];
}

export function groupHarvestConflicts(conflicts: HarvestConflict[]): HarvestConflict[] {
    const grouped = new Map<string, HarvestConflict>();
    for (const conflict of conflicts) {
        const previous = grouped.get(conflict.key);
        if (previous) previous.message += ` ${conflict.message}`;
        else grouped.set(conflict.key, { ...conflict });
    }
    return [...grouped.values()];
}

export function applyHarvestFieldChoice(
    conflicts: HarvestConflict[],
    decisions: Record<string, string>,
    choice: 'local' | 'source'
): Record<string, string> {
    const result = { ...decisions };
    for (const conflict of conflicts) {
        if (
            !result[conflict.key] &&
            conflict.candidates.length === 0 &&
            conflict.choices.includes('local') &&
            conflict.choices.includes('source')
        ) {
            result[conflict.key] = choice;
        }
    }
    return result;
}
