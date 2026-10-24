import { Link } from "@inertiajs/react";

export default function VaultCategoryStrip({ categories }) {
    return (
        <section className="vault-categories" aria-labelledby="vault-categories-title">
            <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="vault-kicker">Featured collections</p>
                    <h2 id="vault-categories-title">Explore the cabinet</h2>
                </div>
                <p className="max-w-md text-sm text-[#3E3A32]">Category labels are development placeholders until public taxonomy and editorial assets are verified.</p>
            </div>
            <ul className="vault-category-grid">
                {categories.map((category, index) => (
                    <li key={category.key}>
                        <Link className={`vault-category vault-category--${category.key}`} href={category.href}>
                            <span className="vault-category__number">{String(index + 1).padStart(2, "0")}</span>
                            <span className="vault-category__star" aria-hidden="true">✦</span>
                            <span className="vault-category__copy"><strong>{category.label}</strong><small>{["Time well collected.", "Exceptional details.", "Nature’s artwork.", "Icons. Rarities. Nostalgia.", "For the unexpected."][index]}</small></span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
