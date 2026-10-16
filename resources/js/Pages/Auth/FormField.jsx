import { useEffect, useRef } from "react";
export default function FormField({
  form,
  name,
  label,
  type = "text",
  autoComplete,
  required = true,
  prefix = "",
  ...props
}) {
  const id = `${prefix}${name}`;
  const error = form.errors[name];
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        name={name}
        type={type}
        autoComplete={autoComplete}
        required={required}
        value={form.data[name] ?? ""}
        onChange={(event) => form.setData(name, event.target.value)}
        disabled={form.processing}
        aria-invalid={Boolean(error)}
        aria-describedby={error ? `${id}-error` : undefined}
        {...props}
      />
      {error && (
        <p id={`${id}-error`} className="field-error">
          {error}
        </p>
      )}
    </div>
  );
}
export function FormErrors({ errors }) {
  const ref = useRef(null);
  const text = Object.values(errors).flat().join(" ");
  useEffect(() => {
    if (text) ref.current?.focus();
  }, [text]);
  return text ? (
    <p ref={ref} tabIndex="-1" role="alert" className="field-error">
      {text}
    </p>
  ) : null;
}
