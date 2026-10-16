import { useForm } from "@inertiajs/react";
import AccountPanel from "./AccountPanel";
import Field, { FormErrors } from "./FormField";
export default function ConfirmPassword() {
  const form = useForm({ password: "" });
  return (
    <AccountPanel title="Confirm your password">
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.post("/confirm-password", { onFinish: () => form.reset() });
        }}
      >
        <FormErrors errors={form.errors} />
        <Field
          form={form}
          name="password"
          label="Password"
          type="password"
          autoComplete="current-password"
        />
        <button className="button button-light" disabled={form.processing}>
          Confirm password
        </button>
      </form>
    </AccountPanel>
  );
}
