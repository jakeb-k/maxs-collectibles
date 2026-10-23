import { Head, Link } from "@inertiajs/react";
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
    <div className="hidden-vault min-h-full bg-[#F8F4E9] text-[#0D0C09]"><div className="page-width max-w-5xl py-8 md:py-12">
      <Head title={`Order ${order.code}`} />
      <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Your order</p>
      <h1 data-hv-type="display" className="my-4 text-[var(--hv-display-sm)] md:text-[var(--hv-display-md)]">{order.code}</h1>
      <section className="surface mb-8 border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
        <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Order status</p><h2 data-hv-type="display" className="mt-2 text-[var(--hv-heading-sm)]">{order.status}</h2>
        <p role="status" className="mt-3 text-sm">
          {order.needs_review
            ? "This payment requires review. Please contact the store; do not make another payment for this order."
            : order.status === "Completed"
              ? "This order has been marked completed."
              : paid
                ? "Payment confirmed. This order has not yet been marked completed."
                : "Payment has not been confirmed. This is not a payment receipt."}
        </p>
        <p className="mt-3 text-sm text-[#3E3A32]">
          Created{" "}
          {new Intl.DateTimeFormat("en-AU", { dateStyle: "medium" }).format(
            new Date(order.created_at),
          )}
        </p>
      </section>
      <div className="grid md:grid-cols-2 gap-6 mb-8">
        <section className="surface border border-[#3E3A32] bg-[#E8DDC4] p-5">
          <h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">Contact at purchase</h2>
          <p>
            {order.contact.first_name} {order.contact.last_name}
          </p>
          <p>{order.contact.email}</p>
          <p>{order.contact.mobile}</p>
        </section>
        <section className="surface border border-[#3E3A32] bg-[#E8DDC4] p-5">
          <h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">Delivery address at purchase</h2>
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
      <section className="surface mb-8 border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
        <h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">Purchased items</h2>
        {order.lines.map((line) => (
          <article
            key={line.id}
            className="grid sm:grid-cols-[150px_1fr] gap-6 border-b border-[#F8F4E9] py-6"
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
      <section className="surface border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
        <h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">Order amounts</h2>
        <dl className="quote-totals mt-4 divide-y divide-[#F8F4E9] border-y border-[#3E3A32]">
          {[
            ["subtotal_minor", "Original item subtotal"],
            ["discount_minor", "Item discounts"],
            ["tax_minor", "Tax recorded within total"],
            ["delivery_minor", "Delivery recorded within total"],
            ["total_minor", "Total paid or payable"],
          ].map(([key, label]) => (
            <div key={key} className="flex justify-between gap-4 py-3">
              <dt>{label}</dt>
              <dd>
                <Money minor={order[key]} currency={order.currency} />
              </dd>
            </div>
          ))}
        </dl>
      </section>
      <Link className="mt-6 inline-flex min-h-11 items-center underline underline-offset-4" href={route("products.index", "all")}>Return to collection ↗</Link>
    </div></div>
  );
}
