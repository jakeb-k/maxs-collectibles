import { useCallback, useEffect, useState } from "react";
import axios from "axios";
export default function useCatalogueItems(ids) {
  const key = [...new Set(ids)].sort((a, b) => a - b).join(",");
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState({
    products: [],
    missing: [],
    pending: Boolean(key),
    error: "",
  });
  const refresh = useCallback(() => setRevision((value) => value + 1), []);
  useEffect(() => {
    if (!key) {
      setState({ products: [], missing: [], pending: false, error: "" });
      return;
    }
    const controller = new AbortController();
    setState((previous) => ({ ...previous, pending: true, error: "" }));
    axios
      .get("/products/resolve", {
        params: { ids: key.split(",").map(Number) },
        signal: controller.signal,
      })
      .then(({ data }) =>
        setState({
          products: data.products,
          missing: data.missing,
          pending: false,
          error: "",
        }),
      )
      .catch((error) => {
        if (!controller.signal.aborted)
          setState({
            products: [],
            missing: [],
            pending: false,
            error: "The collection could not be refreshed. Please try again.",
          });
      });
    return () => controller.abort();
  }, [key, revision]);
  return { ...state, refresh };
}
