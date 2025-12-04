"use client";

import { useState } from "react";

type Props = {
  tags: string[];
  onChange: (tags: string[]) => void;
};

export function TagEditor({ tags, onChange }: Props) {
  const [value, setValue] = useState("");

  const add = () => {
    const v = value.trim();
    if (!v) return;
    const newTags = [...tags, v];
    onChange(newTags);
    setValue("");
  };

  return (
    <div className="flex flex-col gap-2">
      <div className="flex gap-1 flex-wrap">
        {tags.map((tag, i) => (
          <div
            key={i}
            className="bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200 text-xs px-2 py-1 rounded-lg mb-1"
            onClick={() => onChange(tags.filter((_, idx) => idx !== i))}
          >
            #{tag}
          </div>
        ))}
      </div>

      <div className="flex gap-2">
        <input
          value={value}
          placeholder="Add tag"
          className="border px-2 py-1 rounded text-sm flex-1"
          onChange={(e) => setValue(e.target.value)}
        />
        <button
          className="text-sm bg-blue-500 text-white px-2 py-1 rounded"
          onClick={add}
        >
          Add
        </button>
      </div>
    </div>
  );
}
