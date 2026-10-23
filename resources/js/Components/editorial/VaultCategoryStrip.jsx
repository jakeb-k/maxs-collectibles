import { Link } from "@inertiajs/react";

export default function VaultCategoryStrip({ categories }) {
    return (
        <section className="border-y border-[#3E3A32] bg-[#E8DDC4] px-4 py-12 md:px-8 lg:px-12" aria-labelledby="vault-categories-title">
            <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Provisional catalogue</p>
                    <h2 id="vault-categories-title" data-hv-type="display" className="mt-2 text-[var(--hv-display-sm)] leading-[1.29]">Explore the cabinet</h2>
                </div>
                <p className="max-w-md text-sm text-[#3E3A32]">Category labels are development placeholders until public taxonomy and editorial assets are verified.</p>
            </div>
            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                {categories.map((category, index) => (
                    <li key={category.key}>
                        <Link className="group flex min-h-40 flex-col justify-between border border-[#3E3A32] bg-[#F8F4E9] p-4 transition-[background-color,border-color,box-shadow] duration-200 hover:border-[#C9A661] hover:shadow-[var(--hv-soft-shadow)]" href={category.href}>
                            <span className="text-xs font-medium tracking-[0.12em] text-[#3E3A32]">{String(index + 1).padStart(2, "0")}</span>
                            <span data-hv-type="display" className="text-[var(--hv-heading-sm)]">{category.label}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
