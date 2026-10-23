import {useId} from 'react';

/** A labelled native field with linked hint and error text. */
export default function Field({
    id,
    name,
    label,
    hint,
    error,
    as: Component = 'input',
    className = '',
    ...props
}) {
    const generatedId = useId();
    const fieldId = id || name || generatedId;
    const hintId = hint ? `${fieldId}-hint` : undefined;
    const errorId = error ? `${fieldId}-error` : undefined;
    const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div className="grid gap-2">
            {label ? <label htmlFor={fieldId} className="text-sm font-medium text-[var(--hv-text-on-light)]">{label}</label> : null}
            <Component
                {...props}
                id={fieldId}
                name={name}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className={`min-h-11 w-full rounded-[var(--radius-hv-sm)] border border-[var(--hv-border-strong)] bg-[var(--hv-surface-light)] px-3 py-2 text-base text-[var(--hv-text-on-light)] disabled:cursor-not-allowed disabled:opacity-55 ${className}`}
            />
            {hint ? <p id={hintId} className="text-sm text-[var(--hv-text-muted)]">{hint}</p> : null}
            {error ? <p id={errorId} className="text-sm font-medium text-[var(--hv-text-on-light)]"><span aria-hidden="true">Error: </span>{error}</p> : null}
        </div>
    );
}
