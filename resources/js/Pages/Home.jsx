import { Head, Link } from "@inertiajs/react";
import ProductCard from "@/Components/product/ProductCard";
import ProductImage from "@/Components/product/ProductImage";
export default function Home({ products = [] }) {
  const featured = products[0];
  const categories = [
    ...new Map(
      products
        .flatMap((p) => p.tags || [])
        .filter((t) => t.type === "category")
        .map((t) => [t.id, t]),
    ).values(),
  ];
  return (
    <>
      <Head title="Objects worth a closer look" />
      <section className="home-intro page-width">
        <div className="intro-copy">
          <p className="eyebrow">Max’s Collectibles / The collection</p>
          <h1>
            Objects worth
            <br />
            <em>a closer look.</em>
          </h1>
          <p>
            A place to find your next collectible. Explore the objects, inspect
            the details, make it yours.
          </p>
          <Link className="button button-light" href="/products/all">
            Explore the collection <span aria-hidden="true">↗</span>
          </Link>
          <p className="intro-note">
            Photography and item details, front and centre.
          </p>
        </div>
        <div className="hero-object">
          {featured ? (
            <Link href={`/product/${featured.id}`}>
              <ProductImage
                image={featured.images?.[0]}
                name={featured.name}
                eager
              />
              <div className="hero-caption">
                <span>In the collection</span>
                <strong>{featured.name}</strong>
                <span aria-hidden="true">↗</span>
              </div>
            </Link>
          ) : (
            <>
              <ProductImage name="the collection" />
              <p className="hero-caption">
                New objects will appear here when available.
              </p>
            </>
          )}
        </div>
      </section>
      <section className="page-width collection-section">
        {categories.length > 0 && (
          <nav className="category-links" aria-label="Browse by category">
            {categories.map((tag) => (
              <Link
                key={tag.id}
                href={`/products/${encodeURIComponent(tag.slug || tag.name)}`}
              >
                {tag.name} ↗
              </Link>
            ))}
          </nav>
        )}
        <div className="section-heading">
          <div>
            <p className="eyebrow">An open invitation to explore</p>
            <h2>In the collection</h2>
          </div>
          <Link className="text-link" href="/products/all">
            View all objects ↗
          </Link>
        </div>
        {products.length ? (
          <div className="product-grid">
            {products.slice(0, 6).map((p) => (
              <ProductCard key={p.id} product={p} />
            ))}
          </div>
        ) : (
          <div className="empty-state">
            <h3>The collection is taking shape.</h3>
            <p>
              There are no items to browse right now. Please check back later.
            </p>
          </div>
        )}
      </section>
    </>
  );
}
