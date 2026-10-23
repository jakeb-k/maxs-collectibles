import {forwardRef} from 'react';

const styles = {
    primary: 'border-[var(--hv-action-primary)] bg-[var(--hv-action-primary)] text-[var(--hv-action-foreground)]',
    secondary: 'border-[var(--hv-border-strong)] bg-transparent text-[var(--hv-text-on-light)]',
    quiet: 'border-transparent bg-transparent text-[var(--hv-text-on-light)] underline underline-offset-4',
};

/**
 * Presentation-only control. It deliberately delegates all mutations to the
 * caller and reserves its normal label width while a request is pending.
 */
const Button = forwardRef(function Button(
    {variant = 'primary', busy = false, disabled = false, children, className = '', type = 'button', ...props},
    ref,
) {
    const unavailable = disabled || busy;

    return (
        <button
            {...props}
            ref={ref}
            type={type}
            disabled={unavailable}
            aria-busy={busy || undefined}
            data-hv-action={variant === 'primary' ? 'primary' : undefined}
            className={`inline-flex min-h-11 items-center justify-center gap-2 rounded-[var(--radius-hv-sm)] border px-4 py-2 text-sm font-medium tracking-[0.06em] transition-[background-color,border-color,color,box-shadow,transform] duration-200 ease-[var(--hv-motion-gentle)] disabled:cursor-not-allowed disabled:opacity-55 ${styles[variant] || styles.primary} ${className}`}
        >
            <span>{children}</span>
            {busy ? <span className="sr-only">, loading</span> : null}
        </button>
    );
});

export default Button;
