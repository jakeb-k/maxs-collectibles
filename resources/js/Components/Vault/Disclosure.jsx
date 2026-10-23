/** Native disclosure keeps keyboard semantics and works when JavaScript is unavailable. */
export default function Disclosure({title, children, defaultOpen = false, className = ''}) {
    if (!children) return null;

    return (
        <details open={defaultOpen} className={`border-b border-[var(--hv-border-default)] ${className}`}>
            <summary className="min-h-11 cursor-pointer py-3 font-medium text-[var(--hv-text-on-light)] marker:text-[var(--hv-text-on-light)]">{title}</summary>
            <div className="pb-4 text-[var(--hv-text-muted)]">{children}</div>
        </details>
    );
}
