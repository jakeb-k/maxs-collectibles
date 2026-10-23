import { Head, Link, router, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import ProductCard from "@/Components/product/ProductCard";
import useCatalogueItems from "@/utils/useCatalogueItems";
import useFavouritesStore from "@/Stores/useFavouritesStore";

const FILTER_KEYS = ["q", "type", "sort", "min_price_minor", "max_price_minor", "availability", "discounted"];
const draftFrom = (filters) => Object.fromEntries(FILTER_KEYS.map((key) => [key, filters[key] ?? ""]));
const titleFor = (category) => ({ all: "The collection", favourites: "Your favourites", sale: "On sale" })[category] || `${category.charAt(0).toUpperCase()}${category.slice(1)}`;

export default function ProductIndexLayout({ products = [], category = "all", filters = {} }) {
    const { url } = usePage();
    const favourites = useFavouritesStore((state) => state.favourites);
    const live = useCatalogueItems(category === "favourites" ? favourites : []);
    const shown = category === "favourites" ? live.products : products;
    const initialDraft = useMemo(() => draftFrom(filters), [filters]);
    const [draft, setDraft] = useState(initialDraft);
    const isFavourites = category === "favourites";
    const tagged = useMemo(() => new URLSearchParams(url.split("?")[1] || "").get("tag") === "true", [url]);
    const catalogueHref = route("products.index", category);
    useEffect(() => setDraft(initialDraft), [initialDraft]);
    const setField = (key, value) => setDraft((current) => ({ ...current, [key]: value }));
    const submit = (event) => {
        event.preventDefault();
        const params = Object.fromEntries(Object.entries(draft).filter(([, value]) => value !== ""));
        if (tagged) params.tag = true;
        router.get(catalogueHref, params, { preserveScroll: true, preserveState: true });
    };
    const clear = () => router.get(catalogueHref, tagged ? { tag: true } : {}, { preserveScroll: true });
    const title = titleFor(category);

    return <div className="hidden-vault page-width catalogue-page bg-[#F8F4E9] py-8 text-[#0D0C09] md:py-12">
        <Head title={title} />
        <nav className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]" aria-label="Breadcrumb"><Link href={route("index")}>Home</Link><span aria-hidden="true"> / </span><span>Collection</span></nav>
        <header className="mt-5 flex flex-wrap items-end justify-between gap-4 border-b border-[#3E3A32] pb-6"><div><p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Collector’s index</p><h1 data-hv-type="display" className="mt-2 text-[var(--hv-display-sm)] leading-[1.29] md:text-[var(--hv-display-md)] md:leading-[1.2]">{title}</h1></div><p aria-live="polite" className="text-sm text-[#3E3A32]">{shown.length} {shown.length === 1 ? "object shown" : "objects shown"}</p></header>
        {!isFavourites && <form className="mt-6 grid gap-4 border border-[#3E3A32] bg-[#E8DDC4] p-4 md:grid-cols-2 lg:grid-cols-5" onSubmit={submit} aria-label="Filter the collection">
            <label className="text-sm font-medium">Search the collection<input type="search" value={draft.q} onChange={(event) => setField("q", event.target.value)} placeholder="Name, category or tag" /></label>
            {!tagged && <label className="text-sm font-medium">Type<input value={draft.type} onChange={(event) => setField("type", event.target.value)} placeholder="Current supported type" /></label>}
            <label className="text-sm font-medium">Availability<select value={draft.availability || "all"} onChange={(event) => setField("availability", event.target.value)}><option value="all">All items</option><option value="in_stock">In stock</option></select></label>
            <label className="text-sm font-medium">Maximum price<select value={draft.max_price_minor} onChange={(event) => setField("max_price_minor", event.target.value)}><option value="">Any price</option><option value="2500">Up to $25 AUD</option><option value="5000">Up to $50 AUD</option><option value="10000">Up to $100 AUD</option><option value="50000">Up to $500 AUD</option></select></label>
            <label className="text-sm font-medium">Sort by<select value={draft.sort || "newest"} onChange={(event) => setField("sort", event.target.value)}><option value="newest">Newest first</option><option value="name">Name</option><option value="price_asc">Price: low to high</option><option value="price_desc">Price: high to low</option><option value="popular">Most purchased</option></select></label>
            <div className="flex flex-wrap items-end gap-3 lg:col-span-5"><button data-hv-action="primary" className="min-h-11 px-5 py-3" type="submit">Apply filters</button><button className="min-h-11 underline underline-offset-4" type="button" onClick={() => setDraft(initialDraft)}>Cancel changes</button><button className="min-h-11 underline underline-offset-4" type="button" onClick={clear}>Clear filters</button></div>
        </form>}
        {isFavourites && <section className="mt-6 border border-[#3E3A32] bg-[#E8DDC4] p-4">{live.pending && <p role="status">Checking saved objects…</p>}{live.error && <div role="alert"><p>{live.error}</p><button className="mt-3 min-h-11 underline underline-offset-4" type="button" onClick={live.refresh}>Retry favourites refresh</button></div>}{live.missing.length > 0 && <p role="status">{live.missing.length} saved object(s) are no longer available. <button className="underline underline-offset-4" type="button" onClick={() => useFavouritesStore.getState().setFavourites(favourites.filter((id) => !live.missing.includes(id)))}>Remove unavailable objects</button></p>}</section>}
        {shown.length ? <div className="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{shown.map((product) => <ProductCard key={product.id} product={product} />)}</div> : <section className="mt-8 border border-[#3E3A32] bg-[#E8DDC4] p-6"><h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">{isFavourites ? "No favourites saved yet." : "No objects found."}</h2><p className="mt-2">{isFavourites ? "Save an object from its detail page to find it here." : "Try different filters or clear your current search."}</p><Link data-hv-action="primary" className="mt-5 inline-flex min-h-11 items-center px-5 py-3" href={route("products.index", "all")}>Browse all objects</Link></section>}
    </div>;
}
