import "./bootstrap";
import "../css/app.css";
import { createRoot } from "react-dom/client";
import { createInertiaApp } from "@inertiajs/react";
import MainLayout from "./Layouts/MainLayout";
createInertiaApp({
  title: (title) => `${title} — Max’s Collectibles`,
  resolve: (name) => {
    const pages = import.meta.glob("./Pages/**/*.jsx", { eager: true });
    const page = pages[`./Pages/${name}.jsx`];
    page.default.layout ||= (page) => <MainLayout>{page}</MainLayout>;
    return page;
  },
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
  progress: { color: "#c3d5ce" },
});
