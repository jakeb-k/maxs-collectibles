import React from "react";
export const formatMoney = (minor, currency = "AUD") =>
  Number.isInteger(minor)
    ? new Intl.NumberFormat("en-AU", { style: "currency", currency }).format(
        minor / 100,
      )
    : "Price unavailable";
export default function Money({ minor, currency = "AUD" }) {
  return (
    <span>
      {formatMoney(minor, currency)} <small>{currency}</small>
    </span>
  );
}
