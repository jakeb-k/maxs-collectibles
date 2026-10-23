import { Link } from "@inertiajs/react";
import useCartStore from "@/Stores/useCartStore";
import useCatalogueItems from "@/utils/useCatalogueItems";
import Money from "@/Components/common/Money";
import ProductImage from "@/Components/product/ProductImage";

export default function ShoppingCart({ onEdit, checkoutLink = true }) {
  const { products, updateQuantity, removeItem, notice } = useCartStore();
  const current = useCatalogueItems(products.map((p) => p.id));
  const needsQuoteReduction = products.length > 99;
  return (
    <section className="hidden-vault-bag__contents" aria-label="Shopping bag">
      <div className="border-b border-[#C9A661] pb-4">
        <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#E8DDC4]">Your cabinet</p>
        <h2 data-hv-type="display" className="mt-2 text-[var(--hv-display-sm)]">Your bag</h2>
      </div>
      {notice && <p role="status" className="mt-4 border border-[#C9A661] p-3 text-sm">{notice}</p>}
      {current.pending && <p role="status">Checking current items…</p>}
      {current.error && (
        <div role="alert">
          <p>{current.error}</p>
          <button className="mt-3 min-h-11 underline underline-offset-4" type="button" onClick={current.refresh}>
            Retry bag refresh
          </button>
        </div>
      )}
      {!products.length && (
        <div className="empty-state mt-6 border border-[#C9A661] p-5">
          <p>Your bag is empty.</p>
          <Link className="text-link" href="/products/all">
            Explore the collection ↗
          </Link>
        </div>
      )}
      {needsQuoteReduction && (
        <div role="alert" className="mt-5 border border-[#FF4D6D] bg-[#3E3A32] p-4 text-sm">
          This bag has {products.length} selections. Checkout can quote up to 99 selections, so remove at least {products.length - 99} before requesting a quote.
        </div>
      )}
      <div className="divide-y divide-[#E8DDC4]">
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
            className="grid grid-cols-[5rem_minmax(0,1fr)] gap-4 py-5"
          >
            <div className="aspect-square overflow-hidden border border-[#C9A661] bg-[#3E3A32]">
              <ProductImage image={product?.images?.[0]} name={product?.name || `item ${line.id}`} />
            </div>
            <div>
            <h3 data-hv-type="display" className="text-[var(--hv-heading-sm)]">
              {product ? (
                <Link href={`/product/${line.id}`}>{product.name}</Link>
              ) : missing ? (
                "Item no longer available"
              ) : (
                `Item ${line.id}`
              )}
            </h3>
            {product && (
              <p className="my-2 text-sm">
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
                  className="max-w-28 bg-[#F8F4E9] text-[#0D0C09]"
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
                className="min-h-11 underline underline-offset-4"
                type="button"
                aria-label={`Remove ${product?.name || `item ${line.id}`} from bag`}
                onClick={() => {
                  removeItem(line.line_key);
                  onEdit?.();
                }}
              >
                Remove
              </button>
            </div>
            </div>
          </article>
        );
      })}
      </div>
      {products.length > 0 && (
        <>
          <p className="mt-5 text-sm text-[#E8DDC4]">
            Prices and availability are checked again in your checkout quote.
          </p>
          <button className="mt-2 min-h-11 underline underline-offset-4" type="button" onClick={current.refresh}>
            Refresh bag
          </button>
          {checkoutLink && (
            <Link className={`mt-5 flex min-h-11 w-full items-center justify-center px-5 py-3 ${needsQuoteReduction ? "pointer-events-none opacity-50" : ""}`} data-hv-action="primary" aria-disabled={needsQuoteReduction || undefined} href="/checkout">
              Review checkout ↗
            </Link>
          )}
        </>
      )}
    </section>
  );
}
