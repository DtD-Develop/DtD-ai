// If you don't have a shared types file yet, you can inline the interfaces below.

/**
 * Dashboard API client
 *
 * This file provides typed helpers to call backend dashboard endpoints:
 * - GET /api/dashboard/overview
 * - GET /api/dashboard/query-chart
 * - GET /api/dashboard/recent-queries
 *
 * It assumes you have NEXT_PUBLIC_API_BASE_URL configured,
 * or it will default to http://localhost:8000.
 */

const API_BASE_URL =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000";

function buildUrl(path: string): string {
  return `${API_BASE_URL.replace(/\/+$/, "")}${path}`;
}

/* ========= Types ========= */

export interface DashboardOverview {
  kb_files_total: number;
  kb_ready: number;
  kb_chunks: number;
  queries_24h: number;
  queue_default: number;
  failed_jobs: number;
}

export interface DashboardQueryPoint {
  timestamp: string; // "YYYY-MM-DD HH:00:00"
  label: string; // "HH:mm"
  count: number;
}

export interface DashboardQueryChartResponse {
  points: DashboardQueryPoint[];
}

export interface DashboardRecentQueryItem {
  id: number;
  endpoint: string;
  method: string;
  status_code: number;
  created_at: string;
  query: string | null;
  latency_ms: number | null;
}

export interface DashboardRecentQueriesResponse {
  data: DashboardRecentQueryItem[];
}

/* ========= Low-level fetch helper ========= */

async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(buildUrl(path), {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
    },
    // credentials: "include", // uncomment if you need cookies
  });

  if (!res.ok) {
    const text = await res.text().catch(() => "");
    throw new Error(
      `Dashboard API error (${res.status} ${res.statusText}): ${text}`,
    );
  }

  return (await res.json()) as T;
}

/* ========= Public client ========= */

export const dashboardApi = {
  /**
   * GET /api/dashboard/overview
   * Returns high-level counters for the dashboard.
   *
   * - kb_files_total
   * - kb_ready
   * - kb_chunks
   * - queries_24h
   * - queue_default
   * - failed_jobs
   */
  async getOverview(): Promise<DashboardOverview> {
    return apiGet<DashboardOverview>("/api/dashboard/overview");
  },

  /**
   * GET /api/dashboard/query-chart
   * Returns per-hour query counts for the last 24 hours.
   */
  async getQueryChart(): Promise<DashboardQueryChartResponse> {
    return apiGet<DashboardQueryChartResponse>("/api/dashboard/query-chart");
  },

  /**
   * GET /api/dashboard/recent-queries
   * Returns recent query logs (up to 20 items).
   */
  async getRecentQueries(): Promise<DashboardRecentQueriesResponse> {
    return apiGet<DashboardRecentQueriesResponse>(
      "/api/dashboard/recent-queries",
    );
  },
};
