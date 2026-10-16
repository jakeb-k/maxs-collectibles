export const integer = (value) =>
  Number.isSafeInteger(value) && value > 0 && value <= 999999999;
export const uuid = () =>
  globalThis.crypto?.randomUUID?.() ||
  `${Date.now()}-${Math.random().toString(36).slice(2)}`;
export function selection(value) {
  if (!value || (Array.isArray(value) && !value.length)) return {};
  if (
    typeof value !== "object" ||
    Array.isArray(value) ||
    Object.keys(value).length > 32
  )
    return null;
  const entries = Object.entries(value);
  if (
    entries.some(
      ([key, val]) =>
        !key ||
        key.length > 100 ||
        ["__proto__", "constructor", "prototype"].includes(key) ||
        typeof val !== "string" ||
        !val ||
        val.length > 255,
    )
  )
    return null;
  return Object.fromEntries(entries.sort(([a], [b]) => a.localeCompare(b)));
}
export const itemIdentity = (item) =>
  `${item.id}:${JSON.stringify(item.selection)}`;
export function cleanCart(value) {
  if (!Array.isArray(value)) return [];
  const items = new Map();
  const lineKeys = new Set();
  for (const raw of value.slice(0, 100)) {
    if (
      !raw ||
      !integer(raw.id) ||
      !Number.isInteger(raw.quantity) ||
      raw.quantity < 1 ||
      raw.quantity > 999999
    )
      continue;
    // Apparel-era choices have no trusted mapping to today's option definitions.
    if (!raw.selection && (raw.color || raw.size)) continue;
    const chosen = selection(raw.selection);
    if (chosen === null) continue;
    const item = {
      id: raw.id,
      selection: chosen,
      quantity: raw.quantity,
      line_key:
        typeof raw.line_key === "string" &&
        raw.line_key.length > 0 &&
        raw.line_key.length <= 80 &&
        !lineKeys.has(raw.line_key)
          ? raw.line_key
          : uuid(),
    };
    lineKeys.add(item.line_key);
    const key = itemIdentity(item);
    if (items.has(key))
      items.get(key).quantity = Math.min(
        999999,
        items.get(key).quantity + item.quantity,
      );
    else items.set(key, item);
  }
  return [...items.values()];
}
export const cleanFavourites = (value) =>
  [
    ...new Set(
      (Array.isArray(value) ? value : [])
        .map((item) => (typeof item === "object" ? item?.id : item))
        .filter(integer),
    ),
  ].slice(0, 100);
export const safeStorage = {
  getItem(name) {
    try {
      const raw =
        typeof window !== "undefined"
          ? window.localStorage.getItem(name)
          : null;
      return raw ? JSON.parse(raw) : null;
    } catch {
      return {
        version: 2,
        state: {
          notice: "Saved shopping data was unreadable and has been reset.",
        },
      };
    }
  },
  setItem(name, value) {
    try {
      if (typeof window !== "undefined")
        window.localStorage.setItem(name, JSON.stringify(value));
    } catch {
      /* Shopping remains usable in memory when storage is disabled. */
    }
  },
  removeItem(name) {
    try {
      if (typeof window !== "undefined") window.localStorage.removeItem(name);
    } catch {}
  },
};
