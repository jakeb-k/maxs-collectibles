import { create } from "zustand";
import { persist } from "zustand/middleware";
import { cleanFavourites, safeStorage } from "./persistence";
const useFavouritesStore = create(
  persist(
    (set) => ({
      favourites: [],
      notice: "",
      setFavourites: (values) => set({ favourites: cleanFavourites(values) }),
      addFavourite: (item) =>
        set((state) => ({
          favourites: cleanFavourites([...state.favourites, item]),
        })),
      removeFavourite: (id) =>
        set((state) => ({
          favourites: state.favourites.filter((value) => value !== id),
        })),
    }),
    {
      name: "favs-storage",
      version: 2,
      storage: safeStorage,
      migrate: (raw) => ({
        favourites: cleanFavourites(raw?.favourites),
        notice: "Saved favourites updated and checked against the collection.",
      }),
      merge: (raw, current) => ({
        ...current,
        favourites: cleanFavourites(raw?.favourites),
        notice: typeof raw?.notice === "string" ? raw.notice : "",
      }),
      partialize: ({ favourites }) => ({ favourites }),
    },
  ),
);
export default useFavouritesStore;
