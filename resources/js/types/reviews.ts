import type { Page as BasePage } from '@/types/earnings';
export type ReviewPage<T> = BasePage<T> & {
    prev_page_url: string | null;
    next_page_url: string | null;
};
export type ReviewRow = {
    uuid: string;
    rating: number;
    title: string | null;
    body: string;
    reviewer: string;
    verified_purchase: boolean | null;
    purchased_version: string;
    published_at: string;
    edited_at: string | null;
    response: {
        body: string;
        seller: string;
        published_at: string;
        edited_at: string | null;
    } | null;
    product?: { name: string; slug: string };
    status?: string;
    withdrawn_at?: string | null;
    verification_retained?: boolean;
    qualified?: boolean;
    can_edit?: boolean;
    can_restore?: boolean;
    can_withdraw?: boolean;
    can_respond?: boolean;
    can_edit_response?: boolean;
    events?: ReviewPage<{
        type: string;
        reason: string | null;
        occurred_at: string;
    }> | null;
};
export type PublicReviews = {
    summary: {
        average: string | null;
        count: number;
        distribution: Record<number, number>;
    };
    sort: string;
    records: ReviewPage<ReviewRow>;
    entry: { eligible: boolean; url: string | null; label: string | null };
};
