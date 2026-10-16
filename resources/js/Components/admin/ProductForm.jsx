import { useEffect, useRef, useState } from "react";
import axios from "axios";
import Field from "@/Pages/Auth/FormField";
const text = (value) => (typeof value === "object" ? value?.en || "" : value);
export default function ProductForm({ product, onSaved, onCancel }) {
  const [data, setValues] = useState({
    name: product?.name || "",
    description: product?.description || "",
    price_minor: product?.price_minor ?? 0,
    discount_bps: product?.discount_bps ?? 0,
    currency: "AUD",
    stock_quantity: product?.stock_quantity ?? 0,
    available: product?.available ?? true,
    category_ids: product?.category_ids || [],
    type_tag_ids: product?.type_tag_ids || [],
    facts: product?.facts || [],
    options: product?.options || [],
    sku: product?.sku || "",
    url: product?.url || "",
    delivery_date: product?.delivery_date ?? "",
  });
  const [photos, setPhotos] = useState(
    (product?.images || []).map((image) => ({
      ...image,
      kind: "existing",
      alt_text: image.alt_text || "",
      caption: image.caption || "",
    })),
  );
  const [noImages, setNoImages] = useState(
    product ? !product.images.length : false,
  );
  const [tags, setTags] = useState(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState("");
  const summary = useRef(null);
  const urls = useRef([]);
  const setData = (name, value) =>
    setValues((previous) => ({ ...previous, [name]: value }));
  const loadTags = () =>
    axios
      .get("/products/tags")
      .then(({ data }) => setTags(data))
      .catch(() =>
        setMessage("Categories could not be loaded. Retry before saving."),
      );
  useEffect(() => {
    loadTags();
    return () => urls.current.forEach((url) => URL.revokeObjectURL(url));
  }, []);
  const form = { data, setData, processing: busy, errors };
  const update = (type, index, name, value) =>
    setData(
      type,
      data[type].map((row, i) =>
        i === index ? { ...row, [name]: value } : row,
      ),
    );
  const reorder = (list, index, delta) => {
    const next = [...list];
    [next[index], next[index + delta]] = [next[index + delta], next[index]];
    return next;
  };
  const photoUpdate = (index, name, value) =>
    setPhotos((previous) =>
      previous.map((photo, i) =>
        i === index ? { ...photo, [name]: value } : photo,
      ),
    );
  const save = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setErrors({});
    setMessage("");
    const added = photos.filter((photo) => photo.kind === "new");
    const kept = photos.filter((photo) => photo.kind === "existing");
    const payload = {
      ...data,
      price_minor: Number(data.price_minor),
      discount_bps: Number(data.discount_bps),
      stock_quantity: Number(data.stock_quantity),
      delivery_date:
        data.delivery_date === "" ? null : Number(data.delivery_date),
      no_images: noImages,
      kept_images: kept.map(({ id, alt_text, caption }) => ({
        id,
        alt_text,
        caption,
      })),
      image_metadata: added.map(({ alt_text, caption }) => ({
        alt_text,
        caption,
      })),
      image_order: photos.map((photo) =>
        photo.kind === "existing"
          ? `existing:${photo.id}`
          : `new:${added.indexOf(photo)}`,
      ),
    };
    const body = new FormData();
    body.append("authoring", JSON.stringify(payload));
    added.forEach((photo) => body.append("images[]", photo.file));
    if (product) body.append("_method", "PUT");
    try {
      const { data } = await axios.post(
        product ? `/product/${product.id}/update` : "/product/store",
        body,
      );
      onSaved(data.products);
    } catch (error) {
      setErrors(error.response?.data?.errors || {});
      setMessage(
        error.response?.data?.message ||
          "The item could not be saved. Your entries and existing photos are retained.",
      );
      requestAnimationFrame(() => summary.current?.focus());
    } finally {
      setBusy(false);
    }
  };
  return (
    <form onSubmit={save} className="space-y-8">
      <section className="surface">
        <h2>{product ? "Edit item" : "Create item"}</h2>
        {message && (
          <div ref={summary} role="alert" tabIndex="-1" className="field-error">
            {message}
            {Object.entries(errors).map(([key, values]) => (
              <p key={key}>
                {key}: {Array.isArray(values) ? values[0] : values}
              </p>
            ))}
          </div>
        )}
        <div className="form-grid mt-6">
          <Field form={form} name="name" label="Item name" />
          <Field
            form={form}
            name="sku"
            label="Inventory reference (optional)"
            required={false}
          />
          <Field
            form={form}
            name="price_minor"
            label="Price (AUD cents)"
            type="number"
            min="0"
            step="1"
          />
          <Field
            form={form}
            name="discount_bps"
            label="Discount basis points (100 = 1%)"
            type="number"
            min="0"
            max="10000"
            step="1"
          />
          <Field
            form={form}
            name="stock_quantity"
            label="Physical stock (includes held units)"
            type="number"
            min="0"
            step="1"
          />
          <Field
            form={form}
            name="url"
            label="Supplier URL (private, optional)"
            type="url"
            required={false}
          />
          <Field
            form={form}
            name="delivery_date"
            label="Supplied delivery estimate (days, optional)"
            type="number"
            min="0"
            max="365"
            required={false}
          />
        </div>
        <label className="mt-6" htmlFor="item-description">
          Item description
          <textarea
            id="item-description"
            rows="5"
            value={data.description}
            onChange={(e) => setData("description", e.target.value)}
            required
            className="bg-[#101713] text-[#edeee9] border-[#67766c]"
          />
        </label>
        <label className="flex-row items-center mt-6">
          <input
            type="checkbox"
            className="w-auto min-h-0 text-[#46534c]"
            checked={data.available}
            onChange={(e) => setData("available", e.target.checked)}
          />
          Allow new purchases (stock is checked separately)
        </label>
        {product && (
          <p className="muted">
            Held: {product.held_quantity}. Sellable now:{" "}
            {product.available_quantity}. Saving below held stock is refused.
          </p>
        )}
      </section>
      <section className="surface">
        <h2>Categories and tags</h2>
        {tags ? (
          <div className="grid sm:grid-cols-2 gap-8">
            {[
              [
                "category_ids",
                "Categories (choose at least one)",
                tags.categories,
              ],
              ["type_tag_ids", "Other tags (optional)", tags.types],
            ].map(([name, title, choices]) => (
              <fieldset key={name}>
                <legend className="mb-4">{title}</legend>
                {choices.length ? (
                  choices.map((tag) => (
                    <label key={tag.id} className="flex-row items-center my-3">
                      <input
                        className="w-auto min-h-0 text-[#46534c]"
                        type="checkbox"
                        checked={data[name].includes(tag.id)}
                        onChange={(e) =>
                          setData(
                            name,
                            e.target.checked
                              ? [...data[name], tag.id]
                              : data[name].filter((id) => id !== tag.id),
                          )
                        }
                      />
                      {text(tag.name)}
                    </label>
                  ))
                ) : (
                  <p>No optional tags configured.</p>
                )}
              </fieldset>
            ))}
          </div>
        ) : (
          <button type="button" className="button" onClick={loadTags}>
            Retry category loading
          </button>
        )}
      </section>
      <section className="surface">
        <h2>Supplied item facts</h2>
        <p className="muted">
          Only enter known facts. Keep condition, size and provenance wording
          factual.
        </p>
        {data.facts.map((fact, index) => (
          <div className="border-b border-[#46534c] py-5" key={index}>
            <div className="form-grid">
              <label>
                Fact {index + 1} label
                <input
                  value={fact.label}
                  required
                  onChange={(e) =>
                    update("facts", index, "label", e.target.value)
                  }
                />
              </label>
              <label>
                Fact {index + 1} value
                <input
                  value={fact.value}
                  required
                  onChange={(e) =>
                    update("facts", index, "value", e.target.value)
                  }
                />
              </label>
            </div>
            <div className="flex flex-wrap gap-4 mt-3">
              <button
                type="button"
                className="text-link"
                disabled={index === 0}
                onClick={() => setData("facts", reorder(data.facts, index, -1))}
              >
                Move fact {index + 1} up
              </button>
              <button
                type="button"
                className="text-link"
                disabled={index === data.facts.length - 1}
                onClick={() => setData("facts", reorder(data.facts, index, 1))}
              >
                Move fact {index + 1} down
              </button>
              <button
                type="button"
                className="text-link"
                onClick={() =>
                  setData(
                    "facts",
                    data.facts.filter((_, i) => i !== index),
                  )
                }
              >
                Remove fact {index + 1}
              </button>
            </div>
          </div>
        ))}
        <button
          type="button"
          className="button mt-4"
          onClick={() =>
            setData("facts", [...data.facts, { label: "", value: "" }])
          }
        >
          Add fact
        </button>
      </section>
      <section className="surface">
        <h2>Optional selections</h2>
        <p className="muted">
          Choices share this item’s price and stock. List physically different
          objects separately.
        </p>
        {data.options.map((option, index) => (
          <div className="form-grid border-b border-[#46534c] py-5" key={index}>
            <label>
              Selection {index + 1} name
              <input
                value={option.type}
                required
                onChange={(e) =>
                  update("options", index, "type", e.target.value)
                }
              />
            </label>
            <label>
              Selection {index + 1} values (one per line)
              <textarea
                className="bg-[#101713] text-[#edeee9]"
                rows="4"
                value={option.values.join("\n")}
                required
                onChange={(e) =>
                  update("options", index, "values", e.target.value.split("\n"))
                }
              />
            </label>
            <button
              type="button"
              className="text-link"
              onClick={() =>
                setData(
                  "options",
                  data.options.filter((_, i) => i !== index),
                )
              }
            >
              Remove selection {index + 1}
            </button>
          </div>
        ))}
        <button
          type="button"
          className="button mt-4"
          onClick={() =>
            setData("options", [...data.options, { type: "", values: [] }])
          }
        >
          Add selection
        </button>
      </section>
      <section className="surface">
        <h2>Item photographs</h2>
        <p className="muted">
          JPEG, PNG or WebP; up to 20 photos, 20 MB each and 12000 pixels per
          dimension. Originals remain factual and unfiltered.
        </p>
        <label className="mt-6">
          Add photographs
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            onChange={(event) => {
              const next = [...event.target.files].map((file) => {
                const url = URL.createObjectURL(file);
                urls.current.push(url);
                return { kind: "new", file, url, alt_text: "", caption: "" };
              });
              setPhotos([...photos, ...next]);
              setNoImages(false);
              event.target.value = "";
            }}
          />
        </label>
        {photos.map((photo, index) => (
          <div
            key={photo.id || photo.url}
            className="grid sm:grid-cols-[120px_1fr] gap-5 border-b border-[#46534c] py-6"
          >
            {photo.url ? (
              <img
                src={photo.url}
                alt={photo.alt_text || `Photo ${index + 1} preview`}
                className="w-full h-auto aspect-square object-contain"
              />
            ) : (
              <p>Original photograph missing</p>
            )}
            <div className="space-y-3">
              <label>
                Photo {index + 1} alternative text
                <input
                  value={photo.alt_text}
                  onChange={(e) =>
                    photoUpdate(index, "alt_text", e.target.value)
                  }
                />
              </label>
              <label>
                Photo {index + 1} caption
                <input
                  value={photo.caption}
                  onChange={(e) =>
                    photoUpdate(index, "caption", e.target.value)
                  }
                />
              </label>
              <div className="flex flex-wrap gap-4">
                <button
                  type="button"
                  className="text-link"
                  disabled={index === 0}
                  onClick={() => setPhotos(reorder(photos, index, -1))}
                >
                  Move photo {index + 1} up
                </button>
                <button
                  type="button"
                  className="text-link"
                  disabled={index === photos.length - 1}
                  onClick={() => setPhotos(reorder(photos, index, 1))}
                >
                  Move photo {index + 1} down
                </button>
                <button
                  type="button"
                  className="text-link"
                  onClick={() =>
                    setPhotos(photos.filter((_, i) => i !== index))
                  }
                >
                  Remove photo {index + 1}
                </button>
              </div>
            </div>
          </div>
        ))}
        <label className="flex-row items-center mt-6">
          <input
            className="w-auto min-h-0 text-[#46534c]"
            type="checkbox"
            checked={noImages}
            disabled={photos.length > 0}
            onChange={(e) => setNoImages(e.target.checked)}
          />
          Confirm this item has no photographs
        </label>
      </section>
      <div className="flex flex-wrap gap-5">
        <button className="button button-light" disabled={busy || !tags}>
          {busy ? "Saving…" : "Save item"}
        </button>
        <button
          className="button"
          type="button"
          disabled={busy}
          onClick={onCancel}
        >
          Cancel editing
        </button>
      </div>
    </form>
  );
}
