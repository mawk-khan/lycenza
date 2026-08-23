// Mirrors the backend's fixed enum
// (App\Domain\Guardians\Infrastructure\RelationshipType) -- this is
// display-label metadata only, not a parallel source of truth: any
// value submitted still goes through the same Rule::enum() validation
// server-side regardless of what this list offers.
export const RELATIONSHIP_TYPES: Array<{ value: string; label: string }> = [
    { value: 'mother', label: 'Mother' },
    { value: 'father', label: 'Father' },
    { value: 'parent', label: 'Parent' },
    { value: 'step_parent', label: 'Step-parent' },
    { value: 'grandparent', label: 'Grandparent' },
    { value: 'legal_guardian', label: 'Legal guardian' },
    { value: 'foster_guardian', label: 'Foster guardian' },
    { value: 'sibling', label: 'Sibling' },
    { value: 'relative', label: 'Relative' },
    { value: 'other', label: 'Other' },
];

export function relationshipLabel(value: string): string {
    return RELATIONSHIP_TYPES.find((t) => t.value === value)?.label ?? value;
}
