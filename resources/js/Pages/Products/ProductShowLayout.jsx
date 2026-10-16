import { Head, Link } from "@inertiajs/react";
import { useState } from "react";
import ProductImage from "@/Components/product/ProductImage";
import ProductCard from "@/Components/product/ProductCard";
import Money from "@/Components/common/Money";
import useCartStore from "@/Stores/useCartStore";
import useFavouritesStore from "@/Stores/useFavouritesStore";
export default function ProductShowLayout({ product, relatedItems = [] }) {
  const [index, setIndex] = useState(0);
  const [selection, setSelection] = useState({});
  const [quantity, setQuantity] = useState(1);
  const [message, setMessage] = useState("");
  const addProduct = useCartStore((s) => s.addProduct);
  const { favourites, addFavourite, removeFavourite } = useFavouritesStore();
  const saved = favourites.includes(product.id);
  const images = product.images || [];
  const options = product.options || [];
  const canAdd =
    product.purchasable &&
    Number.isInteger(product.display_unit_minor) &&
    options.every((o) => o.values.includes(selection[o.type]));
  const add = () => {
    addProduct({
      id: product.id,
      product_id: product.id,
      name: product.name,
      selection,
      quantity,
      display_unit_minor: product.display_unit_minor,
      currency: product.currency,
      images: product.images,
    });
    setMessage("Added to your bag.");
  };
  return (
    <div className="page-width item-page">
      <Head title={product.name} />
      <p className="eyebrow">
        <Link href="/products/all">Collection</Link> / {product.name}
      </p>
      <div className="item-layout">
        <section className="gallery" aria-label="Item photographs">
          <div
            className="main-photo"
            onKeyDown={(e) => {
              if (
                images.length > 1 &&
                ["ArrowLeft", "ArrowRight"].includes(e.key)
              ) {
                e.preventDefault();
                setIndex(
                  (index + (e.key === "ArrowRight" ? 1 : -1) + images.length) %
                    images.length,
                );
              }
            }}
            tabIndex={images.length > 1 ? 0 : undefined}
          >
            <ProductImage image={images[index]} name={product.name} eager />
          </div>
          {images[index]?.caption && (
            <p className="photo-caption">{images[index].caption}</p>
          )}
          {images.length > 1 && (
            <div className="gallery-controls">
              <button
                className="button"
                onClick={() =>
                  setIndex((index - 1 + images.length) % images.length)
                }
                aria-label="Previous photograph"
              >
                ←
              </button>
              <span aria-live="polite">
                {index + 1} / {images.length}
              </span>
              <button
                className="button"
                onClick={() => setIndex((index + 1) % images.length)}
                aria-label="Next photograph"
              >
                →
              </button>
            </div>
          )}
          {images.length > 1 && (
            <div className="thumbnails">
              {images.map((image, i) => (
                <button
                  key={image.id}
                  onClick={() => setIndex(i)}
                  aria-label={`View photograph ${i + 1}`}
                  aria-pressed={index === i}
                >
                  <ProductImage
                    image={image}
                    name={`${product.name}, view ${i + 1}`}
                  />
                </button>
              ))}
            </div>
          )}
        </section>
        <section className="item-details">
          <p className="eyebrow">
            {product.tags
              ?.filter((t) => t.type === "category")
              .map((t) => t.name)
              .join(" / ") || "The collection"}
          </p>
          <h1>{product.name}</h1>
          <div className="item-price">
            <Money
              minor={product.display_unit_minor}
              currency={product.currency}
            />
          </div>
          <p className="availability">
            {product.purchasable
              ? `${product.available_quantity} available`
              : "Currently unavailable"}
          </p>
          <p className="item-description">{product.description}</p>
          {product.facts?.length > 0 && (
            <dl className="item-facts">
              {product.facts.map((fact, i) => (
                <div key={i}>
                  <dt>{fact.label}</dt>
                  <dd>{fact.value}</dd>
                </div>
              ))}
            </dl>
          )}
          <div className="purchase-panel">
            {options.map((option) => (
              <label key={option.type}>
                {option.type}
                <select
                  value={selection[option.type] || ""}
                  onChange={(e) => {
                    setSelection({
                      ...selection,
                      [option.type]: e.target.value,
                    });
                    setMessage("");
                  }}
                >
                  <option value="">Choose {option.type}</option>
                  {option.values.map((value) => (
                    <option key={value}>{value}</option>
                  ))}
                </select>
              </label>
            ))}
            <label>
              Quantity
              <input
                type="number"
                min="1"
                max={product.available_quantity || 1}
                value={quantity}
                onChange={(e) => {
                  const next = Number(e.target.value);
                  setQuantity(
                    Number.isInteger(next)
                      ? Math.max(
                          1,
                          Math.min(product.available_quantity || 1, next),
                        )
                      : 1,
                  );
                }}
              />
            </label>
            <button
              className="button button-light"
              onClick={add}
              disabled={!canAdd}
            >
              {product.purchasable ? "Add to bag ↗" : "Unavailable"}
            </button>
            <button
              className="text-link"
              aria-pressed={saved}
              onClick={() =>
                saved ? removeFavourite(product.id) : addFavourite(product.id)
              }
            >
              {saved ? "Remove from favourites" : "Save to favourites"}
            </button>
            <p role="status">{message}</p>
          </div>
        </section>
      </div>
      {relatedItems.length > 0 && (
        <section className="collection-section">
          <div className="section-heading">
            <h2>Continue exploring</h2>
          </div>
          <div className="product-grid">
            {relatedItems.map((item) => (
              <ProductCard key={item.id} product={item} />
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
