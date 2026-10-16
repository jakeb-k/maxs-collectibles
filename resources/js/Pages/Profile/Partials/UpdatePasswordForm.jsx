import { useForm } from "@inertiajs/react";
import Field, { FormErrors } from "@/Pages/Auth/FormField";
export default function UpdatePasswordForm() {
  const form = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
  });
  return (
    <section className="surface">
      <h2>Change password</h2>
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.put("/password", {
            preserveScroll: true,
            onSuccess: () => form.reset(),
          });
        }}
      >
        <FormErrors errors={form.errors} />
        <Field
          prefix="change-"
          form={form}
          name="current_password"
          label="Current password"
          type="password"
          autoComplete="current-password"
        />
        <Field
          prefix="change-"
          form={form}
          name="password"
          label="New password"
          type="password"
          autoComplete="new-password"
        />
        <Field
          prefix="change-"
          form={form}
          name="password_confirmation"
          label="Confirm new password"
          type="password"
          autoComplete="new-password"
        />
        <button className="button button-light" disabled={form.processing}>
          Save new password
        </button>
        {form.recentlySuccessful && <p role="status">Password changed.</p>}
      </form>
    </section>
  );
}
