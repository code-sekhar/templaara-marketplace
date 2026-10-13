import * as categories from '@/routes/admin/categories';
import * as industries from '@/routes/admin/industries';
import * as technologies from '@/routes/admin/technologies';

export const catalogRoutes = { categories, industries, technologies };
export const catalogLabels = {
    categories: {
        plural: 'Categories',
        singular: 'Category',
        description: 'What the software does',
    },
    industries: {
        plural: 'Industries',
        singular: 'Industry',
        description: 'The business sectors software serves',
    },
    technologies: {
        plural: 'Technologies',
        singular: 'Technology',
        description: 'Secondary technical implementation information',
    },
};
