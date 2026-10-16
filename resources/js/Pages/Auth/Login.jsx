import { Link, useForm } from "@inertiajs/react";
import AccountPanel from "./AccountPanel";
import Field, { FormErrors } from "./FormField";
export default function Login({ status, canResetPassword }) {
  const form = useForm({ email: "", password: "", remember: false });
  return (
    <AccountPanel title="Log in">
      {status && (
        <p role="status" className="mb-6">
          {status}
        </p>
      )}
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.post("/login", { onFinish: () => form.reset("password") });
        }}
      >
        <FormErrors errors={form.errors} />
        <Field
          form={form}
          name="email"
          label="Email"
          type="email"
          autoComplete="username"
        />
        <Field
          form={form}
          name="password"
          label="Password"
          type="password"
          autoComplete="current-password"
        />
        <label className="flex-row items-center">
          <input
            className="w-auto min-h-0 text-[#46534c]"
            type="checkbox"
            checked={form.data.remember}
            onChange={(e) => form.setData("remember", e.target.checked)}
          />
          Remember me
        </label>
        <button
          className="button button-light w-full"
          disabled={form.processing}
        >
          {form.processing ? "Logging in…" : "Log in"}
        </button>
      </form>
      <div className="flex flex-col gap-3 mt-6">
        {canResetPassword && (
          <Link className="text-link" href="/forgot-password">
            Forgot your password?
          </Link>
        )}
        <Link className="text-link" href="/register">
          Create an account
        </Link>
      </div>
    </AccountPanel>
  );
}
