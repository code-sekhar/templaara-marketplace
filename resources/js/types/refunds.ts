export interface RefundData {
    uuid: string;
    reference: string;
    order: string;
    currency: string;
    amount: string;
    status: string;
    reason_code: string;
    requested_at: string;
    completed_at: string | null;
    customer?: string;
    customer_reason?: string | null;
    failure_reason?: string | null;
    cancellation_reason?: string | null;
    admin_note?: string | null;
    external_reference?: string | null;
    purchase_date?: string;
    window_days?: number;
    window_ends_at?: string;
    items?: {
        product: string;
        version: string;
        amount: string;
        license_status?: string;
        download_count?: number;
        activation_count?: number;
        earning_status?: string;
        author_amount?: string;
        commission_amount?: string;
        payout_exposure?: string[];
        paid_exposure?: string;
    }[];
    events?: {
        status: string;
        previous_status: string | null;
        reason: string | null;
        occurred_at: string;
    }[];
}
