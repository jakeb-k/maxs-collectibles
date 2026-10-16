import { Head, Link, router } from "@inertiajs/react";
import ProductCard from "@/Components/product/ProductCard";
import useCatalogueItems from "@/utils/useCatalogueItems";
import useFavouritesStore from "@/Stores/useFavouritesStore";
export default function ProductIndexLayout({
  products = [],
  category = "all",
  filters = {},
}) {
  const favourites = useFavouritesStore((s) => s.favourites);
  const live = useCatalogueItems(category === "favourites" ? favourites : []);
  const shown = category === "favourites" ? live.products : products;
  const title =
    category === "all"
      ? "The collection"
      : category === "favourites"
        ? "Your favourites"
        : category === "sale"
          ? "On sale"
          : category.charAt(0).toUpperCase() + category.slice(1);
  return (
    <div className="page-width catalogue-page">
      <Head title={title} />
      <p className="eyebrow">
        <Link href="/">Home</Link> / Collection
      </p>
      <div className="section-heading">
        <h1>{title}</h1>
        <span>
          {shown.length} {shown.length === 1 ? "object" : "objects"}
        </span>
      </div>
      {category !== "favourites" && (
        <form
          key={JSON.stringify(filters)}
          className="catalogue-filters"
          action={`/products/${encodeURIComponent(category)}`}
          onSubmit={(event) => {
            event.preventDefault();
            const params = Object.fromEntries(
              [...new FormData(event.currentTarget)].filter(
                ([, value]) => value !== "",
              ),
            );
            router.get(`/products/${encodeURIComponent(category)}`, params);
          }}
        >
          {filters.type && (
            <input type="hidden" name="type" value={filters.type} />
          )}
          <label>
            Search the collection
            <input
              type="search"
              name="q"
              defaultValue={filters.q || ""}
              placeholder="Name, category or tag"
            />
          </label>
          <label>
            Availability
            <select
              name="availability"
              defaultValue={filters.availability || "all"}
            >
              <option value="all">All items</option>
              <option value="in_stock">In stock</option>
            </select>
          </label>
          <label>
            Price range
            <select
              name="max_price_minor"
              defaultValue={filters.max_price_minor || ""}
            >
              <option value="">Any price</option>
              <option value="2500">Up to $25 AUD</option>
              <option value="5000">Up to $50 AUD</option>
              <option value="10000">Up to $100 AUD</option>
              <option value="50000">Up to $500 AUD</option>
            </select>
          </label>
          <label>
            Sort by
            <select name="sort" defaultValue={filters.sort || "newest"}>
              <option value="newest">Newest first</option>
              <option value="name">Name</option>
              <option value="price_asc">Price: low to high</option>
              <option value="price_desc">Price: high to low</option>
              <option value="popular">Most purchased</option>
            </select>
          </label>
          <button className="button" type="submit">
            Apply
          </button>
          <Link
            className="text-link"
            href={`/products/${encodeURIComponent(category)}`}
          >
            Clear
          </Link>
        </form>
      )}
      {category === "favourites" && (
        <>
          {live.pending && <p role="status">Checking saved items…</p>}
          {live.error && (
            <div role="alert">
              <p>{live.error}</p>
              <button className="button" onClick={live.refresh}>
                Retry favourites refresh
              </button>
            </div>
          )}
          {live.missing.length > 0 && (
            <p role="status">
              {live.missing.length} saved item(s) are no longer available.{" "}
              <button
                className="text-link"
                onClick={() =>
                  useFavouritesStore
                    .getState()
                    .setFavourites(
                      favourites.filter((id) => !live.missing.includes(id)),
                    )
                }
              >
                Remove unavailable favourites
              </button>
            </p>
          )}
        </>
      )}
      {shown.length ? (
        <div className="product-grid">
          {shown.map((p) => (
            <ProductCard key={p.id} product={p} />
          ))}
        </div>
      ) : (
        <div className="empty-state">
          <h2>
            {category === "favourites"
              ? "No favourites saved yet."
              : "No objects found."}
          </h2>
          <p>
            {category === "favourites"
              ? "Save an item from its detail page to find it here."
              : "Try a different search or clear your filters."}
          </p>
          <Link className="button" href="/products/all">
            Browse all objects
          </Link>
        </div>
      )}
    </div>
  );
}
