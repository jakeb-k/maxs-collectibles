import { Link } from "@inertiajs/react";
import axios from "axios";
import { useState } from "react";

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
            await axios.post(route("subscribe.email"), { email });
            setMessage("You’re subscribed. Your welcome email is queued for delivery.");
        } catch (error) {
            setInvalid(true);
            setMessage(error.response?.data?.errors?.email?.[0] || "We couldn’t subscribe you. Please try again.");
        } finally {
            setPending(false);
        }
    };

    return (
        <footer className="store-footer hidden-vault-footer">
            <div className="page-width footer-grid">
                <div>
                    <Link className="wordmark" href={route("index")}>THE<span>HIDDEN VAULT</span></Link>
                    <p>Objects worth a closer look.</p>
                </div>
                <nav aria-label="Footer navigation">
                    <Link href={route("products.index", "all")}>Collection</Link>
                    <Link href="/faqs">Information</Link>
                    <Link href="/faqs#contact">Contact</Link>
                    <Link href={route("profile.edit")}>Your account</Link>
                </nav>
                <form onSubmit={subscribe}>
                    <label htmlFor="subscriber-email">Stay in the loop</label>
                    <p>Updates from The Hidden Vault.</p>
                    <div className="subscribe-row">
                        <input id="subscriber-email" aria-invalid={invalid} aria-describedby={message ? "subscriber-status" : undefined} type="email" required autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Your email address" />
                        <button className="button" type="submit" disabled={pending}>{pending ? "Joining…" : "Subscribe ↗"}</button>
                    </div>
                    <p id="subscriber-status" role="status">{message}</p>
                </form>
            </div>
            <div className="page-width footer-bottom"><span>© {new Date().getFullYear()} The Hidden Vault</span><span>All prices in AUD</span></div>
        </footer>
    );
}
