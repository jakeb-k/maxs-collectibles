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
    <div className="hidden-vault vault-checkout bg-[#F8F4E9] text-[#0D0C09]">
      <div className="page-width checkout-page vault-checkout__inner py-8 md:py-12">
      <Head title="Checkout" />
      <nav className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]" aria-label="Breadcrumb">
        <Link href={route("products.index", "all")}>Collection</Link><span aria-hidden="true"> / </span><span>Checkout</span>
      </nav>
      <header className="vault-checkout__intro mt-5 border-b border-[#3E3A32] pb-6">
        <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Secure checkout</p>
        <h1 data-hv-type="display" className="mt-2 text-[var(--hv-display-sm)] leading-[1.29] md:text-[var(--hv-display-md)] md:leading-[1.2]">Review your quote.</h1>
        <p className="mt-3 max-w-2xl text-sm text-[#3E3A32]">Prices, stock, discounts and delivery are confirmed by the server before payment.</p>
      </header>
      <div className="checkout-layout vault-checkout__layout mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.7fr)] lg:items-start">
        <div className="checkout-details">
          <ShoppingCart onEdit={invalidate} checkoutLink={false} />
          <form onSubmit={requestQuote} className="checkout-details mt-8 grid gap-6">
            <section className="surface vault-checkout__step border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
              <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Step 1</p><h2 data-hv-type="display" className="mt-2 text-[var(--hv-heading-sm)]">Contact details</h2>
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
            <section className="surface vault-checkout__step border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
              <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Step 2</p><h2 data-hv-type="display" className="mt-2 text-[var(--hv-heading-sm)]">Delivery address</h2>
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
            <section className="surface vault-checkout__step border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
              <h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">Promo code</h2>
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
                data-hv-action="primary" className="min-h-11 px-5 py-3"
                disabled={busy || !products.length}
              >
                {busy ? "Checking…" : "Review your quote ↗"}
              </button>
            )}
          </form>
        </div>
        <aside className="surface checkout-summary vault-checkout__summary border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6 lg:sticky lg:top-6">
          <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Server-authoritative</p><h2 data-hv-type="display" className="mt-2 text-[var(--hv-heading-sm)]">{attempt ? "Existing payment attempt" : "Review your quote"}</h2>
          {quote ? (
            <>
              {quote.lines.map((line, i) => (
                <div key={i} className="summary-line flex justify-between gap-4 border-b border-[#F8F4E9] py-3 text-sm">
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
              <p className="mt-4 text-sm text-[#3E3A32]">
                These amounts come from the current server quote. Payment is
                confirmed only by the order status.
              </p>
            </>
          ) : (
            <p className="mt-4 text-sm text-[#3E3A32]">
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
              className="checkout-message mt-5 border border-[#3E3A32] bg-[#F8F4E9] p-4 text-sm"
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
              data-hv-action="primary" className="mt-5 min-h-11 w-full px-5 py-3"
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
            <button className="mt-4 min-h-11 border border-[#3E3A32] px-5 py-3" type="button" onClick={startAgain}>
              Start a new checkout
            </button>
          )}
        </aside>
      </div>
      </div>
    </div>
  );
}
