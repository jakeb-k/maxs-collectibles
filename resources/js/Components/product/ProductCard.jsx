import { Link } from "@inertiajs/react";
import ProductImage from "./ProductImage";
import Money from "@/Components/common/Money";
export default function ProductCard({ product, eager = false }) {
  const category = product.tags?.find((tag) => tag.type === "category")?.name || "Collectible";
  return (
    <article className="product-card overflow-hidden border border-[#3E3A32] bg-[#F8F4E9] text-[#0D0C09]">
      <Link
        href={`/product/${product.id}`}
        className="product-photo-link block border-b border-[#3E3A32] bg-[#E8DDC4]"
        aria-label={`View ${product.name}`}
      >
        <ProductImage
          image={product.images?.[0]}
          name={product.name}
          eager={eager}
        />
      </Link>
      <div className="p-4">
      <div className="card-meta flex items-center justify-between gap-3 text-xs font-medium uppercase tracking-[0.1em] text-[#3E3A32]">
        <span>{category}</span>
        <span>{product.purchasable ? "Available" : "Unavailable"}</span>
      </div>
      <h3 data-hv-type="display" className="mt-3 text-[var(--hv-heading-sm)] leading-[1.4]">
        <Link className="underline-offset-4 hover:underline" href={route("products.show", product.id)}>{product.name}</Link>
      </h3>
      <div className="mt-3"><Money minor={product.display_unit_minor} currency={product.currency} /></div>
      </div>
    </article>
  );
}
