"use client";

type Props = {
  mode: "test" | "train";
  onChange: (mode: "test" | "train") => void;
};

export function ModeToggle({ mode, onChange }: Props) {
  return (
    <div className="inline-flex items-center rounded-full border px-1 py-1 text-xs bg-muted">
      <button
        onClick={() => onChange("test")}
        className={`px-3 py-1 rounded-full transition text-xs ${
          mode === "test" ? "bg-background shadow-sm" : "text-muted-foreground"
        }`}
      >
        Test
      </button>
      <button
        onClick={() => onChange("train")}
        className={`px-3 py-1 rounded-full transition text-xs ${
          mode === "train" ? "bg-background shadow-sm" : "text-muted-foreground"
        }`}
      >
        Train ⭐
      </button>
    </div>
  );
}
