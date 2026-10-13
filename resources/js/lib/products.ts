import * as admin from '@/routes/admin/products';
import * as author from '@/routes/author/products';

export const productRoutes = { admin, author };
export const licenseLabels: Record<string, string> = {
    single_business: 'Single business',
    multi_business: 'Multiple businesses',
    extended: 'Extended',
};

export const statusLabels: Record<string, string> = {
    draft: 'Draft',
    submitted: 'Submitted',
    under_review: 'Under Review',
    changes_requested: 'Changes Requested',
    rejected: 'Rejected',
    approved: 'Approved',
    published: 'Published',
};
export const feedbackSections: Record<string, string> = {
    general: 'General',
    basic_information: 'Basic Information',
    business_details: 'Business Details',
    features: 'Features',
    pricing: 'Pricing',
    demo: 'Demo',
    technical: 'Technical Information',
    support: 'Support & Updates',
    seo: 'SEO',
};
