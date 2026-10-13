export type ProductMedia = {
    id: number;
    type: 'cover' | 'screenshot';
    original_name: string;
    mime_type: string;
    size_bytes: number;
    width: number;
    height: number;
    sort_order: number;
    alt_text: string | null;
    url: string | null;
};
export type ProductArtifact = {
    id: number;
    type: 'source_package' | 'documentation';
    original_name: string;
    mime_type: string;
    size_bytes: number;
    checksum: string;
};
export type ProductRelease = {
    id: number;
    version: string;
    status: 'draft' | 'in_review' | 'approved' | 'published' | 'rejected';
    changelog: string;
    release_notes: string | null;
    released_at: string | null;
    artifacts: ProductArtifact[];
};
export type ProductFileLimits = {
    cover_max_kb: number;
    screenshot_max_kb: number;
    screenshots_max: number;
    package_max_kb: number;
    documentation_max_kb: number;
};
