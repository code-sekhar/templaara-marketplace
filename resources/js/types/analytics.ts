export type Filters = {
    period: string;
    from: string;
    to: string;
    currency?: string;
    product?: number | string;
    author?: number | string;
    category?: number | string;
    origin?: string;
    status?: string;
};
export type Row = Record<string, string | number | null>;
export type Financial = Row & {
    currency: string;
    gross: string;
    comparison: { absolute: string; percentage: string | null; new: boolean };
};
export type Point = {
    bucket: string;
    gross: string;
    orders: number;
    commission: string;
    author_earned: string;
    refunds: string;
};
export type Charts = {
    granularity: string;
    labels: string[];
    series: { currency: string; points: Point[] }[];
    newCustomers: { bucket: string; count: number }[];
};
export type Analytics = {
    financial: Financial[];
    customers: Record<string, number>;
    products: {
        live: number;
        author: number;
        official: number;
        changes_requested: number;
        categories: { name: string; count: number }[];
        industries: { name: string; count: number }[];
    };
    authors: Record<string, number>;
    refunds: Record<string, number>;
    reviews: {
        count: number;
        average: string | null;
        products: number;
        unanswered: number;
        distribution: number[];
    };
    support: Record<string, number | Row[]>;
    queues: { label: string; count: number; url: string; permission: string }[];
    topProducts: Row[];
    topUnits: Row[];
    topRated: Row[];
    topAuthors: Row[];
    topAuthorEarnings: Row[];
    categoryPerformance: Row[];
    industryPerformance: Row[];
    charts: Charts;
};
