export default function PrimaryButton({ className = '', disabled, busy = false, children, ...props }) {
    const unavailable = disabled || busy;

    return (
        <button
            {...props}
            type={props.type || 'button'}
            data-hv-action="primary"
            aria-busy={busy || undefined}
            className={
                `hover:bg-secondary hover:text-main hover:border-2 hover:border-main transition-[background-color,border-color,color,box-shadow,transform] duration-200 ease-in-out text-lg font-oswald px-4 py-1 border-2 border-black bg-main rounded-lg text-nowrap min-h-11 w-32 flex justify-center items-center space-x-1 ${
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
