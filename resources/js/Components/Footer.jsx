import { useState } from "react";
import axios from "axios";
import { Link } from "@inertiajs/react";
export default function Footer() {
  const [email, setEmail] = useState("");
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState("");
  const [invalid, setInvalid] = useState(false);
  const subscribe = async (event) => {
    event.preventDefault();
    setPending(true);
    setMessage("");
    setInvalid(false);
    try {
      await axios.post("/email/subscribe", { email });
      setMessage(
        "You’re subscribed. Your welcome email is queued for delivery.",
      );
    } catch (error) {
      setInvalid(true);
      setMessage(
        error.response?.data?.errors?.email?.[0] ||
          "We couldn’t subscribe you. Please try again.",
      );
    } finally {
      setPending(false);
    }
  };
  return (
    <footer className="store-footer">
      <div className="page-width footer-grid">
        <div>
          <Link className="wordmark" href="/">
            MAX’S<span>COLLECTIBLES</span>
          </Link>
          <p>Objects worth a closer look.</p>
        </div>
        <nav aria-label="Footer navigation">
          <Link href="/products/all">Collection</Link>
          <Link href="/faqs">Information</Link>
          <Link href="/faqs#contact">Contact</Link>
          <Link href="/profile">Your account</Link>
        </nav>
        <form onSubmit={subscribe}>
          <label htmlFor="subscriber-email">Stay in the loop</label>
          <p>Updates from Max’s Collectibles.</p>
          <div className="subscribe-row">
            <input
              id="subscriber-email"
              aria-invalid={invalid}
              aria-describedby={message ? "subscriber-status" : undefined}
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="Your email address"
            />
            <button className="button" disabled={pending}>
              {pending ? "Joining…" : "Subscribe ↗"}
            </button>
          </div>
          <p id="subscriber-status" role="status">
            {message}
          </p>
        </form>
      </div>
      <div className="page-width footer-bottom">
        <span>© {new Date().getFullYear()} Max’s Collectibles</span>
        <span>All prices in AUD</span>
      </div>
    </footer>
  );
}
