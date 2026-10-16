import { Link } from "@inertiajs/react";
import ProductImage from "./ProductImage";
import Money from "@/Components/common/Money";
export default function ProductCard({ product, eager = false }) {
  return (
    <article className="product-card">
      <Link
        href={`/product/${product.id}`}
        className="product-photo-link"
        aria-label={`View ${product.name}`}
      >
        <ProductImage
          image={product.images?.[0]}
          name={product.name}
          eager={eager}
        />
      </Link>
      <div className="card-meta">
        <span className="eyebrow">
          {product.tags?.find((t) => t.type === "category")?.name ||
            "Collectible"}
        </span>
        <span>{product.purchasable ? "Available" : "Unavailable"}</span>
      </div>
      <h3>
        <Link href={`/product/${product.id}`}>{product.name}</Link>
      </h3>
      <Money minor={product.display_unit_minor} currency={product.currency} />
    </article>
  );
}
