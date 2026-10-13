export type CatalogKind = 'categories' | 'industries' | 'technologies';
export type CatalogParent = { id: number; name: string };
export type CatalogRecord = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    icon?: string | null;
    logo?: string | null;
    website_url?: string | null;
    parent_id?: number | null;
    parent?: CatalogParent | null;
    sort_order: number;
    is_active: boolean;
};
export type CatalogFilters = {
    search?: string;
    active?: string;
    sort?: string;
    parent?: string;
};
export type CatalogPagination = {
    data: CatalogRecord[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
