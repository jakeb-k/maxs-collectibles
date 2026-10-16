import { Head } from "@inertiajs/react";
import UpdateProfileInformationForm from "./Partials/UpdateProfileInformationForm";
import UpdatePasswordForm from "./Partials/UpdatePasswordForm";
import DeleteUserForm from "./Partials/DeleteUserForm";
import Money from "@/Components/common/Money";
export default function Edit({
  auth,
  mustVerifyEmail,
  status,
  orders = [],
  location = [],
}) {
  return (
    <div className="page-width py-12">
      <Head title="Your account" />
      <p className="eyebrow">Max’s Collectibles / Account</p>
      <h1 className="text-4xl my-6">{auth.user.name}</h1>
      <div className="grid lg:grid-cols-2 gap-6 items-start">
        <div className="space-y-6">
          <UpdateProfileInformationForm
            location={location}
            mustVerifyEmail={mustVerifyEmail}
            status={status}
          />
          <UpdatePasswordForm />
          <DeleteUserForm />
        </div>
        <section className="surface">
          <h2>Your orders</h2>
          {orders.length ? (
            orders.map((order) => (
              <a
                key={order.id}
                href={`/orders/${order.id}`}
                className="block border-b border-[#46534c] py-5"
              >
                <span className="block">{order.code}</span>
                <span className="block my-2">{order.status}</span>
                <Money minor={order.total_minor} currency={order.currency} />
              </a>
            ))
          ) : (
            <p>No orders are linked to this account.</p>
          )}
          <p className="muted">
            Guest orders stay separate from account history. Use their original
            order access link.
          </p>
        </section>
      </div>
    </div>
  );
}
