import { Link, useForm } from "@inertiajs/react";
import AccountPanel from "./AccountPanel";
import Field, { FormErrors } from "./FormField";
export default function ForgotPassword({ status }) {
  const form = useForm({ email: "" });
  return (
    <AccountPanel title="Reset your password">
      <p className="mb-6">Request a link to choose a new password.</p>
      {status && (
        <p role="status" className="mb-6">
          Your password reset request was accepted for delivery.
        </p>
      )}
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.post("/forgot-password");
        }}
      >
        <FormErrors errors={form.errors} />
        <Field
          form={form}
          name="email"
          label="Email"
          type="email"
          autoComplete="email"
        />
        <button className="button button-light" disabled={form.processing}>
          {form.processing ? "Requesting…" : "Request reset link"}
        </button>
      </form>
      <Link className="text-link mt-6" href="/login">
        Return to log in
      </Link>
    </AccountPanel>
  );
}
