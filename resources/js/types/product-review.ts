import type { ProductRecord, ProductStatus } from '@/types/product';
export type WorkflowActions = {
    submit?: boolean;
    start?: boolean;
    changes?: boolean;
    reject?: boolean;
    approve?: boolean;
    publish?: boolean;
};
export type ReviewActor = { id: number; name: string };
export type ReviewFeedback = {
    id: number;
    section: string;
    message: string;
    creator: ReviewActor;
};
export type SubmissionRound = {
    id: number;
    product_id: number;
    round_number: number;
    submitted_at: string;
    review_started_at: string | null;
    decision_at: string | null;
    decision: string | null;
    decision_summary: string | null;
    reviewed_by: number | null;
    submitter: ReviewActor;
    reviewer: ReviewActor | null;
    feedback: ReviewFeedback[];
};
export type LifecycleEntry = {
    id: number;
    action: string;
    from_status: ProductStatus;
    to_status: ProductStatus;
    occurred_at: string;
    actor: ReviewActor;
};
export type ReviewPage<T> = {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
export type ReviewHistory = {
    rounds: ReviewPage<SubmissionRound>;
    events: ReviewPage<LifecycleEntry>;
};
export type ReviewQueueItem = SubmissionRound & {
    product: Pick<
        ProductRecord,
        'id' | 'name' | 'slug' | 'status' | 'origin' | 'owner' | 'category'
    >;
};
