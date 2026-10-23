import { forwardRef, useEffect, useRef } from 'react';

export default forwardRef(function TextInput({ type = 'text', className = '', isFocused = false, ...props }, ref) {
    const input = ref ? ref : useRef();

    useEffect(() => {
        if (isFocused) {
            input.current.focus();
        }
    }, [input, isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={
                'min-h-11 rounded-lg py-1 px-4 bg-transparent hover:bg-gray-200/50 focus:ring-2 focus:ring-[#FFD100] focus:outline-none transition-[background-color,border-color,color,box-shadow] duration-200 ease-in-out ' +
                className
            }
            ref={input}
        />
    );
});

