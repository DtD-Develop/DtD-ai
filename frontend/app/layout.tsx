import type { Metadata } from "next";
import "./globals.css";
import { ThemeProvider } from "@/components/theme-provider";
import { TopNav } from "@/components/navigation/top-nav";
import { SimpleToastProvider } from "@/components/ui/simple-toast";

export const metadata: Metadata = {
  title: "DtD-AI",
  description: "Your Knowledge & Chat AI",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" suppressHydrationWarning>
      <body className="min-h-screen bg-background text-foreground">
        <ThemeProvider>
          <SimpleToastProvider>
            <TopNav />

            <main className="container mx-auto py-6 px-4">{children}</main>
          </SimpleToastProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}
