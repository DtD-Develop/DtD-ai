const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || "";
const API_KEY = process.env.NEXT_PUBLIC_API_KEY || "";

export type ApiLog = {
  id: number;
  endpoint: string;
  method: string;
  status_code: number | null;
  latency_ms: number | null;
  ip: string | null;
  api_key: string | null;
  request_body: any;
  response_body: any;
  created_at: string;
};

export type LogsResponse = {
  data: ApiLog[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  stats: {
    trend: { d: string; total: number; success: number; error: number }[];
    by_endpoint: { endpoint: string; c: number }[];
  };
};

export async function fetchLogs(params: {
  page?: number;
  q?: string;
  method?: string;
  status_group?: string;
  from?: string;
  to?: string;
}): Promise<LogsResponse> {
  const qs = new URLSearchParams();
  if (params.page) qs.set("page", String(params.page));
  if (params.q) qs.set("q", params.q);
  if (params.method) qs.set("method", params.method);
  if (params.status_group) qs.set("status_group", params.status_group);
  if (params.from) qs.set("from", params.from);
  if (params.to) qs.set("to", params.to);

  const url = `${BASE_URL.replace(/\/+$/, "")}/api/logs?${qs.toString()}`;

  const res = await fetch(url, {
    headers: {
      "X-API-KEY": API_KEY,
    },
    cache: "no-store",
  });

  if (!res.ok) {
    throw new Error(await res.text());
  }

  const json: LogsResponse = await res.json();
  return json;
}
