import { Head } from "@inertiajs/react";
export default function AccountPanel({ title, children }) {
  return (
    <div className="hidden-vault min-h-full bg-[#F8F4E9] text-[#0D0C09]">
      <Head title={title} />
      <div className="page-width py-12 md:py-20"><section className="max-w-xl border border-[#3E3A32] bg-[#E8DDC4] p-5 md:mx-auto md:p-8">
          <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Max’s Collectibles / Account</p>
          <h1 data-hv-type="display" className="mb-8 mt-3 text-[var(--hv-display-sm)]">{title}</h1>
          {children}
      </section></div>
    </div>
  );
}
