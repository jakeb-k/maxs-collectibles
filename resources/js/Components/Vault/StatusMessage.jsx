import {useEffect, useRef} from 'react';

export default function StatusMessage({severity = 'status', focusOnError = false, children, className = ''}) {
    const message = useRef(null);
    const isError = severity === 'error';

    useEffect(() => {
        if (isError && focusOnError) message.current?.focus();
    }, [focusOnError, isError]);

    if (!children) return null;

    return (
        <div
            ref={message}
            tabIndex={isError && focusOnError ? -1 : undefined}
            role={isError ? 'alert' : 'status'}
            className={`rounded-[var(--radius-hv-sm)] border px-3 py-2 text-sm ${isError ? 'border-[var(--hv-status-error-accent)] text-[var(--hv-text-on-light)]' : 'border-[var(--hv-status-success)] text-[var(--hv-text-on-light)]'} ${className}`}
        >
            {children}
        </div>
    );
}
