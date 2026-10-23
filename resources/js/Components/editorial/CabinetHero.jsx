import { Link } from "@inertiajs/react";
import ProductImage from "@/Components/product/ProductImage";

export default function CabinetHero({ product }) {
    return (
        <section
            className="grid gap-8 border-b border-[#C9A661] bg-[#0D0C09] px-4 py-12 text-[#F8F4E9] md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] md:px-8 md:py-16 lg:px-12"
            data-hv-surface="dark"
        >
            <div className="flex max-w-2xl flex-col justify-center gap-6">
                <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#E8DDC4]">
                    Max’s Collectibles / The Hidden Vault
                </p>
                <h1 data-hv-type="display" className="text-[var(--hv-display-sm)] leading-[1.29] md:text-[var(--hv-display-md)] md:leading-[1.2] lg:text-[var(--hv-display-lg)] lg:leading-[1.125]">
                    Objects worth a <em className="font-normal">closer look.</em>
                </h1>
                <p data-hv-type="body-lg" className="max-w-xl text-[#E8DDC4]">
                    A considered collection of jewellery, watches, cards and objects.
                </p>
                <div>
                    <Link data-hv-action="primary" className="inline-flex min-h-11 items-center px-5 py-3 font-medium" href={route("products.index", "all")}>
                        Explore the collection
                    </Link>
                </div>
                <p className="text-sm text-[#E8DDC4]">
                    Product photography and details remain the source of truth.
                </p>
            </div>
            <div className="relative min-h-80 overflow-hidden border border-[#C9A661] bg-[#3E3A32] p-4 before:pointer-events-none before:absolute before:inset-0 before:bg-[image:var(--hv-amber-glow)] before:content-['']">
                {product ? (
                    <Link className="relative block h-full" href={route("products.show", product.id)}>
                        <ProductImage image={product.images?.[0]} name={product.name} eager />
                        <span className="absolute bottom-0 left-0 right-0 border-t border-[#C9A661] bg-[#0D0C09]/90 p-4 text-sm text-[#F8F4E9]">
                            <span className="block text-xs uppercase tracking-[0.12em] text-[#E8DDC4]">In the collection</span>
                            {product.name}
                        </span>
                    </Link>
                ) : (
                    <div className="relative flex h-full min-h-72 items-end border border-[#E8DDC4]/50 p-4">
                        <p className="max-w-sm text-sm text-[#E8DDC4]">
                            Featured object placeholder — approved editorial art and current catalogue imagery will appear here when available.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}
