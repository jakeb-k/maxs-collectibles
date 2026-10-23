import { Link } from "@inertiajs/react";
import vaultNavigation from "@/content/vault-navigation";

export default function MobileNavigationMenu({ closeMenu, openSearch }) {
    return (
        <div className="hidden-vault-mobile-menu" id="store-navigation" aria-label="Main navigation">
            <div className="hidden-vault-mobile-menu__actions">
                <button type="button" onClick={openSearch}>Search the collection</button>
                <Link href={route("products.index", "all")} onClick={closeMenu}>All objects</Link>
                <Link href={route("products.index", "favourites")} onClick={closeMenu}>Favourites</Link>
                <Link href="/faqs" onClick={closeMenu}>Information</Link>
            </div>
            <nav aria-label="Placeholder collection categories">
                <p>Collection</p>
                <ul>
                    {vaultNavigation.categories.map((category) => (
                        <li key={category.key}>
                            <Link href={category.href} onClick={closeMenu}>{category.label}</Link>
                        </li>
                    ))}
                </ul>
            </nav>
        </div>
    );
}
