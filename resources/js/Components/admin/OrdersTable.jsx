import { useState } from "react";
import axios from "axios";
import Money from "@/Components/common/Money";
export default function OrdersTable({ orders, onChange }) {
  const [busy, setBusy] = useState(null);
  const [message, setMessage] = useState("");
  const complete = async (order) => {
    setBusy(order.id);
    setMessage("");
    try {
      const { data } = await axios.post(`/orders/${order.id}/complete`);
      onChange(data.orders);
      setMessage(
        "Order marked completed. The completion email is queued for delivery.",
      );
    } catch (error) {
      setMessage(
        error.response?.data?.message ||
          "The order could not be completed. Refresh its payment state and retry.",
      );
    } finally {
      setBusy(null);
    }
  };
  return (
    <section aria-label="Orders">
      <h2>Orders</h2>
      {message && (
        <p role="status" className="my-5">
          {message}
        </p>
      )}
      {!orders.length && <p>No orders yet.</p>}
      <div className="grid lg:grid-cols-2 gap-5 mt-6">
        {orders.map((order) => (
          <article className="surface" key={order.id}>
            <h3>
              <a href={`/orders/${order.id}`} className="text-link">
                {order.code}
              </a>
            </h3>
            <p className="my-3">
              {order.contact.first_name} {order.contact.last_name}
            </p>
            <p>Status: {order.status}</p>
            <p>Payment: {order.checkout_state || "No checkout attempt"}</p>
            {order.needs_review && (
              <p role="status" className="field-error">
                Payment requires review. Fulfilment is blocked.
              </p>
            )}
            <p className="my-3">
              <Money minor={order.total_minor} currency={order.currency} />
            </p>
            <button
              className="button"
              disabled={
                busy === order.id ||
                order.status !== "Paid" ||
                order.needs_review
              }
              onClick={() => complete(order)}
            >
              Complete order {order.code}
            </button>
          </article>
        ))}
      </div>
    </section>
  );
}
