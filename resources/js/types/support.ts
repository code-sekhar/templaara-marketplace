export interface SupportPage<T> {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
    current_page: number;
    last_page: number;
}
export interface SupportPurchase {
    purchase: string;
    product_name: string;
    version: string;
    offer_name: string;
    license_type: string;
    support_period_days: number;
    support_ends_at: string;
}
export type SupportMode = 'customer' | 'author' | 'admin';
export interface SupportMessage {
    public_uuid: string;
    body: string;
    sender: string;
    sender_type: string;
    is_internal: boolean;
    created_at: string;
    attachments: {
        public_uuid: string;
        original_name: string;
        mime_type: string;
        size_bytes: number;
        url: string;
    }[];
}
export interface SupportTicket extends Omit<SupportPurchase, 'purchase'> {
    uuid: string;
    ticket_number: string;
    subject: string;
    status: string;
    priority: string;
    category: string;
    customer: string;
    seller: string;
    unread: number;
    last_message_at: string;
    created_at: string;
    is_escalated: boolean;
    admin_locked: boolean;
    can_reply: boolean;
    can_note: boolean;
    can_resolve: boolean;
    can_close: boolean;
    can_reopen: boolean;
    can_escalate: boolean;
    can_manage: boolean;
    messages: SupportPage<SupportMessage>;
    events: SupportPage<{
        type: string;
        previous_status: string | null;
        status: string;
        reason: string | null;
        occurred_at: string;
    }>;
}
