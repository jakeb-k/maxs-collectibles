import { useEffect, useRef } from "react";

/** One-shot editorial reveal for nonessential, below-fold content. */
export default function useVaultReveal() {
    const ref = useRef(null);

    useEffect(() => {
        const element = ref.current;
        if (!element || !window.matchMedia("(min-width: 768px)").matches || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return undefined;
        element.classList.add("vault-reveal--armed");
        const observer = new IntersectionObserver(([entry]) => {
            if (!entry.isIntersecting) return;
            element.classList.add("vault-reveal--visible");
            observer.disconnect();
        }, { threshold: 0.12, rootMargin: "0px 0px -8% 0px" });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);

    return ref;
}
