import { Link, useForm } from "@inertiajs/react";
import AccountPanel from "./AccountPanel";
import { FormErrors } from "./FormField";
export default function VerifyEmail({ status }) {
  const form = useForm({});
  return (
    <AccountPanel title="Verify your email">
      <p>Use the verification link in your email to confirm this address.</p>
      {status === "verification-link-sent" && (
        <p role="status" className="mt-6">
          Your verification message was accepted for delivery.
        </p>
      )}
      <FormErrors errors={form.errors} />
      <form
        className="mt-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.post("/email/verification-notification");
        }}
      >
        <button className="button button-light" disabled={form.processing}>
          Request verification email
        </button>
      </form>
      <Link href="/logout" method="post" as="button" className="text-link mt-6">
        Log out
      </Link>
    </AccountPanel>
  );
}
