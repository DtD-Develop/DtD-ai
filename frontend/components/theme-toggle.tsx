"use client";

import { useTheme } from "next-themes";
import { Monitor, Moon, Sun } from "lucide-react";

export function ThemeToggle() {
  const { theme, setTheme } = useTheme();

  const icon =
    theme === "dark" ? (
      <Moon className="w-5 h-5" />
    ) : theme === "light" ? (
      <Sun className="w-5 h-5" />
    ) : (
      <Monitor className="w-5 h-5" />
    );

  const next =
    theme === "light" ? "dark" : theme === "dark" ? "system" : "light";

  return (
    <button
      onClick={() => setTheme(next)}
      className="p-2 rounded-md border hover:bg-accent transition"
      title="Toggle Theme"
    >
      {icon}
    </button>
  );
}
