export interface Balance {
    refund_adjustment?: string;
    outstanding_adjustment?: string;
    net_earned?: string;
    net_position?: string;
    gross_refunded?: string;
    commission_reversed?: string;
    net_sales?: string;
    net_commission?: string;
    gross?: string;
    commission?: string;
    currency: string;
    earned: string;
    pending: string;
    available: string;
    reserved?: string;
    paid?: string;
}
export interface Earning {
    refund_adjustment: string;
    uuid: string;
    product: string;
    offer: string;
    version: string;
    order_number: string;
    author?: string;
    currency: string;
    gross: string;
    rate: string;
    commission: string;
    net: string;
    status: string;
    hold_days: number;
    available_at: string;
    released_at: string | null;
    created_at: string;
}
export interface Page<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}
