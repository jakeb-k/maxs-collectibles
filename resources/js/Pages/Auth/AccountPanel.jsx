import { Head } from "@inertiajs/react";
export default function AccountPanel({ title, children }) {
  return (
    <div className="page-width py-12 md:py-20">
      <Head title={title} />
      <section className="surface max-w-xl mx-auto">
        <p className="eyebrow mb-4">Max’s Collectibles / Account</p>
        <h1 className="text-4xl mb-8">{title}</h1>
        {children}
      </section>
    </div>
  );
}
