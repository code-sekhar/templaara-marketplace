import type { PublicImage } from './marketplace';
export type LibraryPurchase = {
    support_entry: { url: string | null };
    review_entry: {
        eligible: boolean;
        url: string | null;
        label: string | null;
    };
    refund: {
        uuid: string;
        reference: string;
        status: string;
        completed_at: string | null;
    } | null;
    activation_pagination: {
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
    } | null;
    product_identity: string;
    activation_limit: number;
    active_activations: number;
    activations: {
        public_uuid: string;
        installation_label: string | null;
        domain_display: string | null;
        status: string;
        activated_at: string;
        last_validated_at: string | null;
        deactivated_at: string | null;
    }[];
    reference: string;
    status: string;
    granted_at: string;
    order_number: string;
    product_name: string;
    offer_name: string;
    version: string;
    license_type: string;
    license_status: string | null;
    access_count: number;
    cover: PublicImage | null;
    customer_name?: string;
};
export type PurchasedSoftware = LibraryPurchase & {
    offer_description: string | null;
    support_period_days: number | null;
    updates_period_days: number | null;
    unit_price: string;
    currency: string;
    download_available: boolean;
    checksum: string | null;
    license: {
        key: string | null;
        status: string;
        issued_at: string;
        expires_at: string | null;
        revoked_at: string | null;
    } | null;
};
