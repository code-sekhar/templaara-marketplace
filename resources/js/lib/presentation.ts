export function statusPresentation(value: string): {
    label: string;
    tone: string;
} {
    const normalized = value.toLowerCase();
    const groups: Record<string, string[]> = {
        success: [
            'approved',
            'published',
            'paid',
            'completed',
            'resolved',
            'active',
            'issued',
        ],
        warning: [
            'pending',
            'requested',
            'processing',
            'changes_requested',
            'reserved',
            'waiting_customer',
            'waiting_author',
            'open',
            'expiring',
        ],
        danger: ['rejected', 'failed', 'revoked', 'expired', 'refunded'],
        info: ['submitted', 'confirmed'],
        review: ['under_review', 'escalated'],
    };
    const tone =
        Object.entries(groups).find(([, values]) =>
            values.includes(normalized),
        )?.[0] ?? 'neutral';
    const label =
        normalized === 'changes_requested'
            ? 'Changes requested'
            : value
                  .replaceAll('_', ' ')
                  .replace(/\b\w/g, (c) => c.toUpperCase());
    return { label, tone };
}
