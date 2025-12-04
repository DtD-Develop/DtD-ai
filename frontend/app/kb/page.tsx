"use client";

import { useEffect, useState } from "react";
import { kbApi } from "@/lib/api-kb";
import { KbTable } from "@/components/kb/kb-table";

export default function KbPage() {
  const [files, setFiles] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);

  async function refresh() {
    setLoading(true);
    try {
      const res = (await kbApi.getFileList?.()) ?? [];
      setFiles(res);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    refresh();
    const timer = setInterval(refresh, 5000);
    return () => clearInterval(timer);
  }, []);

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-semibold">Knowledge Base Table</h1>
      <KbTable files={files} refresh={refresh} />
    </div>
  );
}
