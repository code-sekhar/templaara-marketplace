export type AuthorStatus =
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'changes_requested'
    | 'approved'
    | 'rejected';
export const authorStatusLabels: Record<AuthorStatus, string> = {
    draft: 'Draft',
    submitted: 'Submitted',
    under_review: 'Under Review',
    changes_requested: 'Changes Requested',
    approved: 'Approved',
    rejected: 'Rejected',
};
export const authorFields = [
    'display_name',
    'company_name',
    'slug',
    'author_type',
    'country_code',
    'bio',
    'website_url',
    'portfolio_url',
    'github_url',
    'linkedin_url',
    'support_email',
    'support_phone',
] as const;
export type AuthorField = (typeof authorFields)[number];
export type AuthorContent = Record<AuthorField, string | null> & {
    professional?: import('./author-professional').ProfessionalEvidence;
};
export type AuthorProfile = AuthorContent & {
    status: AuthorStatus;
    is_legacy: boolean;
};
export type AuthorRound = {
    id: number;
    round_number: number;
    status: AuthorStatus;
    submitted_at: string;
    review_started_at: string | null;
    decision_at: string | null;
    decision_summary: string | null;
    snapshot: AuthorContent;
    feedback: { section: string; message: string; created_at: string }[];
    events: {
        action: string;
        actor_name: string;
        from_status: AuthorStatus;
        to_status: AuthorStatus;
        occurred_at: string;
    }[];
};
export type Pagination<T> = {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
export type AuthorHistory = { rounds: Pagination<AuthorRound> };
export const feedbackSections = [
    'general',
    'identity',
    'profile',
    'company',
    'portfolio',
    'contact',
];
export const countryName = (code: string) =>
    new Intl.DisplayNames(['en'], { type: 'region' }).of(code) ?? code;
