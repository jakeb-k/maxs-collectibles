import { useState } from "react";
import axios from "axios";
import Field from "@/Pages/Auth/FormField";
export default function ContactForm() {
  const [data, setData] = useState({
    first_name: "",
    last_name: "",
    email: "",
    message: "",
  });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const form = {
    data,
    setData: (name, value) =>
      setData((previous) => ({ ...previous, [name]: value })),
    errors,
    processing: busy,
  };
  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage("");
    try {
      await axios.post("/contact/email", data);
      setMessage("Your message was accepted and is queued for delivery.");
    } catch (error) {
      setErrors(error.response?.data?.errors || {});
      setMessage(
        error.response?.data?.code === "contact_unavailable"
          ? "Contact is unavailable right now. Please try again later."
          : "Your message could not be accepted. Check the fields and try again.",
      );
    } finally {
      setBusy(false);
    }
  };
  return (
    <form onSubmit={submit} className="space-y-6" aria-label="Contact Max">
      <div className="form-grid">
        <Field
          prefix="contact-"
          form={form}
          name="first_name"
          label="First name"
          autoComplete="given-name"
        />
        <Field
          prefix="contact-"
          form={form}
          name="last_name"
          label="Last name (optional)"
          required={false}
          autoComplete="family-name"
        />
      </div>
      <Field
        prefix="contact-"
        form={form}
        name="email"
        label="Email"
        type="email"
        autoComplete="email"
      />
      <label htmlFor="contact-message">Your message</label>
      <textarea
        id="contact-message"
        name="message"
        rows="6"
        className="w-full bg-[#101713] text-[#edeee9] border-[#67766c]"
        value={data.message}
        required
        onChange={(e) => form.setData("message", e.target.value)}
        aria-invalid={Boolean(errors.message)}
        aria-describedby={errors.message ? "contact-message-error" : undefined}
      />
      {errors.message && (
        <p id="contact-message-error" className="field-error">
          {errors.message[0]}
        </p>
      )}
      <p className="muted">
        For an order question, include its reference. Please keep passwords and
        payment card details out of messages.
      </p>
      <button className="button button-light" disabled={busy}>
        {busy ? "Submitting…" : "Send message"}
      </button>
      {message && <p role="status">{message}</p>}
    </form>
  );
}
