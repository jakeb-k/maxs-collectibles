import { useEffect, useRef, useState } from "react";
import axios from "axios";
import useCartStore from "@/Stores/useCartStore";
export default function useCheckout(resume, user) {
  const cart = useCartStore();
  const pendingKey =
    cart.pendingKey && cart.attempts[cart.pendingKey] ? cart.pendingKey : null;
  const [contact, setContact] = useState(
    resume?.request?.contact || {
      first_name: user?.first_name || "",
      last_name: user?.last_name || "",
      email: user?.email || "",
      mobile: user?.mobile || "",
    },
  );
  const [address, setAddress] = useState(
    resume?.request?.address || {
      street: "",
      city: "",
      state: "",
      postcode: "",
      country: "AU",
    },
  );
  const [promo, setPromo] = useState(resume?.request?.promo_code || "");
  const [quote, setQuote] = useState(null);
  const [quotedLines, setQuotedLines] = useState([]);
  const [accepted, setAccepted] = useState(resume?.request || null);
  const [attempt, setAttempt] = useState(resume?.attempt_key || pendingKey);
  const [busy, setBusy] = useState(false);
  const submitting = useRef(false);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(
    resume
      ? "Resume this existing payment attempt. No new order will be created."
      : pendingKey
        ? "An earlier attempt may still be pending. Re-enter its original details to retry the same attempt, or return to its saved checkout."
        : "",
  );
  const [changed, setChanged] = useState(false);
  const [closed, setClosed] = useState(false);
  const errorFocus = useRef(null);
  useEffect(() => {
    if (Object.keys(errors).length) errorFocus.current?.focus();
  }, [errors]);
  const locked = Boolean(attempt && accepted);
  const invalidate = () => {
    if (!attempt) {
      setQuote(null);
      setAccepted(null);
      setChanged(false);
    }
  };
  const cartKey = JSON.stringify(
    cart.products.map(({ id, quantity, selection, line_key }) => ({
      id,
      quantity,
      selection,
      line_key,
    })),
  );
  useEffect(() => {
    invalidate();
  }, [cartKey]);
  const body = () => ({
    items: cart.products.map((p) => ({
      product_id: p.id,
      quantity: p.quantity,
      selection: p.selection,
    })),
    contact,
    address,
    promo_code: promo || null,
  });
  const fail = (error, fallback) => {
    setErrors(error.response?.data?.errors || {});
    setMessage(error.response?.data?.message || fallback);
  };
  const requestQuote = async (event) => {
    event.preventDefault();
    if (submitting.current) return;
    submitting.current = true;
    setBusy(true);
    setErrors({});
    setMessage("");
    try {
      const request = body();
      const lines = cart.products.map((p) => ({
        ...p,
        selection: { ...p.selection },
      }));
      const response = await axios.post("/checkout/quote", request);
      setQuote(response.data);
      setAccepted(request);
      setQuotedLines(lines);
      setChanged(false);
    } catch (error) {
      setQuote(null);
      setAccepted(null);
      fail(
        error,
        "Unable to obtain a quote. Check your details and refresh the bag.",
      );
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };
  const pay = async () => {
    if (submitting.current || closed) return;
    const request =
      accepted ||
      (attempt
        ? {
            ...body(),
            items:
              cart.attempts[attempt]?.lines.map((p) => ({
                product_id: p.id,
                quantity: p.quantity,
                selection: p.selection,
              })) || body().items,
          }
        : null);
    if (!request) return;
    const key = attempt || crypto.randomUUID();
    const token =
      quote?.quote_token || resume?.quote_token || cart.attempts[key]?.token;
    if (!token) {
      setMessage("Review a fresh quote before continuing.");
      return;
    }
    submitting.current = true;
    setBusy(true);
    setErrors({});
    setMessage("");
    setAttempt(key);
    setAccepted(request);
    if (!attempt) cart.captureAttempt(key, token, quotedLines);
    try {
      const { data } = await axios.post("/checkout", {
        ...request,
        attempt_key: key,
        quote_token: token,
      });
      if (["paid", "completed"].includes(data.state))
        window.location.assign(
          `/success?attempt_key=${encodeURIComponent(key)}`,
        );
      else if (data.state === "open" && data.url) {
        const url = new URL(data.url);
        if (url.protocol === "https:" && url.hostname === "checkout.stripe.com")
          window.location.assign(url.href);
        else setMessage("Payment link unavailable. Retry this same attempt.");
      } else
        setMessage(
          data.message ||
            "Payment is pending. Check its status or retry this same attempt after 30 seconds.",
        );
    } catch (error) {
      const result = error.response?.data;
      if (result?.code === "quote_changed" && result.quote) {
        cart.releaseAttempt(key);
        setAttempt(null);
        const stillCurrent =
          JSON.stringify(useCartStore.getState().products) ===
          JSON.stringify(quotedLines);
        setQuote(stillCurrent ? result.quote : null);
        if (!stillCurrent) setAccepted(null);
        setChanged(stillCurrent);
        setMessage(
          stillCurrent
            ? "Your quote changed. Review the updated amounts, then explicitly accept the updated quote."
            : "Your bag changed while the quote was checked. Request a fresh quote for the current bag.",
        );
      } else if (result?.code === "stock_changed") {
        cart.releaseAttempt(key);
        setAttempt(null);
        setQuote(null);
        setAccepted(null);
        fail(
          error,
          "Stock changed. Refresh your bag and adjust or remove unavailable items.",
        );
      } else if (result?.code === "checkout_closed") {
        setClosed(true);
        fail(
          error,
          "This payment attempt is closed. Review your bag before deliberately starting again.",
        );
      } else if (error.response?.status === 422 && !resume && !pendingKey) {
        cart.releaseAttempt(key);
        setAttempt(null);
        fail(error, "Check the highlighted details before retrying.");
      } else
        fail(
          error,
          "Checkout could not be confirmed. Retry this same attempt; do not start another payment.",
        );
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };
  const startAgain = () => {
    cart.releaseAttempt(attempt);
    setAttempt(null);
    setAccepted(null);
    setQuote(null);
    setClosed(false);
    setChanged(false);
    setMessage("Review your bag and request a fresh quote.");
  };
  return {
    contact,
    setContact,
    address,
    setAddress,
    promo,
    setPromo,
    quote,
    accepted,
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
    products: cart.products,
  };
}
