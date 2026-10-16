import { Head, router } from "@inertiajs/react";
import { useState, useEffect } from "react";
import ProductForm from "@/Components/admin/ProductForm";
import ProductsTable from "@/Components/admin/ProductsTable";
import OrdersTable from "@/Components/admin/OrdersTable";
export default function AdminDashboard(props) {
  const [products, setProducts] = useState(props.products);
  const [orders, setOrders] = useState(props.orders);
  useEffect(() => {
    setProducts(props.products);
    setOrders(props.orders);
  }, [props.products, props.orders]);
  const [tab, setTab] = useState("products");
  const [editing, setEditing] = useState(null);
  const [creating, setCreating] = useState(false);
  const [query, setQuery] = useState("");
  const [message, setMessage] = useState("");
  return (
    <div className="page-width py-12">
      <Head title="Administration" />
      <p className="eyebrow">Max’s Collectibles / Administration</p>
      <h1 className="text-4xl my-6">Manage the collection.</h1>
      <nav className="flex flex-wrap gap-4 mb-8" aria-label="Administration">
        <button
          className="button"
          aria-pressed={tab === "products"}
          onClick={() => {
            setTab("products");
            setEditing(null);
            setCreating(false);
          }}
        >
          Products
        </button>
        <button
          className="button"
          aria-pressed={tab === "orders"}
          onClick={() => {
            setTab("orders");
            setEditing(null);
            setCreating(false);
          }}
        >
          Orders
        </button>
        <button
          className="button button-light"
          onClick={() => {
            setEditing(null);
            setCreating(true);
          }}
        >
          Create item
        </button>
        <button className="text-link" onClick={() => router.reload()}>
          Refresh administration
        </button>
      </nav>
      {message && (
        <p role="status" className="mb-6">
          {message}
        </p>
      )}
      {creating || editing ? (
        <ProductForm
          key={editing?.id || "new"}
          product={editing}
          onCancel={() => {
            setEditing(null);
            setCreating(false);
          }}
          onSaved={(values) => {
            setProducts(values);
            setEditing(null);
            setCreating(false);
            setTab("products");
            setMessage("Item saved.");
          }}
        />
      ) : (
        <>
          <label className="max-w-md mb-6">
            Search {tab}
            <input
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
          </label>
          {tab === "products" ? (
            <ProductsTable
              products={products.filter((p) =>
                p.name.toLowerCase().includes(query.toLowerCase()),
              )}
              onEdit={setEditing}
              onChange={setProducts}
            />
          ) : (
            <OrdersTable
              orders={orders.filter((o) =>
                `${o.code} ${o.contact.first_name} ${o.contact.last_name}`
                  .toLowerCase()
                  .includes(query.toLowerCase()),
              )}
              onChange={setOrders}
            />
          )}
        </>
      )}
    </div>
  );
}
