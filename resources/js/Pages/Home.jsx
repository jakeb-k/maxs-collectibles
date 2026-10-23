import { Head, Link } from "@inertiajs/react";
import ProductCard from "@/Components/product/ProductCard";
import CabinetHero from "@/Components/editorial/CabinetHero";
import VaultCategoryStrip from "@/Components/editorial/VaultCategoryStrip";
import vaultNavigation from "@/content/vault-navigation";
export default function Home({ products = [] }) {
  const featured = products[0];
  return (
    <>
      <Head title="The Hidden Vault" />
      <CabinetHero product={featured} />
      <VaultCategoryStrip categories={vaultNavigation.categories} />
      <section className="bg-[#F8F4E9] px-4 py-12 text-[#0D0C09] md:px-8 lg:px-12">
        <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Latest objects</p>
            <h2 data-hv-type="display" className="mt-2 text-[var(--hv-display-sm)] leading-[1.29] md:text-[var(--hv-display-md)] md:leading-[1.2]">In the collection</h2>
          </div>
          <Link className="min-h-11 underline underline-offset-4" href={route("products.index", "all")}>
            View all objects
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
