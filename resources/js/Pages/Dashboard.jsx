import { Link, Head } from "@inertiajs/react";
export default function Dashboard() {
  return (
    <div className="page-width py-12">
      <Head title="Account" />
      <h1>Your account</h1>
      <Link className="button mt-6" href="/profile">
        View account and orders
      </Link>
    </div>
  );
}
