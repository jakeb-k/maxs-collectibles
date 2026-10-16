import { useForm, usePage, Link } from "@inertiajs/react";
import Field, { FormErrors } from "@/Pages/Auth/FormField";
export default function UpdateProfileInformationForm({
  location = [],
  mustVerifyEmail,
  status,
}) {
  const user = usePage().props.auth.user;
  const address = location[0] || {};
  const form = useForm({
    first_name: user.first_name,
    last_name: user.last_name,
    email: user.email,
    mobile: user.mobile || "",
    street: address.street || "",
    city: address.city || "",
    state: address.state || "",
    postcode: address.postcode || "",
  });
  return (
    <section className="surface">
      <h2>Profile information</h2>
      <form
        className="space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.patch("/profile", { preserveScroll: true });
        }}
      >
        <FormErrors errors={form.errors} />
        <div className="form-grid">
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
        </div>
        <h3>Saved address (optional)</h3>
        <p className="muted">
          Fill all address fields to save an Australian address. This does not
          change past orders.
        </p>
        <div className="form-grid">
          {[
            ["street", "Street address", "street-address"],
            ["city", "Suburb / city", "address-level2"],
            ["state", "State / territory", "address-level1"],
            ["postcode", "Postcode", "postal-code"],
          ].map(([name, label, autoComplete]) => (
            <Field
              key={name}
              form={form}
              name={name}
              label={label}
              autoComplete={autoComplete}
              required={false}
            />
          ))}
        </div>
        <button className="button button-light" disabled={form.processing}>
          Save profile
        </button>
        {form.recentlySuccessful && <p role="status">Profile saved.</p>}
      </form>
      {mustVerifyEmail && !user.email_verified_at && (
        <p className="mt-6">
          Your email is unverified.{" "}
          <Link className="text-link" href="/verify-email">
            Verify your email
          </Link>
        </p>
      )}
      {status && <p role="status">Your request was accepted for delivery.</p>}
    </section>
  );
}
