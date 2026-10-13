import {
    Boxes,
    UsersRound,
    Contact,
    Calculator,
    MapPinned,
    ShoppingBag,
    CalendarCheck,
    Kanban,
    ChartNoAxesCombined,
    Headphones,
    BriefcaseBusiness,
    Store,
    Utensils,
    HeartPulse,
    GraduationCap,
    Building2,
    HardHat,
    Factory,
    Truck,
    Hotel,
    Warehouse,
} from '@lucide/vue';
import type { Component } from 'vue';
type Discovery = { icon: Component; accent: string; description?: string };
// Presentation-only copy for the actual BusinessCatalogSeeder slugs. Never a database taxonomy or metric.
const categories: Record<string, Discovery> = {
    'pos-inventory': {
        icon: Boxes,
        accent: 'blue',
        description: 'Organize stock and everyday sales',
    },
    'hr-payroll': {
        icon: UsersRound,
        accent: 'purple',
        description: 'Manage your people and payroll',
    },
    crm: {
        icon: Contact,
        accent: 'coral',
        description: 'Build stronger customer relationships',
    },
    'accounting-finance': {
        icon: Calculator,
        accent: 'emerald',
        description: 'Bring clarity to business finances',
    },
    'field-service-management': {
        icon: MapPinned,
        accent: 'cyan',
        description: 'Coordinate work beyond the office',
    },
    ecommerce: {
        icon: ShoppingBag,
        accent: 'blue',
        description: 'Run your online business',
    },
    'booking-reservation': {
        icon: CalendarCheck,
        accent: 'purple',
        description: 'Keep bookings running smoothly',
    },
    'project-management': {
        icon: Kanban,
        accent: 'amber',
        description: 'Turn plans into organized work',
    },
    'business-analytics': {
        icon: ChartNoAxesCombined,
        accent: 'cyan',
        description: 'Understand your business data',
    },
    'customer-support': {
        icon: Headphones,
        accent: 'coral',
        description: 'Support the customers you serve',
    },
};
const industries: Record<string, Component> = {
    retail: Store,
    'wholesale-distribution': Warehouse,
    'restaurant-food-service': Utensils,
    healthcare: HeartPulse,
    education: GraduationCap,
    'real-estate': Building2,
    construction: HardHat,
    manufacturing: Factory,
    'logistics-transportation': Truck,
    'professional-services': BriefcaseBusiness,
    hospitality: Hotel,
};
export const categoryPresentation = (slug: string): Discovery =>
    categories[slug] ?? { icon: BriefcaseBusiness, accent: 'blue' };
export const industryIcon = (slug: string): Component =>
    industries[slug] ?? Building2;
