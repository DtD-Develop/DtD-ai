"use client";

import { useEffect, useState } from "react";

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

  // ถ้ามีการเปลี่ยน initialScore จากภายนอก (เช่น reload messages) ให้ sync เข้ามา
  useEffect(() => {
    setScore(initialScore);
  }, [initialScore]);

  const current = hover ?? score ?? 0;

  const handleClick = async (value: number) => {
    // กันกดซ้ำถ้าให้คะแนนแล้ว หรือกำลังโหลด หรือ disabled
    if (disabled || loading || score != null) return;

    setLoading(true);
    try {
      await onRate(value);
      setScore(value);
    } finally {
      setLoading(false);
    }
  };

  const isReadonly = disabled || score != null;

  return (
    <div className="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
      <div className="flex items-center">
        {[1, 2, 3, 4, 5].map((v) => (
          <button
            key={v}
            type="button"
            disabled={isReadonly}
            onMouseEnter={() => !isReadonly && setHover(v)}
            onMouseLeave={() => !isReadonly && setHover(null)}
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

      {!loading && score != null && (
        <span className="inline-flex items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200 px-2 py-0.5 text-[10px]">
          ✓ Rated ({score}/5)
        </span>
      )}

      {!loading && score == null && isTrained && (
        <span className="inline-flex items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200 px-2 py-0.5 text-[10px]">
          ✓ Trained
        </span>
      )}
    </div>
  );
}
