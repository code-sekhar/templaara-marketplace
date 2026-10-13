export type CatalogLink = { name: string; slug: string };
export type PublicImage = { url: string; alt: string };
export type Seo = {
    title: string;
    description: string;
    canonical: string;
    structuredData?: string;
};
export type ProductCardData = {
    rating: { average: string | null; count: number };
    name: string;
    slug: string;
    short_description: string;
    official: boolean;
    featured: boolean;
    category: CatalogLink;
    industries: CatalogLink[];
    cover: PublicImage;
    currency: string;
    starting_price: string;
};
export type PublicOffer = {
    offer_id: number;
    activation_limit: number;
    name: string;
    description: string | null;
    price: string;
    license_type: string;
    support_period_days: number | null;
    updates_period_days: number | null;
    is_default: boolean;
};
export type PublicProduct = ProductCardData & {
    description: string;
    business_problem: string | null;
    target_audience: string[];
    key_benefits: string[];
    seller: string | null;
    author: import('./author-professional').AuthorCard | null;
    demo_url: string | null;
    demo_username: string | null;
    demo_password: string | null;
    documentation_url: string | null;
    preview_video_url: string | null;
    screenshots: PublicImage[];
    technologies: CatalogLink[];
    features: { title: string; description: string | null }[];
    offers: PublicOffer[];
    release: {
        version: string;
        changelog: string;
        release_notes: string | null;
        released_at: string;
    };
};
export type MarketplaceFilters = {
    search?: string;
    category?: string;
    industry?: string;
    technology?: string;
    industries?: string[];
    technologies?: string[];
    currency?: string;
    min_price?: string;
    max_price?: string;
    sort?: string;
    view?: 'grid' | 'list';
};
