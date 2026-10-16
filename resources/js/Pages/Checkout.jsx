import { Head, Link, usePage } from "@inertiajs/react";
import ShoppingCart from "@/Components/ShoppingCart";
import Money from "@/Components/common/Money";
import QuoteTotals from "@/Components/common/QuoteTotals";
import useCheckout from "@/utils/useCheckout";
export default function Checkout({ resume }) {
  const checkout = useCheckout(resume, usePage().props.auth?.user);
  const {
    contact,
    setContact,
    address,
    setAddress,
    promo,
    setPromo,
    quote,
    attempt,
    busy,
    errors,
    message,
    changed,
    closed,
    locked,
    invalidate,
    requestQuote,
    pay,
    startAgain,
    errorFocus,
    products,
  } = checkout;
  const field = (group, name, label, type = "text", autoComplete) => {
    const key = `${group}.${name}`;
    return (
      <div key={key} className="flex flex-col gap-2">
        <label htmlFor={key}>
          {label}
          <input
            id={key}
            name={name}
            type={type}
            autoComplete={autoComplete}
            required
            value={group === "contact" ? contact[name] : address[name]}
            disabled={locked || busy}
            onChange={(event) => {
              (group === "contact" ? setContact : setAddress)((previous) => ({
                ...previous,
                [name]: event.target.value,
              }));
              invalidate();
            }}
            aria-invalid={Boolean(errors[key])}
            aria-describedby={errors[key] ? `${key}-error` : undefined}
          />
        </label>
        {errors[key] && (
          <span id={`${key}-error`} className="field-error">
            {errors[key][0]}
          </span>
        )}
      </div>
    );
  };
  return (
    <div className="page-width checkout-page">
      <Head title="Checkout" />
      <p className="eyebrow">
        <Link href="/products/all">Collection</Link> / Checkout
      </p>
      <h1>Your next find.</h1>
      <div className="checkout-layout">
        <div className="checkout-details">
          <ShoppingCart onEdit={invalidate} checkoutLink={false} />
          <form onSubmit={requestQuote} className="checkout-details">
            <section className="surface">
              <h2>Contact details</h2>
              <div className="form-grid">
                {field(
                  "contact",
                  "first_name",
                  "First name",
                  "text",
                  "given-name",
                )}
                {field(
                  "contact",
                  "last_name",
                  "Last name",
                  "text",
                  "family-name",
                )}
                {field("contact", "email", "Email", "email", "email")}
                {field("contact", "mobile", "Australian mobile", "tel", "tel")}
              </div>
            </section>
            <section className="surface">
              <h2>Delivery address</h2>
              <div className="form-grid">
                {field(
                  "address",
                  "street",
                  "Street address",
                  "text",
                  "street-address",
                )}
                {field(
                  "address",
                  "city",
                  "Suburb / city",
                  "text",
                  "address-level2",
                )}
                {field(
                  "address",
                  "state",
                  "State / territory",
                  "text",
                  "address-level1",
                )}
                {field(
                  "address",
                  "postcode",
                  "Postcode",
                  "text",
                  "postal-code",
                )}
              </div>
              <p className="muted">
                Delivery within Australia. Enter your address manually; no
                address lookup is required.
              </p>
            </section>
            <section className="surface">
              <h2>Promo code</h2>
              <label htmlFor="promo-code">
                Code (optional)
                <input
                  id="promo-code"
                  value={promo}
                  disabled={locked || busy}
                  onChange={(event) => {
                    setPromo(event.target.value);
                    invalidate();
                  }}
                />
              </label>
              {errors.promo_code && (
                <p className="field-error">{errors.promo_code[0]}</p>
              )}
            </section>
            {!attempt && (
              <button
                className="button button-light"
                disabled={busy || !products.length}
              >
                {busy ? "Checking…" : "Review your quote ↗"}
              </button>
            )}
          </form>
        </div>
        <aside className="surface checkout-summary">
          <h2>{attempt ? "Existing payment attempt" : "Review your quote"}</h2>
          {quote ? (
            <>
              {quote.lines.map((line, i) => (
                <div key={i} className="summary-line">
                  <span>
                    {line.title} × {line.quantity}
                  </span>
                  <Money
                    minor={line.line_payable_minor}
                    currency={quote.currency}
                  />
                </div>
              ))}
              <QuoteTotals quote={quote} />
              <p className="muted">
                These amounts come from the current server quote. Payment is
                confirmed only by the order status.
              </p>
            </>
          ) : (
            <p className="muted">
              {attempt
                ? "Retrying uses the original order and amounts. Changes to your bag are kept for later; they do not alter this payment."
                : "Delivery, discounts and tax appear after your quote is checked. No payment has been made."}
            </p>
          )}
          {message && (
            <div
              ref={errorFocus}
              tabIndex="-1"
              role={Object.keys(errors).length ? "alert" : "status"}
              className="checkout-message"
            >
              {message}
              {Object.entries(errors)
                .filter(
                  ([key]) =>
                    !key.startsWith("contact.") &&
                    !key.startsWith("address.") &&
                    key !== "promo_code",
                )
                .map(([key, value]) => (
                  <p key={key}>{Array.isArray(value) ? value[0] : value}</p>
                ))}
            </div>
          )}
          {(quote || attempt) && !closed && (
            <button
              className="button button-light"
              disabled={busy}
              onClick={pay}
            >
              {busy
                ? "Preparing payment…"
                : attempt
                  ? "Retry this payment attempt"
                  : changed
                    ? "Accept updated quote & continue ↗"
                    : "Accept quote & continue ↗"}
            </button>
          )}
          {attempt && (
            <div className="flex flex-col gap-3 mt-4">
              <a
                className="text-link"
                href={`/success?attempt_key=${encodeURIComponent(attempt)}`}
              >
                Check payment status
              </a>
              <a
                className="text-link"
                href={`/checkout?attempt_key=${encodeURIComponent(attempt)}`}
              >
                Reload saved payment details
              </a>
            </div>
          )}
          {closed && (
            <button className="button mt-4" onClick={startAgain}>
              Start a new checkout
            </button>
          )}
        </aside>
      </div>
    </div>
  );
}
