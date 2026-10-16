import { Link, usePage } from "@inertiajs/react";
import { useState, useRef, useEffect } from "react";
import ShoppingCart from "@/Components/ShoppingCart";
import Footer from "@/Components/Footer";
import useCartStore from "@/Stores/useCartStore";
export default function MainLayout({ children }) {
  const { auth } = usePage().props;
  const [open, setOpen] = useState(false);
  const [bagOpen, setBagOpen] = useState(false);
  const bag = useRef(null);
  useEffect(() => {
    if (bagOpen) bag.current?.showModal();
    else bag.current?.close();
  }, [bagOpen]);
  const count = useCartStore((s) =>
    s.products.reduce(
      (sum, p) => sum + (Number.isInteger(p.quantity) ? p.quantity : 0),
      0,
    ),
  );
  return (
    <div className="storefront">
      <a className="skip-link" href="#main-content">
        Skip to content
      </a>
      <header className="store-header">
        <Link
          className="wordmark"
          href="/"
          aria-label="Max’s Collectibles home"
        >
          MAX’S<span>COLLECTIBLES</span>
        </Link>
        <button
          className="menu-toggle"
          onClick={() => setOpen(!open)}
          aria-expanded={open}
          aria-controls="store-navigation"
        >
          {open ? "Close menu" : "Menu"}
        </button>
        <nav
          id="store-navigation"
          aria-label="Main navigation"
          className={open ? "is-open" : ""}
        >
          <Link onClick={() => setOpen(false)} href="/products/all">
            Collection
          </Link>
          <Link onClick={() => setOpen(false)} href="/products/sale">
            On sale
          </Link>
          <Link onClick={() => setOpen(false)} href="/products/favourites">
            Favourites
          </Link>
          <Link onClick={() => setOpen(false)} href="/faqs">
            Information
          </Link>
        </nav>
        <div className="header-actions">
          <Link href={auth?.user ? "/profile" : "/login"}>Account</Link>
          <button onClick={() => setBagOpen(true)} aria-haspopup="dialog">
            Bag <span aria-label={`${count} items`}>({count})</span>
          </button>
          {auth?.roles?.includes("admin") && <Link href="/admin">Admin</Link>}
          {auth?.user && (
            <Link href="/logout" method="post" as="button">
              Log out
            </Link>
          )}
        </div>
      </header>
      <main id="main-content" tabIndex="-1">
        {children}
      </main>
      <dialog
        ref={bag}
        onClose={() => setBagOpen(false)}
        className="fixed inset-0 m-auto max-h-[90dvh] w-[min(92vw,650px)] bg-[#101513] text-[#edeee9] border border-[#46534c] p-5 backdrop:bg-black/70"
        aria-label="Your shopping bag"
        onClick={(event) => {
          if (event.target.closest("a")) setBagOpen(false);
        }}
      >
        <div className="flex justify-end mb-4">
          <button className="button" onClick={() => setBagOpen(false)}>
            Close bag
          </button>
        </div>
        {bagOpen && <ShoppingCart />}
      </dialog>
      <Footer />
    </div>
  );
}
