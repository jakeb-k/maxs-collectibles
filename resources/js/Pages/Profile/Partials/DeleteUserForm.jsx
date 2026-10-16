import { useState } from "react";
import { useForm } from "@inertiajs/react";
import Field, { FormErrors } from "@/Pages/Auth/FormField";
export default function DeleteUserForm() {
  const [open, setOpen] = useState(false);
  const form = useForm({ password: "" });
  return (
    <section className="surface">
      <h2>Delete account</h2>
      <p>
        Deleting your account removes your sign-in access. Retained order
        records keep their purchase facts.
      </p>
      {!open ? (
        <button className="button mt-6" onClick={() => setOpen(true)}>
          Delete my account
        </button>
      ) : (
        <form
          className="space-y-6 mt-6"
          onSubmit={(e) => {
            e.preventDefault();
            form.delete("/profile", { onFinish: () => form.reset() });
          }}
        >
          <p>Confirm this action with your current password.</p>
          <FormErrors errors={form.errors} />
          <Field
            prefix="delete-"
            form={form}
            name="password"
            label="Password to delete account"
            type="password"
            autoComplete="current-password"
          />
          <div className="flex gap-4 flex-wrap">
            <button className="button" disabled={form.processing}>
              Permanently delete account
            </button>
            <button
              type="button"
              className="text-link"
              onClick={() => {
                setOpen(false);
                form.reset();
                form.clearErrors();
              }}
            >
              Cancel deletion
            </button>
          </div>
        </form>
      )}
    </section>
  );
}
