/**
 * Development catalogue map. Labels are provisional placeholders derived from
 * the approved Hidden Vault pack and project research, not live taxonomy.
 *
 * The public app has no category-feed endpoint. Keep these links on the
 * existing named catalogue route until the owner replaces this map with
 * verified public tags and editorial asset metadata.
 */
// `type` is the existing public catalogue filter. Do not use the legacy
// `tag=true` branch: it filters by the route category's tag type instead.
const catalogueRoute = (type) => route("products.index", { category: "all", type });

export const vaultNavigation = Object.freeze({
    status: "provisional-placeholder-catalogue",
    categories: Object.freeze([
        { key: "watches", label: "Watches", href: catalogueRoute("Watches") },
        { key: "jewellery", label: "Jewellery", href: catalogueRoute("Jewellery") },
        { key: "opals", label: "Opals", href: catalogueRoute("Opals") },
        { key: "trading-cards", label: "Trading Cards", href: catalogueRoute("Trading Cards") },
        { key: "curiosities", label: "Curiosities", href: catalogueRoute("Curiosities") },
    ]),
    editorialAssets: Object.freeze([]),
});

export default vaultNavigation;
