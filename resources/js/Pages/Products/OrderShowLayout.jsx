import { Head } from "@inertiajs/react";
import { useEffect } from "react";
import Money from "@/Components/common/Money";
import ProductImage from "@/Components/product/ProductImage";
import useCartStore from "@/Stores/useCartStore";
export default function OrderShowLayout({ order }) {
  const settle = useCartStore((state) => state.settleAttempt);
  const paid =
    ["Paid", "Completed"].includes(order.status) && !order.needs_review;
  useEffect(() => {
    if (paid && order.attempt_key) settle(order.attempt_key);
  }, [paid, order.attempt_key, settle]);
  return (
    <div className="page-width py-12 max-w-5xl">
      <Head title={`Order ${order.code}`} />
      <p className="eyebrow">Your order</p>
      <h1 className="my-6 text-4xl">{order.code}</h1>
      <section className="surface mb-8">
        <h2>{order.status}</h2>
        <p role="status">
          {order.needs_review
            ? "This payment requires review. Please contact the store; do not make another payment for this order."
            : order.status === "Completed"
              ? "This order has been marked completed."
              : paid
                ? "Payment confirmed. This order has not yet been marked completed."
                : "Payment has not been confirmed. This is not a payment receipt."}
        </p>
        <p className="muted">
          Created{" "}
          {new Intl.DateTimeFormat("en-AU", { dateStyle: "medium" }).format(
            new Date(order.created_at),
          )}
        </p>
      </section>
      <div className="grid md:grid-cols-2 gap-6 mb-8">
        <section className="surface">
          <h2>Contact at purchase</h2>
          <p>
            {order.contact.first_name} {order.contact.last_name}
          </p>
          <p>{order.contact.email}</p>
          <p>{order.contact.mobile}</p>
        </section>
        <section className="surface">
          <h2>Delivery address at purchase</h2>
          <address className="not-italic">
            {order.delivery.street}
            <br />
            {order.delivery.city}, {order.delivery.state}{" "}
            {order.delivery.postcode}
            <br />
            {order.delivery.country}
          </address>
        </section>
      </div>
      <section className="surface mb-8">
        <h2>Purchased items</h2>
        {order.lines.map((line) => (
          <article
            key={line.id}
            className="grid sm:grid-cols-[150px_1fr] gap-6 border-b border-[#46534c] py-6"
          >
            <ProductImage
              image={{ url: line.image_url, alt_text: line.image_alt }}
              name={line.title}
            />
            <div>
              <h3>{line.title}</h3>
              <p className="my-3">{line.description}</p>
              <p>Quantity: {line.quantity}</p>
              <p>
                Paid per item:{" "}
                <Money
                  minor={line.payable_unit_minor}
                  currency={order.currency}
                />
              </p>
              <p>
                Line total:{" "}
                <Money
                  minor={line.line_payable_minor}
                  currency={order.currency}
                />
              </p>
              {Object.entries(line.selected_options || {}).map(
                ([label, value]) => (
                  <p key={label}>
                    {label}: {value}
                  </p>
                ),
              )}
              <dl className="item-facts mt-4">
                {line.facts.map((fact, i) => (
                  <div key={i}>
                    <dt>{fact.label}</dt>
                    <dd>{fact.value}</dd>
                  </div>
                ))}
              </dl>
            </div>
          </article>
        ))}
      </section>
      <section className="surface">
        <h2>Order amounts</h2>
        <dl className="quote-totals">
          {[
            ["subtotal_minor", "Original item subtotal"],
            ["discount_minor", "Item discounts"],
            ["tax_minor", "Tax recorded within total"],
            ["delivery_minor", "Delivery recorded within total"],
            ["total_minor", "Total paid or payable"],
          ].map(([key, label]) => (
            <div key={key}>
              <dt>{label}</dt>
              <dd>
                <Money minor={order[key]} currency={order.currency} />
              </dd>
            </div>
          ))}
        </dl>
      </section>
      <a className="text-link mt-6" href="/products/all">
        Return to collection ↗
      </a>
    </div>
  );
}
