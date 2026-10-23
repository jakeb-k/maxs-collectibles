import axios from "axios";
import { Link } from "@inertiajs/react";
import { useEffect, useState } from "react";

export default function SearchResults({ query, closeSearch }) {
    const [state, setState] = useState({ pending: false, products: [], error: null });

    useEffect(() => {
        const controller = new AbortController();
        const term = query.trim();

        if (!term) {
            setState({ pending: false, products: [], error: null });
            return () => controller.abort();
        }

        setState({ pending: true, products: [], error: null });
        axios.get(route("products.search", term), { signal: controller.signal })
            .then(({ data }) => setState({ pending: false, products: data.products || [], error: null }))
            .catch((error) => {
                if (error.code !== "ERR_CANCELED") {
                    setState({ pending: false, products: [], error: "Search is unavailable. Please try again." });
                }
            });

        return () => controller.abort();
    }, [query]);

    if (!query.trim()) return <p className="hidden-vault-search__hint">Search by object, category or tag.</p>;
    if (state.pending) return <p role="status">Searching the collection…</p>;
    if (state.error) return <p role="alert">{state.error}</p>;
    if (!state.products.length) return <p role="status">No matching objects found.</p>;

    return (
        <ul className="hidden-vault-search__results">
            {state.products.map((product) => (
                <li key={product.id}>
                    <Link href={route("products.show", product.id)} onClick={closeSearch}>
                        {product.name}
                    </Link>
                </li>
            ))}
        </ul>
    );
}
