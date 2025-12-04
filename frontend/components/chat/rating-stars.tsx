"use client";

import { useState } from "react";

type Props = {
  initialScore: number | null;
  disabled?: boolean;
  onRate: (score: number) => Promise<void> | void;
  isTrained?: boolean;
};

export function RatingStars({
  initialScore,
  disabled,
  onRate,
  isTrained,
}: Props) {
  const [hover, setHover] = useState<number | null>(null);
  const [score, setScore] = useState<number | null>(initialScore);
  const [loading, setLoading] = useState(false);

  const current = hover ?? score ?? 0;

  const handleClick = async (value: number) => {
    if (disabled || loading) return;
    setLoading(true);
    try {
      await onRate(value);
      setScore(value);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
      <div className="flex items-center">
        {[1, 2, 3, 4, 5].map((v) => (
          <button
            key={v}
            type="button"
            disabled={disabled}
            onMouseEnter={() => setHover(v)}
            onMouseLeave={() => setHover(null)}
            onClick={() => handleClick(v)}
            className="p-0.5"
          >
            <span
              className={
                v <= current ? "text-yellow-400" : "text-muted-foreground"
              }
            >
              ★
            </span>
          </button>
        ))}
      </div>
      {loading && <span>กำลังบันทึก…</span>}
      {!loading && isTrained && (
        <span className="inline-flex items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200 px-2 py-0.5 text-[10px]">
          ✓ Trained
        </span>
      )}
    </div>
  );
}
