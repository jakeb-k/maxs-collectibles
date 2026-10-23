export default function SecondaryButton({ type = 'button', className = '', disabled, busy = false, children, ...props }) {
    const unavailable = disabled || busy;

    return (
        <button
            {...props}
            type={type}
            aria-busy={busy || undefined}
            className={
                `inline-flex min-h-11 items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition-[background-color,border-color,color,box-shadow] duration-200 ease-in-out ${
                    unavailable && 'opacity-25'
                } ` + className
            }
            disabled={unavailable}
        >
            {children}
            {busy ? <span className="sr-only">, loading</span> : null}
        </button>
    );
}
