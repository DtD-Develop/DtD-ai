"use client";

import React, { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Activity,
  Database,
  FileText,
  RefreshCw,
  AlertTriangle,
  MessageSquare,
} from "lucide-react";
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
} from "recharts";

const API_URL = process.env.NEXT_PUBLIC_API_URL!;
const API_KEY = process.env.NEXT_PUBLIC_API_KEY!;

export default function DashboardPage() {
  const [overview, setOverview] = useState<any>(null);
  const [chartPoints, setChartPoints] = useState<any[]>([]);
  const [recent, setRecent] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchData = async () => {
    try {
      setLoading(true);

      const [ov, ch, rq] = await Promise.all([
        fetch(`${API_URL}/api/dashboard/overview`, {
          headers: { "X-API-KEY": API_KEY },
        }).then((r) => r.json()),

        fetch(`${API_URL}/api/dashboard/query-chart`, {
          headers: { "X-API-KEY": API_KEY },
        }).then((r) => r.json()),

        fetch(`${API_URL}/api/dashboard/recent-queries`, {
          headers: { "X-API-KEY": API_KEY },
        }).then((r) => r.json()),
      ]);

      setOverview(ov);
      setChartPoints(ch.points || []);
      setRecent(rq.data || []);
    } catch (err) {
      console.error("Dashboard load failed:", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  if (!overview || loading) {
    return <div className="p-6 text-center opacity-60">Loading dashboard…</div>;
  }

  return (
    <div className="p-6 space-y-8">
      {/* Header */}
      <div className="flex justify-between items-center">
        <h1 className="text-3xl font-semibold">Dashboard</h1>

        <Button variant="outline" onClick={fetchData}>
          <RefreshCw size={18} className="mr-2" />
          Refresh
        </Button>
      </div>

      {/* Stats Overview */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        {[
          {
            title: "KB Files",
            value: overview.kb_files_total,
            icon: FileText,
            color: "text-blue-500",
          },
          {
            title: "Ready Files",
            value: overview.kb_ready,
            icon: Activity,
            color: "text-green-500",
          },
          {
            title: "Chunks",
            value: overview.kb_chunks,
            icon: Database,
            color: "text-purple-500",
          },
          {
            title: "Queue Jobs",
            value: overview.queue_default,
            icon: AlertTriangle,
            color: "text-orange-500",
          },
        ].map((item, i) => (
          <Card key={i} className="shadow-sm">
            <CardContent className="flex justify-between items-center p-4">
              <div>
                <p className="text-sm text-muted-foreground">{item.title}</p>
                <p className="text-2xl font-bold">{item.value}</p>
              </div>
              <item.icon className={item.color} size={32} />
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Query Usage Chart */}
      <Card className="shadow-sm">
        <CardHeader>
          <CardTitle>Query Usage (Last 24h)</CardTitle>
        </CardHeader>
        <CardContent className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartPoints}>
              <XAxis dataKey="label" stroke="#888" />
              <YAxis allowDecimals={false} stroke="#888" />
              <Tooltip />
              <Line
                type="monotone"
                dataKey="count"
                stroke="#3b82f6"
                strokeWidth={2}
              />
            </LineChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Recent Queries */}
      <Card className="shadow-sm">
        <CardHeader>
          <CardTitle>Recent Chat Queries</CardTitle>
        </CardHeader>
        <CardContent>
          <table className="min-w-full text-sm">
            <thead className="border-b">
              <tr className="text-left text-muted-foreground">
                <th className="py-2">Time</th>
                <th>Query</th>
                <th>Status</th>
                <th>Latency</th>
              </tr>
            </thead>
            <tbody>
              {recent.slice(0, 15).map((log) => (
                <tr key={log.id} className="border-b last:border-none">
                  <td className="py-2 whitespace-nowrap text-muted-foreground">
                    {new Date(log.created_at).toLocaleTimeString()}
                  </td>
                  <td className="max-w-[350px] truncate">{log.query}</td>
                  <td>
                    <Badge
                      variant={
                        log.status_code === 200 ? "default" : "destructive"
                      }
                    >
                      {log.status_code}
                    </Badge>
                  </td>
                  <td>{log.latency_ms} ms</td>
                </tr>
              ))}
            </tbody>
          </table>

          {recent.length === 0 && (
            <p className="text-center py-4 opacity-60">No logs found yet.</p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
