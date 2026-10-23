export default function InputError({ message, className = '', ...props }) {
    return message ? (
        <p {...props} role="alert" className={'text-sm text-red-600 ' + className}>
            {message}
        </p>
    ) : null;
}
