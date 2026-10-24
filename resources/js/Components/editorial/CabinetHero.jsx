import { Link } from "@inertiajs/react";
import cabinetHero from "../../../assets/images/hidden-vault/cabinet-hero.webp";
import ProductImage from "@/Components/product/ProductImage";
import useVaultParallax from "@/hooks/useVaultParallax";

export default function CabinetHero({ product }) {
    const cabinet = useVaultParallax();

    return (
        <section ref={cabinet} className="vault-hero" data-hv-surface="dark">
            <img className="vault-hero__art" src={cabinetHero} alt="" />
            <div className="vault-hero__reflection" aria-hidden="true" />
            <div className="vault-hero__frame" aria-hidden="true"><span /></div>
            <div className="vault-hero__star" aria-hidden="true">✦</div>
            <div className="vault-hero__copy">
                <p className="vault-kicker">The Hidden Vault / Est. for curious minds</p>
                <h1 aria-label="Objects worth a closer look.">A curated<br />collection <em>for the curious.</em></h1>
                <p>Rare objects. Timeless details.<br />Unexpected discoveries.</p>
                <Link data-hv-action="primary" aria-label="Explore the collection" href={route("products.index", "all")}>Explore the vault <span>→</span></Link>
            </div>
            {product && <Link className="hero-object" href={route("products.show", product.id)} aria-label={`View ${product.name}`}><span className="hero-object__index" aria-hidden="true">Object / 01</span><ProductImage image={product.images?.[0]} name={product.name} eager /></Link>}
            <div className="vault-hero__trust" aria-label="Store information"><span>◇ Collector’s index</span><span>◎ Item details shown</span><span>◌ Quote before payment</span><span>◎ Australia checkout</span></div>
        </section>
    );
}
