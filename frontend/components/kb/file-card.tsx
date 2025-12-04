"use client";

import { RefreshCw, CheckCircle, Loader2 } from "lucide-react";

type Props = {
  file: any;
  onClick?: () => void;
};

export function FileCard({ file, onClick }: Props) {
  const status = file.status;

  const color =
    status === "tagged"
      ? "text-emerald-600"
      : status === "embedding"
        ? "text-blue-500"
        : status === "error"
          ? "text-red-500"
          : "text-muted-foreground";

  return (
    <div
      className="border rounded-lg p-3 mb-2 cursor-pointer hover:bg-accent/40 transition"
      onClick={onClick}
    >
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium truncate max-w-[200px]">
          {file.original_name}
        </span>

        {status === "tagged" && <CheckCircle className={`w-4 h-4 ${color}`} />}
        {status === "pending" && <Loader2 className="w-4 h-4 animate-spin" />}
        {status === "embedding" && <Loader2 className="w-4 h-4 animate-spin" />}
        {status === "error" && <RefreshCw className={`w-4 h-4 ${color}`} />}
      </div>

      <div className="mt-1 text-[11px] text-muted-foreground">
        Status: {file.status}
      </div>
    </div>
  );
}
