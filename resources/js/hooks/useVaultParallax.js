import { useEffect, useRef } from "react";

/** Decorative, fine-pointer-only depth. It never moves commerce content. */
export default function useVaultParallax() {
    const ref = useRef(null);

    useEffect(() => {
        const element = ref.current;
        const canAnimate = window.matchMedia("(hover: hover) and (pointer: fine)");
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
        let frame = null;

        const reset = () => {
            element?.style.removeProperty("--hv-pointer-x");
            element?.style.removeProperty("--hv-pointer-y");
        };
        const update = (event) => {
            if (!canAnimate.matches || reduceMotion.matches || !element) return;
            const bounds = element.getBoundingClientRect();
            const x = Math.max(-1, Math.min(1, ((event.clientX - bounds.left) / bounds.width - 0.5) * 2));
            const y = Math.max(-1, Math.min(1, ((event.clientY - bounds.top) / bounds.height - 0.5) * 2));
            if (frame) window.cancelAnimationFrame(frame);
            frame = window.requestAnimationFrame(() => {
                element.style.setProperty("--hv-pointer-x", x.toFixed(3));
                element.style.setProperty("--hv-pointer-y", y.toFixed(3));
            });
        };

        element?.addEventListener("pointermove", update);
        element?.addEventListener("pointerleave", reset);
        reduceMotion.addEventListener("change", reset);
        return () => {
            if (frame) window.cancelAnimationFrame(frame);
            element?.removeEventListener("pointermove", update);
            element?.removeEventListener("pointerleave", reset);
            reduceMotion.removeEventListener("change", reset);
            reset();
        };
    }, []);

    return ref;
}
