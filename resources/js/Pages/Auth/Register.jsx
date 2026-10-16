import { Link, useForm } from "@inertiajs/react";
import AccountPanel from "./AccountPanel";
import Field, { FormErrors } from "./FormField";
export default function Register() {
  const form = useForm({
    first_name: "",
    last_name: "",
    email: "",
    mobile: "",
    password: "",
    password_confirmation: "",
  });
  return (
    <AccountPanel title="Create an account">
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.post("/register", {
            onFinish: () => form.reset("password", "password_confirmation"),
          });
        }}
      >
        <FormErrors errors={form.errors} />
        <Field
          form={form}
          name="first_name"
          label="First name"
          autoComplete="given-name"
        />
        <Field
          form={form}
          name="last_name"
          label="Last name"
          autoComplete="family-name"
        />
        <Field
          form={form}
          name="email"
          label="Email"
          type="email"
          autoComplete="email"
        />
        <Field
          form={form}
          name="mobile"
          label="Australian mobile (optional)"
          type="tel"
          autoComplete="tel"
          required={false}
        />
        <Field
          form={form}
          name="password"
          label="Password"
          type="password"
          autoComplete="new-password"
        />
        <Field
          form={form}
          name="password_confirmation"
          label="Confirm password"
          type="password"
          autoComplete="new-password"
        />
        <button
          className="button button-light w-full"
          disabled={form.processing}
        >
          {form.processing ? "Creating account…" : "Create account"}
        </button>
      </form>
      <Link className="text-link mt-6" href="/login">
        Already have an account? Log in
      </Link>
    </AccountPanel>
  );
}
