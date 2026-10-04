import { useEffect, useState } from "react";
import { getStoredValue } from "../lib/demo";

function usePersistentState(key, initialValue) {
  const [value, setValue] = useState(() => getStoredValue(key, initialValue));

  useEffect(() => {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (error) {
      console.error(`Unable to save demo data for "${key}".`, error);
    }
  }, [key, value]);

  return [value, setValue];
}

export default usePersistentState;
