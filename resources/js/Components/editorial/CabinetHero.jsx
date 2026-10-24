import { Link } from "@inertiajs/react";
import cabinetHero from "../../../assets/images/hidden-vault/cabinet-hero.webp";

export default function CabinetHero({ product }) {
    return (
        <section className="vault-hero" data-hv-surface="dark">
            <img className="vault-hero__art" src={cabinetHero} alt="" />
            <div className="vault-hero__copy">
                <p className="vault-kicker">The Hidden Vault / Est. for curious minds</p>
                <h1 aria-label="Objects worth a closer look.">A curated<br />collection <em>for the curious.</em></h1>
                <p>Rare objects. Timeless details.<br />Unexpected discoveries.</p>
                <Link data-hv-action="primary" href={route("products.index", "all")}>Explore the vault <span>→</span></Link>
            </div>
            <div className="vault-hero__trust" aria-label="Store promises"><span>◇ Rare & authentic</span><span>◎ Curated by experts</span><span>◌ Collector community</span><span>◎ Worldwide delivery</span></div>
        </section>
    );
}
