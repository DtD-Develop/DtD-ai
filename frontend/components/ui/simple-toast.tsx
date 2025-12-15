"use client";

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  ReactNode,
} from "react";

type ToastVariant = "default" | "success" | "error";

type Toast = {
  id: number;
  message: string;
  variant?: ToastVariant;
  duration?: number;
};

type SimpleToastContextValue = {
  showToast: (message: string, opts?: { variant?: ToastVariant; duration?: number }) => void;
};

const SimpleToastContext = createContext<SimpleToastContextValue | undefined>(
  undefined,
);

let idCounter = 1;

export function SimpleToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const removeToast = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const showToast = useCallback(
    (message: string, opts?: { variant?: ToastVariant; duration?: number }) => {
      const id = idCounter++;
      const toast: Toast = {
        id,
        message,
        variant: opts?.variant ?? "default",
        duration: opts?.duration ?? 3000,
      };
      setToasts((prev) => [...prev, toast]);

      // auto dismiss
      if (toast.duration && toast.duration > 0) {
        window.setTimeout(() => {
          removeToast(id);
        }, toast.duration);
      }
    },
    [removeToast],
  );

  return (
    <SimpleToastContext.Provider value={{ showToast }}>
      {children}
      <ToastViewport toasts={toasts} onDismiss={removeToast} />
    </SimpleToastContext.Provider>
  );
}

export function useSimpleToast() {
  const ctx = useContext(SimpleToastContext);
  if (!ctx) {
    throw new Error("useSimpleToast must be used within a SimpleToastProvider");
  }
  return ctx;
}

function ToastViewport({
  toasts,
  onDismiss,
}: {
  toasts: Toast[];
  onDismiss: (id: number) => void;
}) {
  // Prevent body scroll shift on mobile keyboards/etc. not really needed but safe.
  useEffect(() => {
    if (toasts.length === 0) return;
    const original = document.body.style.paddingRight;
    return () => {
      document.body.style.paddingRight = original;
    };
  }, [toasts.length]);

  if (toasts.length === 0) return null;

  return (
    <div className="fixed inset-x-0 bottom-3 z-50 flex justify-center px-4 pointer-events-none">
      <div className="flex w-full max-w-md flex-col gap-2">
        {toasts.map((t) => (
          <ToastItem key={t.id} toast={t} onDismiss={onDismiss} />
        ))}
      </div>
    </div>
  );
}

function ToastItem({
  toast,
  onDismiss,
}: {
  toast: Toast;
  onDismiss: (id: number) => void;
}) {
  const { id, message, variant } = toast;

  let base =
    "pointer-events-auto flex items-center justify-between rounded-lg border px-3 py-2 text-xs shadow-sm backdrop-blur bg-background/95";
  let color = "";
  if (variant === "success") {
    color = "border-emerald-500/40 text-emerald-200 bg-emerald-900/80";
  } else if (variant === "error") {
    color = "border-rose-500/40 text-rose-100 bg-rose-900/80";
  } else {
    color = "border-border text-foreground bg-neutral-900/90";
  }

  return (
    <div className={`${base} ${color}`}>
      <span className="mr-2">{message}</span>
      <button
        type="button"
        onClick={() => onDismiss(id)}
        className="ml-2 text-[10px] uppercase tracking-wide opacity-60 hover:opacity-100"
      >
        Close
      </button>
    </div>
  );
}
