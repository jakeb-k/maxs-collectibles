/**
 * Deliberately empty until an owner supplies a verified public category map.
 * Do not derive this from /products/tags (admin-only) or the latest-products
 * sample. Consumers must retain existing supported destinations meanwhile.
 */
export const vaultNavigation = Object.freeze({
    status: "awaiting-approved-category-mapping",
    categories: [],
    editorialAssets: [],
});

export default vaultNavigation;
