import { Head, Link } from "@inertiajs/react";
import { useState } from "react";
import Disclosure from "@/Components/Vault/Disclosure";
import QuantityInput from "@/Components/Vault/QuantityInput";
import StatusMessage from "@/Components/Vault/StatusMessage";
import ProductCard from "@/Components/product/ProductCard";
import ProductImage from "@/Components/product/ProductImage";
import Money from "@/Components/common/Money";
import useCartStore from "@/Stores/useCartStore";
import useFavouritesStore from "@/Stores/useFavouritesStore";

export default function ProductShowLayout({ product, relatedItems = [] }) {
    const [imageIndex, setImageIndex] = useState(0);
    const [selection, setSelection] = useState({});
    const [quantity, setQuantity] = useState(1);
    const [message, setMessage] = useState("");
    const addProduct = useCartStore((state) => state.addProduct);
    const { favourites, addFavourite, removeFavourite } = useFavouritesStore();
    const images = product.images || [];
    const options = product.options || [];
    const saved = favourites.includes(product.id);
    const selectedImage = images[imageIndex];
    const category = product.tags?.filter((tag) => tag.type === "category").map((tag) => tag.name).join(" / ") || "The collection";
    const canAdd = product.purchasable && Number.isInteger(product.display_unit_minor) && options.every((option) => option.values.includes(selection[option.type]));
    const chooseImage = (next) => setImageIndex((next + images.length) % images.length);

    const add = () => {
        if (!canAdd) return;
        addProduct({ id: product.id, product_id: product.id, name: product.name, selection, quantity, display_unit_minor: product.display_unit_minor, currency: product.currency, images: product.images });
        setMessage("Added to your bag.");
    };

    return <div className="hidden-vault vault-detail bg-[#F8F4E9] text-[#0D0C09]">
        <Head title={product.name} />
        <div className="page-width py-6 md:py-8 vault-detail__inner">
            <nav className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]" aria-label="Breadcrumb">
                <Link href={route("index")}>Home</Link><span aria-hidden="true"> / </span><Link href={route("products.index", "all")}>Collection</Link><span aria-hidden="true"> / </span><span>{product.name}</span>
            </nav>
            <div className="vault-detail__layout mt-6 grid gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(20rem,0.85fr)] lg:gap-12">
                <section aria-label="Item photographs" className="gallery min-w-0">
                    <div className="main-photo vault-detail__photo relative aspect-square overflow-hidden border border-[#3E3A32] bg-[#E8DDC4]" onKeyDown={(event) => { if (images.length < 2 || !["ArrowLeft", "ArrowRight"].includes(event.key)) return; event.preventDefault(); chooseImage(imageIndex + (event.key === "ArrowRight" ? 1 : -1)); }} tabIndex={images.length > 1 ? 0 : undefined}>
                        <ProductImage image={selectedImage} name={product.name} eager className="h-full" />
                    </div>
                    {selectedImage?.caption && <p className="mt-3 text-sm text-[#3E3A32]">{selectedImage.caption}</p>}
                    {images.length > 1 && <div className="gallery-controls mt-4 flex items-center justify-between gap-3"><button type="button" className="min-h-11 border border-[#3E3A32] px-4" onClick={() => chooseImage(imageIndex - 1)} aria-label="Previous photograph">Previous</button><p aria-live="polite" className="text-sm">{imageIndex + 1} / {images.length}</p><button type="button" className="min-h-11 border border-[#3E3A32] px-4" onClick={() => chooseImage(imageIndex + 1)} aria-label="Next photograph">Next</button></div>}
                    {images.length > 1 && <div className="mt-4 grid grid-cols-4 gap-2 sm:grid-cols-5" aria-label="Choose an item photograph">{images.map((image, index) => <button key={image.id} type="button" className={`aspect-square overflow-hidden border ${index === imageIndex ? "border-2 border-[#C9A661]" : "border-[#3E3A32]"}`} onClick={() => setImageIndex(index)} aria-label={`View photograph ${index + 1}`} aria-pressed={index === imageIndex}><ProductImage image={image} name={`${product.name}, view ${index + 1}`} className="h-full" /></button>)}</div>}
                </section>
                <section className="item-details min-w-0 lg:pt-3">
                    <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">{category}</p>
                    <h1 data-hv-type="display" className="mt-3 text-[var(--hv-display-sm)] leading-[1.29] md:text-[var(--hv-display-md)] md:leading-[1.2]">{product.name}</h1>
                    <div className="mt-5 border-y border-[#3E3A32] py-5 text-xl font-medium"><Money minor={product.display_unit_minor} currency={product.currency} /></div>
                    <p className="mt-4 text-sm text-[#3E3A32]">{product.purchasable ? `${product.available_quantity} available` : "Currently unavailable"}</p>
                    <div className="mt-6 border border-[#3E3A32] bg-[#E8DDC4] p-4"><div className="grid gap-4">
                        {options.map((option) => <label key={option.type} className="grid gap-2 text-sm font-medium">{option.type}<select value={selection[option.type] || ""} onChange={(event) => { setSelection((current) => ({ ...current, [option.type]: event.target.value })); setMessage(""); }} disabled={!product.purchasable}><option value="">Choose {option.type}</option>{option.values.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>)}
                        <QuantityInput id={`product-${product.id}-quantity`} max={product.available_quantity || 1} value={quantity} disabled={!product.purchasable} onChange={(event) => { const next = Number(event.target.value); setQuantity(Number.isInteger(next) ? Math.max(1, Math.min(product.available_quantity || 1, next)) : 1); }} />
                        <button data-hv-action="primary" type="button" className="min-h-11 px-5 py-3" onClick={add} disabled={!canAdd}>{product.purchasable ? "Add to bag ↗" : "Unavailable"}</button>
                        <button type="button" className="min-h-11 text-left underline underline-offset-4" aria-pressed={saved} onClick={() => saved ? removeFavourite(product.id) : addFavourite(product.id)}>{saved ? "Remove from favourites" : "Save to favourites"}</button>
                        <StatusMessage>{message}</StatusMessage>
                    </div></div>
                    <div className="mt-6 divide-y divide-[#E8DDC4] border-t border-[#E8DDC4]"><Disclosure title="Item details" defaultOpen><p className="whitespace-pre-line">{product.description || "No additional description is available."}</p></Disclosure>{product.facts?.length > 0 && <section className="border-b border-[var(--hv-border-default)] py-3"><h2 className="font-medium text-[var(--hv-text-on-light)]">Facts</h2><dl className="mt-3 grid gap-3 pb-1 text-[var(--hv-text-muted)]">{product.facts.map((fact, index) => <div key={`${fact.label}-${index}`} className="grid gap-1 sm:grid-cols-[minmax(8rem,0.35fr)_1fr]"><dt className="font-medium">{fact.label}</dt><dd>{fact.value}</dd></div>)}</dl></section>}</div>
                </section>
            </div>
            {relatedItems.length > 0 && <section className="mt-12 border-t border-[#3E3A32] pt-8"><p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">More to explore</p><h2 data-hv-type="display" className="mt-2 text-[var(--hv-display-sm)]">Continue exploring</h2><div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{relatedItems.map((item) => <ProductCard key={item.id} product={item} />)}</div></section>}
        </div>
    </div>;
}
