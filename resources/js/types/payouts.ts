export interface PayoutMethod {
    uuid: string;
    type: string;
    currency: string;
    country_code: string;
    label: string;
    bank_name: string;
    masked_account: string;
    status: string;
    is_default: boolean;
}
export interface Payout {
    uuid: string;
    reference: string;
    currency: string;
    amount: string;
    status: string;
    method: PayoutMethod;
    author?: string;
    requested_at: string;
    paid_at: string | null;
    failure_reason: string | null;
    cancellation_reason: string | null;
    admin_note?: string | null;
    external_reference?: string | null;
    allocations?: {
        amount: string;
        product: string;
        version: string;
        earning_uuid: string;
    }[];
    events?: {
        status: string;
        previous_status: string | null;
        reason: string | null;
        occurred_at: string;
        actor?: string;
    }[];
}
