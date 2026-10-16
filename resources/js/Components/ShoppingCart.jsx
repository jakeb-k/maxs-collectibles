import { Link } from "@inertiajs/react";
import useCartStore from "@/Stores/useCartStore";
import useCatalogueItems from "@/utils/useCatalogueItems";
import Money from "@/Components/common/Money";
export default function ShoppingCart({ onEdit, checkoutLink = true }) {
  const { products, updateQuantity, removeItem, notice } = useCartStore();
  const current = useCatalogueItems(products.map((p) => p.id));
  return (
    <section className="surface" aria-label="Shopping bag">
      <h2>Your bag</h2>
      {notice && <p role="status">{notice}</p>}
      {current.pending && <p role="status">Checking current items…</p>}
      {current.error && (
        <div role="alert">
          <p>{current.error}</p>
          <button className="button" onClick={current.refresh}>
            Retry bag refresh
          </button>
        </div>
      )}
      {!products.length && (
        <div className="empty-state">
          <p>Your bag is empty.</p>
          <Link className="text-link" href="/products/all">
            Explore the collection ↗
          </Link>
        </div>
      )}
      {products.map((line) => {
        const product = current.products.find((p) => p.id === line.id);
        const missing = current.missing.includes(line.id);
        const invalidSelection =
          product &&
          (Object.keys(line.selection).length !== product.options.length ||
            !product.options.every((option) =>
              option.values.includes(line.selection[option.type]),
            ));
        return (
          <article
            key={line.line_key}
            className="border-b border-[#46534c] py-5"
          >
            <h3>
              {product ? (
                <Link href={`/product/${line.id}`}>{product.name}</Link>
              ) : missing ? (
                "Item no longer available"
              ) : (
                `Item ${line.id}`
              )}
            </h3>
            {product && (
              <p className="my-2">
                <Money
                  minor={product.display_unit_minor}
                  currency={product.currency}
                />{" "}
                <small>per item, before checkout promo</small>
              </p>
            )}
            {Object.entries(line.selection).map(([type, value]) => (
              <p key={type} className="text-sm">
                {type}: {value}
              </p>
            ))}
            {product &&
              (!product.purchasable ||
                line.quantity > product.available_quantity) && (
                <p role="status" className="field-error">
                  Stock has changed. Available now: {product.available_quantity}
                  . Adjust the quantity or remove this item.
                </p>
              )}
            {invalidSelection && (
              <p role="status" className="field-error">
                Item choices changed. Remove this entry and choose the current
                options on the item page.
              </p>
            )}
            <div className="flex flex-wrap items-end gap-4 mt-4">
              <label>
                Quantity for {product?.name || `item ${line.id}`}
                <input
                  type="number"
                  className="max-w-28"
                  min="1"
                  max="999999"
                  value={line.quantity}
                  onChange={(e) => {
                    updateQuantity(line.line_key, Number(e.target.value));
                    onEdit?.();
                  }}
                />
              </label>
              <button
                className="text-link"
                aria-label={`Remove ${product?.name || `item ${line.id}`} from bag`}
                onClick={() => {
                  removeItem(line.line_key);
                  onEdit?.();
                }}
              >
                Remove
              </button>
            </div>
          </article>
        );
      })}
      {products.length > 0 && (
        <>
          <p className="muted">
            Prices and availability are checked again in your checkout quote.
          </p>
          <button className="text-link" onClick={current.refresh}>
            Refresh bag
          </button>
          {checkoutLink && (
            <Link className="button button-light mt-5 w-full" href="/checkout">
              Review checkout ↗
            </Link>
          )}
        </>
      )}
    </section>
  );
}
