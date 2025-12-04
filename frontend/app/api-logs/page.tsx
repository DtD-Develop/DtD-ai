"use client";

import { useEffect, useState } from "react";
import { ApiLog, fetchLogs, LogsResponse } from "@/lib/api-logs";
import { FilterBar } from "@/components/api-logs/FilterBar";
import { StatusBadge } from "@/components/api-logs/StatusBadge";
import { LogDetailDrawer } from "@/components/api-logs/LogDetailDrawer";
import { TrendChart } from "@/components/api-logs/TrendChart";

export default function ApiLogsPage() {
  const [logs, setLogs] = useState<ApiLog[]>([]);
  const [meta, setMeta] = useState<LogsResponse["meta"] | null>(null);
  const [trend, setTrend] = useState<LogsResponse["stats"]["trend"]>([]);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);

  const [q, setQ] = useState("");
  const [method, setMethod] = useState("");
  const [statusGroup, setStatusGroup] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [selected, setSelected] = useState<ApiLog | null>(null);

  async function load(p = 1) {
    setLoading(true);
    try {
      const res = await fetchLogs({
        page: p,
        q,
        method,
        status_group: statusGroup,
        from,
        to,
      });
      setLogs(res.data);
      setMeta(res.meta);
      setTrend(res.stats.trend || []);
      setPage(p);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load(1);
  }, []);

  const formatLatency = (ms: number | null) => {
    if (ms == null) return "-";
    // คุณเลือก Latency แบบ A = milliseconds
    return `${ms} ms`;
  };

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-semibold">API Logs</h1>

      <FilterBar
        q={q}
        onQChange={setQ}
        method={method}
        onMethodChange={setMethod}
        statusGroup={statusGroup}
        onStatusGroupChange={setStatusGroup}
        from={from}
        to={to}
        onFromChange={setFrom}
        onToChange={setTo}
        onSubmit={() => load(1)}
      />

      <TrendChart data={trend} />

      <div className="border rounded-xl bg-card">
        <div className="flex items-center justify-between px-3 py-2 border-b">
          <span className="text-xs font-semibold uppercase text-muted-foreground">
            Logs
          </span>
          {loading && (
            <span className="text-[10px] text-muted-foreground">
              Loading...
            </span>
          )}
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead className="border-b text-[10px] text-muted-foreground">
              <tr>
                <th className="text-left py-1 px-2">Time</th>
                <th className="text-left py-1 px-2">Method</th>
                <th className="text-left py-1 px-2">Endpoint</th>
                <th className="text-left py-1 px-2">Status</th>
                <th className="text-right py-1 px-2">Latency</th>
                <th className="text-left py-1 px-2">API Key</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 && (
                <tr>
                  <td
                    colSpan={6}
                    className="py-4 text-center text-[11px] text-muted-foreground"
                  >
                    No logs found.
                  </td>
                </tr>
              )}
              {logs.map((log) => (
                <tr
                  key={log.id}
                  className="border-b hover:bg-accent cursor-pointer"
                  onClick={() => setSelected(log)}
                >
                  <td className="py-1 px-2">
                    {new Date(log.created_at).toLocaleTimeString()}
                  </td>
                  <td className="py-1 px-2 text-[11px] font-mono">
                    {log.method}
                  </td>
                  <td className="py-1 px-2">
                    <span className="font-mono text-[11px]">
                      {log.endpoint}
                    </span>
                  </td>
                  <td className="py-1 px-2">
                    <StatusBadge status={log.status_code} />
                  </td>
                  <td className="py-1 px-2 text-right">
                    {formatLatency(log.latency_ms)}
                  </td>
                  <td className="py-1 px-2 text-[10px] text-muted-foreground">
                    {log.api_key || "-"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {meta && (
          <div className="flex items-center justify-between px-3 py-2 border-t text-[11px] text-muted-foreground">
            <div>
              Page {meta.current_page} / {meta.last_page} • Total {meta.total}
            </div>
            <div className="space-x-2">
              <button
                className="px-2 py-0.5 border rounded disabled:opacity-40"
                onClick={() => load(page - 1)}
                disabled={page <= 1}
              >
                Prev
              </button>
              <button
                className="px-2 py-0.5 border rounded disabled:opacity-40"
                onClick={() => load(page + 1)}
                disabled={meta && page >= meta.last_page}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>

      <LogDetailDrawer log={selected} onClose={() => setSelected(null)} />
    </div>
  );
}
