import React from "react";
import Money from "./Money";

// Each row selects a canonical server amount. Exclusive tax includes delivery
// tax, so its delivery row must use the amount before that tax is added.
export default function QuoteTotals({ quote }) {
  const rows = [
    ["Subtotal", quote.totals.subtotal_minor],
    ["Discount", quote.totals.discount_minor],
    [
      quote.tax_mode === "inclusive" ? "Tax (included)" : "Tax",
      quote.totals.tax_minor,
    ],
    [
      "Delivery",
      quote.tax_mode === "exclusive"
        ? quote.delivery.net_minor
        : quote.totals.delivery_minor,
    ],
    ["Total", quote.totals.total_minor],
  ];
  return (
    <dl className="quote-totals">
      {rows.map(([label, minor]) => (
        <div key={label}>
          <dt>{label}</dt>
          <dd>
            <Money minor={minor} currency={quote.currency} />
          </dd>
        </div>
      ))}
    </dl>
  );
}
