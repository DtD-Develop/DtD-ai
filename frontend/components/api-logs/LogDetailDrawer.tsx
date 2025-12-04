"use client";

import { ApiLog } from "@/lib/api-logs";

type Props = {
  log: ApiLog | null;
  onClose: () => void;
};

export function LogDetailDrawer({ log, onClose }: Props) {
  if (!log) return null;

  return (
    <div className="fixed inset-0 z-40 flex">
      {/* backdrop */}
      <div className="flex-1 bg-black/40" onClick={onClose} />
      {/* drawer */}
      <div className="w-full max-w-md bg-background border-l border-border p-4 overflow-y-auto">
        <div className="flex items-center justify-between mb-2">
          <h2 className="text-sm font-semibold">Log Detail</h2>
          <button
            className="text-xs text-muted-foreground hover:text-foreground"
            onClick={onClose}
          >
            Close
          </button>
        </div>

        <div className="space-y-2 text-xs">
          <div>
            <div className="font-semibold">Time</div>
            <div className="text-muted-foreground">
              {new Date(log.created_at).toLocaleString()}
            </div>
          </div>
          <div>
            <div className="font-semibold">Request</div>
            <pre className="mt-1 rounded bg-muted p-2 text-[11px] overflow-x-auto">
              {JSON.stringify(log.request_body ?? {}, null, 2)}
            </pre>
          </div>
          <div>
            <div className="font-semibold">Response</div>
            <pre className="mt-1 rounded bg-muted p-2 text-[11px] overflow-x-auto">
              {JSON.stringify(log.response_body ?? {}, null, 2)}
            </pre>
          </div>
        </div>
      </div>
    </div>
  );
}
