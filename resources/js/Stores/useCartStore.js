import { create } from "zustand";
import { persist } from "zustand/middleware";
import { cleanCart, itemIdentity, safeStorage, uuid } from "./persistence";
const cleanAttempt = (raw) =>
  raw &&
  typeof raw.token === "string" &&
  raw.token.length > 0 &&
  raw.token.length < 20000 &&
  Array.isArray(raw.lines)
    ? { token: raw.token, lines: cleanCart(raw.lines) }
    : null;
const sanitize = (raw) => ({
  products: cleanCart(raw?.products),
  attempts: Object.fromEntries(
    Object.entries(raw?.attempts || {})
      .slice(-20)
      .filter(
        ([key, value]) => /^[a-f0-9-]{36}$/i.test(key) && cleanAttempt(value),
      )
      .map(([key, value]) => [key, cleanAttempt(value)]),
  ),
  settled: Array.isArray(raw?.settled)
    ? raw.settled.filter((key) => typeof key === "string").slice(-100)
    : [],
  pendingKey: typeof raw?.pendingKey === "string" ? raw.pendingKey : null,
  notice: typeof raw?.notice === "string" ? raw.notice : "",
});
const useCartStore = create(
  persist(
    (set) => ({
      products: [],
      attempts: {},
      settled: [],
      pendingKey: null,
      notice: "",
      setProducts: (products) => set({ products: cleanCart(products) }),
      addProduct: (raw) =>
        set((state) => {
          const [item] = cleanCart([{ ...raw, line_key: uuid() }]);
          if (!item)
            return {
              notice:
                "That item could not be saved. Please refresh and try again.",
            };
          const existing = state.products.find(
            (p) => itemIdentity(p) === itemIdentity(item),
          );
          return {
            products: existing
              ? state.products.map((p) =>
                  p.line_key === existing.line_key
                    ? {
                        ...p,
                        quantity: Math.min(999999, p.quantity + item.quantity),
                      }
                    : p,
                )
              : [...state.products, item].slice(0, 100),
            notice: "",
          };
        }),
      updateQuantity: (key, quantity) => {
        if (Number.isInteger(quantity) && quantity >= 1 && quantity <= 999999)
          set((state) => ({
            products: state.products.map((p) =>
              p.line_key === key ? { ...p, quantity } : p,
            ),
          }));
      },
      removeItem: (key) =>
        set((state) => ({
          products: state.products.filter((p) => p.line_key !== key),
        })),
      captureAttempt: (key, token, lines = []) =>
        set((state) => ({
          attempts: {
            ...state.attempts,
            [key]: state.attempts[key] || {
              token,
              lines: cleanCart(lines).map((p) => ({
                ...p,
                selection: { ...p.selection },
              })),
            },
          },
          pendingKey: key,
        })),
      releaseAttempt: (key) =>
        set((state) => {
          const attempts = { ...state.attempts };
          delete attempts[key];
          return {
            attempts,
            pendingKey: state.pendingKey === key ? null : state.pendingKey,
          };
        }),
      settleAttempt: (key) =>
        set((state) => {
          const captured = state.attempts[key];
          if (!captured || state.settled.includes(key)) return {};
          const quantities = new Map(
            captured.lines.map((p) => [p.line_key, p.quantity]),
          );
          const products = state.products
            .map((p) => ({
              ...p,
              quantity: p.quantity - (quantities.get(p.line_key) || 0),
            }))
            .filter((p) => p.quantity > 0);
          const attempts = { ...state.attempts };
          delete attempts[key];
          return {
            products,
            attempts,
            settled: [...state.settled, key].slice(-100),
            pendingKey: state.pendingKey === key ? null : state.pendingKey,
          };
        }),
    }),
    {
      name: "cart-storage",
      version: 2,
      storage: safeStorage,
      migrate: (raw) => ({
        ...sanitize(raw),
        notice:
          "Saved bag updated. Items will be checked against the current collection.",
      }),
      merge: (raw, current) => ({ ...current, ...sanitize(raw) }),
      partialize: ({ products, attempts, settled, pendingKey }) => ({
        products,
        attempts,
        settled,
        pendingKey,
      }),
    },
  ),
);
export default useCartStore;
