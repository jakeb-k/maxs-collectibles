import { Link } from "@inertiajs/react";
import vaultNavigation from "@/content/vault-navigation";

export default function NavigationMenu({ closeMenu }) {
    return (
        <nav aria-label="Collection categories" className="hidden-vault-menu">
            <p className="hidden-vault-menu__label">Explore the cabinet</p>
            <ul>
                {vaultNavigation.categories.map((category) => (
                    <li key={category.key}>
                        <Link href={category.href} onClick={closeMenu}>
                            {category.label}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
