"use client";

import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
} from "recharts";

type Props = {
  data: { d: string; total: number; success: number; error: number }[];
};

export function TrendChart({ data }: Props) {
  if (!data || data.length === 0) return null;

  return (
    <div className="w-full h-52 border rounded-xl p-2 bg-card">
      <div className="text-xs font-semibold mb-2">Requests (7 days)</div>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data}>
          <XAxis dataKey="d" tick={{ fontSize: 10 }} />
          <YAxis tick={{ fontSize: 10 }} />
          <Tooltip />
          <Legend />
          <Line type="monotone" dataKey="total" stroke="#8884d8" dot={false} />
          <Line
            type="monotone"
            dataKey="success"
            stroke="#22c55e"
            dot={false}
          />
          <Line type="monotone" dataKey="error" stroke="#ef4444" dot={false} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
