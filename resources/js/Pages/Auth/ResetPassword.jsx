import { useForm } from "@inertiajs/react";
import AccountPanel from "./AccountPanel";
import Field, { FormErrors } from "./FormField";
export default function ResetPassword({ token, email }) {
  const form = useForm({
    token,
    email: email || "",
    password: "",
    password_confirmation: "",
  });
  return (
    <AccountPanel title="Choose a new password">
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.post("/reset-password", {
            onFinish: () => form.reset("password", "password_confirmation"),
          });
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
          label="New password"
          type="password"
          autoComplete="new-password"
        />
        <Field
          form={form}
          name="password_confirmation"
          label="Confirm new password"
          type="password"
          autoComplete="new-password"
        />
        <button className="button button-light" disabled={form.processing}>
          Reset password
        </button>
      </form>
    </AccountPanel>
  );
}
