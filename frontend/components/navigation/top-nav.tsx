"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { navItems } from "./nav-items";
import { ThemeToggle } from "../theme-toggle";

export function TopNav() {
  const pathname = usePathname();

  return (
    <header className="sticky top-0 z-40 bg-background border-b">
      <div className="flex h-14 items-center justify-between px-4">
        {/* Left */}
        <div className="flex items-center gap-6">
          <Link
            href="/dashboard"
            className="font-semibold text-lg tracking-tight"
          >
            DtD-AI
          </Link>

          <nav className="hidden md:flex gap-4">
            {navItems.map((item) => {
              const active = pathname.startsWith(item.href);
              return (
                <Link
                  key={item.title}
                  href={item.href}
                  className={`text-sm font-medium transition-colors hover:text-primary ${
                    active ? "text-primary" : "text-muted-foreground"
                  }`}
                >
                  {item.title}
                </Link>
              );
            })}
          </nav>
        </div>

        {/* Right */}
        <div className="flex items-center gap-2">
          <ThemeToggle />
        </div>
      </div>
    </header>
  );
}
