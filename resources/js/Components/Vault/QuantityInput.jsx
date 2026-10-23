/** Controlled native quantity input; validation and stock authority stay with its caller. */
export default function QuantityInput({id, label = 'Quantity', min = 1, max, value, onChange, disabled = false, className = ''}) {
    return (
        <label htmlFor={id} className={`grid gap-2 text-sm font-medium text-[var(--hv-text-on-light)] ${className}`}>
            <span>{label}</span>
            <input
                id={id}
                type="number"
                inputMode="numeric"
                min={min}
                max={max}
                value={value}
                onChange={onChange}
                disabled={disabled}
                className="min-h-11 rounded-[var(--radius-hv-sm)] border border-[var(--hv-border-strong)] bg-[var(--hv-surface-light)] px-3 py-2 text-base text-[var(--hv-text-on-light)] disabled:cursor-not-allowed disabled:opacity-55"
            />
        </label>
    );
}
