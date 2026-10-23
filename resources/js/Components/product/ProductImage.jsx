import { useState } from "react";
export default function ProductImage({
  image,
  name,
  eager = false,
  className = "",
}) {
  const [failed, setFailed] = useState(null);
  const src = image?.url;
  if (!src || failed === src)
    return (
      <div
        className={`photo-fallback ${className}`}
        role="img"
        aria-label={`No photograph available for ${name}`}
      >
        <span className="object-mark" aria-hidden="true">
          ◇
        </span>
        <span>Photograph unavailable</span>
      </div>
    );
  return (
    <img
      className={`item-photo aspect-square w-full object-contain ${className}`}
      src={src}
      srcSet={
        image.derivatives
          ?.filter((d) => d.url && d.width)
          .map((d) => `${d.url} ${d.width}w`)
          .join(", ") || undefined
      }
      sizes="(max-width: 700px) 100vw, 50vw"
      width={image.width || 960}
      height={image.height || 960}
      alt={image.alt_text || name}
      loading={eager ? "eager" : "lazy"}
      decoding="async"
      onError={() => setFailed(src)}
    />
  );
}
