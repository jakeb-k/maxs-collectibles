import { Link, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import Footer from "@/Components/Footer";
import ShoppingCart from "@/Components/ShoppingCart";
import MobileNavigationMenu from "@/Components/home/MobileNavigationMenu";
import NavigationMenu from "@/Components/home/NavigationMenu";
import SearchResults from "@/Components/home/SearchResults";
import useCartStore from "@/Stores/useCartStore";

export default function MainLayout({ children }) {
    const { auth } = usePage().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const [bagOpen, setBagOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [query, setQuery] = useState("");
    const bag = useRef(null);
    const bagTrigger = useRef(null);
    const menuTrigger = useRef(null);
    const searchTrigger = useRef(null);
    const count = useCartStore((state) => state.products.reduce(
        (sum, product) => sum + (Number.isInteger(product.quantity) ? product.quantity : 0),
        0,
    ));

    const closeMenu = () => setMenuOpen(false);
    const closeSearch = () => {
        setSearchOpen(false);
        setQuery("");
    };
    const openSearch = () => {
        setMenuOpen(false);
        setBagOpen(false);
        setSearchOpen(true);
    };
    const openBag = () => {
        setMenuOpen(false);
        setSearchOpen(false);
        setBagOpen(true);
    };

    useEffect(() => {
        if (bagOpen && !bag.current?.open) bag.current?.showModal();
        if (!bagOpen && bag.current?.open) bag.current.close();
    }, [bagOpen]);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key !== "Escape") return;
            if (searchOpen) {
                closeSearch();
                searchTrigger.current?.focus();
            } else if (menuOpen) {
                closeMenu();
                menuTrigger.current?.focus();
            }
        };
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, [menuOpen, searchOpen]);

    return (
        <div className="storefront hidden-vault">
            <a className="skip-link" href="#main-content">Skip to content</a>
            <header className="store-header hidden-vault-shell bg-[#0D0C09] text-[#F8F4E9] border-[#C9A661]">
                <Link className="wordmark" href={route("index")} aria-label="Max’s Collectibles home">
                    <span className="wordmark__the">THE</span> HIDDEN VAULT
                </Link>
                <button
                    ref={menuTrigger}
                    className="menu-toggle"
                    type="button"
                    onClick={() => {
                        setSearchOpen(false);
                        setMenuOpen((open) => !open);
                    }}
                    aria-expanded={menuOpen}
                    aria-controls="store-navigation"
                >
                    {menuOpen ? "Close menu" : "Menu"}
                </button>
                {menuOpen && <MobileNavigationMenu closeMenu={closeMenu} openSearch={openSearch} />}
                <nav className="hidden-vault-desktop-nav" aria-label="Desktop navigation">
                    <Link href={route("products.index", "all")}>Collection</Link>
                    <Link href={route("products.index", "all")}>Watches</Link>
                    <Link href={route("products.index", "all")}>Jewellery</Link>
                    <Link href={route("products.index", "all")}>Opals</Link>
                    <Link href={route("products.index", "all")}>Trading cards</Link>
                    <Link href={route("products.index", "all")}>Curiosities</Link>
                    <Link href={route("products.index", "favourites")}>Favourites</Link>
                    <NavigationMenu closeMenu={closeMenu} />
                </nav>
                <div className="header-actions">
                    <button ref={searchTrigger} type="button" onClick={openSearch} aria-expanded={searchOpen} aria-controls="store-search">Search</button>
                    <Link href={auth?.user ? route("profile.edit") : route("login")}>Account</Link>
                    <button ref={bagTrigger} type="button" onClick={openBag} aria-haspopup="dialog">
                        Bag <span aria-label={`${count} items`}>({count})</span>
                    </button>
                    {auth?.roles?.includes("admin") && <Link href={route("admin.dashboard")}>Admin</Link>}
                    {auth?.user && <Link href={route("logout")} method="post" as="button">Log out</Link>}
                </div>
                {searchOpen && (
                    <section id="store-search" className="hidden-vault-search absolute inset-x-0 top-full z-30 border border-[#C9A661] bg-[#0D0C09] p-4 text-[#F8F4E9]" aria-label="Search the collection">
                        <div className="hidden-vault-search__heading">
                            <label htmlFor="store-search-query">Search the collection</label>
                            <button type="button" onClick={closeSearch}>Close search</button>
                        </div>
                        <input id="store-search-query" type="search" autoFocus value={query} onChange={(event) => setQuery(event.target.value)} />
                        <SearchResults query={query} closeSearch={closeSearch} />
                    </section>
                )}
            </header>
            <main id="main-content" tabIndex="-1">{children}</main>
            <dialog
                ref={bag}
                onClose={() => {
                    setBagOpen(false);
                    bagTrigger.current?.focus();
                }}
                className="hidden-vault-bag fixed inset-0 m-auto max-h-[90dvh] w-[min(92vw,650px)] border border-[#C9A661] bg-[#0D0C09] p-5 text-[#F8F4E9] backdrop:bg-black/70"
                aria-label="Your shopping bag"
                onClick={(event) => {
                    if (event.target.closest("a")) setBagOpen(false);
                }}
            >
                <div className="flex justify-end mb-4"><button className="button" type="button" onClick={() => setBagOpen(false)}>Close bag</button></div>
                {bagOpen && <ShoppingCart />}
            </dialog>
            <Footer />
        </div>
    );
}
