import { useState } from "react";
import axios from "axios";
import Money from "@/Components/common/Money";
export default function ProductsTable({ products, onEdit, onChange }) {
  const [busy, setBusy] = useState(null);
  const [message, setMessage] = useState("");
  const [deleting, setDeleting] = useState(null);
  const mutate = async (product, action) => {
    setBusy(product.id);
    setMessage("");
    try {
      const { data } =
        action === "delete"
          ? await axios.delete(`/product/${product.id}/delete`)
          : await axios.post(`/product/${product.id}/available`, {
              available: !product.available,
            });
      onChange(data.products);
      setDeleting(null);
    } catch (error) {
      setMessage(
        error.response?.data?.message ||
          "The item could not be changed. Refresh and retry.",
      );
    } finally {
      setBusy(null);
    }
  };
  return (
    <section aria-label="Inventory">
      <h2>Inventory</h2>
      {message && (
        <p role="alert" className="field-error">
          {message}
        </p>
      )}
      {!products.length && <p>No items yet. Create an item to begin.</p>}
      <div className="grid lg:grid-cols-2 gap-5 mt-6">
        {products.map((product) => (
          <article className="surface" key={product.id}>
            <h3>
              <a href={`/product/${product.id}`} className="text-link">
                {product.name}
              </a>
            </h3>
            <p className="my-3">
              <Money
                minor={product.display_unit_minor}
                currency={product.currency}
              />
            </p>
            <dl className="grid grid-cols-2 gap-2 text-sm">
              <dt>Physical stock</dt>
              <dd>{product.stock_quantity}</dd>
              <dt>Held for payment</dt>
              <dd>{product.held_quantity}</dd>
              <dt>Sellable now</dt>
              <dd>{product.available_quantity}</dd>
              <dt>New purchases</dt>
              <dd>{product.available ? "Enabled" : "Paused"}</dd>
            </dl>
            <div className="flex flex-wrap gap-4 mt-5">
              <button className="button" onClick={() => onEdit(product)}>
                Edit {product.name}
              </button>
              <button
                className="button"
                disabled={busy === product.id}
                onClick={() => mutate(product, "pause")}
              >
                {product.available ? "Pause" : "Resume"} {product.name}
              </button>
              <button
                className="text-link"
                onClick={() => setDeleting(product.id)}
              >
                Delete {product.name}
              </button>
            </div>
            {deleting === product.id && (
              <div className="mt-5">
                <p>
                  Remove this item from new purchases? Existing orders keep
                  their purchase facts.
                </p>
                <button
                  className="button mt-3"
                  disabled={busy === product.id}
                  onClick={() => mutate(product, "delete")}
                >
                  Confirm delete {product.name}
                </button>
                <button
                  className="text-link ml-4"
                  onClick={() => setDeleting(null)}
                >
                  Cancel deletion
                </button>
              </div>
            )}
          </article>
        ))}
      </div>
    </section>
  );
}
