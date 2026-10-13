import type { ProductMedia, ProductRelease } from '@/types/product-files';
export type ProductStatus =
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'changes_requested'
    | 'rejected'
    | 'approved'
    | 'published';
export type ProductContext = 'admin' | 'author';
export type CatalogOption = { id: number; name: string; is_active: boolean };
export type ProductFeature = {
    id?: number;
    title: string;
    description: string | null;
    sort_order: number;
};
export type ProductOffer = {
    id?: number;
    name: string;
    description: string | null;
    price: string;
    activation_limit?: number;
    license_type: string;
    support_period_days: number | null;
    updates_period_days: number | null;
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
};
export type ProductRecord = {
    id: number;
    owner_id: number | null;
    origin: 'author' | 'templaara';
    status: ProductStatus;
    name: string;
    slug: string;
    category_id: number;
    short_description: string;
    description: string | null;
    business_problem: string | null;
    target_audience: string[];
    key_benefits: string[];
    currency: string;
    demo_url: string | null;
    demo_username: string | null;
    demo_password: string | null;
    version_label: string | null;
    documentation_url: string | null;
    preview_video_url: string | null;
    media?: ProductMedia[];
    releases?: ProductRelease[];
    is_featured: boolean;
    is_active: boolean;
    meta_title: string | null;
    meta_description: string | null;
    features: ProductFeature[];
    offers: ProductOffer[];
    industries: CatalogOption[];
    technologies: CatalogOption[];
    owner: { id: number; name: string } | null;
    category: { id: number; name: string };
};
export type ProductListItem = Pick<
    ProductRecord,
    | 'id'
    | 'name'
    | 'slug'
    | 'short_description'
    | 'origin'
    | 'owner'
    | 'category'
    | 'status'
    | 'currency'
    | 'is_active'
    | 'is_featured'
> & {
    can: { view: boolean; update: boolean; delete: boolean; submit: boolean };
};
export type ProductPagination = {
    data: ProductListItem[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
export type ProductFilters = {
    search?: string;
    category?: string;
    status?: string;
    origin?: string;
};
