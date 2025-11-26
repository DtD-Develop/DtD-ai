import type { ReactNode } from "react";

export const metadata = {
  title: "AI Chat UI",
  description: "AI Chat and Upload UI",
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <body>
        <div style={{ maxWidth: 900, margin: "0 auto", padding: 20 }}>
          <h1>AI Platform Frontend</h1>
          <nav style={{ marginBottom: 20 }}>
            <a href="/chat" style={{ marginRight: 12 }}>
              Chat
            </a>
            <a href="/upload" style={{ marginRight: 12 }}>
              Upload
            </a>
            <a href="/train">Train</a>
          </nav>
          {children}
        </div>
      </body>
    </html>
  );
}
