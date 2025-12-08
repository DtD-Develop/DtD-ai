"use client";

import React, { useEffect, useMemo, useState } from "react";

const API_URL = process.env.NEXT_PUBLIC_API_URL;
const API_KEY = process.env.NEXT_PUBLIC_API_KEY;

type KbFile = {
  id: number;
  filename: string;
  original_name: string;
  mime_type: string | null;
  size_bytes: number;
  storage_path: string;
  status: "uploaded" | "parsing" | "tagged" | "embedding" | "ready" | "failed";
  progress: number;
  auto_tags: string[] | null;
  tags: string[] | null;
  chunks_count: number;
  error_message: string | null;
  created_at: string;
  updated_at: string;
};

type PaginatedResponse = {
  data: KbFile[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
};

type LogEntry = {
  level: "info" | "warn" | "error" | "success";
  message: string;
};

export default function UploadKbPage() {
  const [files, setFiles] = useState<KbFile[]>([]);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [selectedFileId, setSelectedFileId] = useState<number | null>(null);
  const [tagInput, setTagInput] = useState("");
  const [autoTagInput, setAutoTagInput] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  const selectedFile = useMemo(
    () => files.find((f) => f.id === selectedFileId) || null,
    [files, selectedFileId],
  );
  const [theme, setTheme] = useState(
    typeof window !== "undefined"
      ? localStorage.getItem("theme") || "dark"
      : "dark",
  );

  useEffect(() => {
    document.documentElement.classList.toggle("dark", theme === "dark");
    localStorage.setItem("theme", theme);
  }, [theme]);

  // โหลดไฟล์ KB ครั้งแรก + polling
  useEffect(() => {
    if (!API_URL || !API_KEY) return;

    const fetchFiles = async () => {
      try {
        setLoading(true);
        const res = await fetch(`${API_URL}/api/kb/files?per_page=50`, {
          headers: {
            "X-API-KEY": API_KEY,
          },
        });
        const json: PaginatedResponse = await res.json();
        setFiles(json.data || []);
      } catch (e) {
        console.error("Failed to load KB files", e);
      } finally {
        setLoading(false);
      }
    };

    fetchFiles();
    const timer = setInterval(fetchFiles, 5000); // poll ทุก 5 วิ
    return () => clearInterval(timer);
  }, []);

  const handleFilesSelected = async (fileList: FileList | null) => {
    if (!fileList || !API_URL || !API_KEY) return;
    const arr = Array.from(fileList);
    if (arr.length === 0) return;

    setUploading(true);
    setMessage(null);

    try {
      const form = new FormData();
      arr.forEach((file) => form.append("files[]", file));

      const res = await fetch(`${API_URL}/api/kb/upload`, {
        method: "POST",
        headers: {
          "X-API-KEY": API_KEY,
        },
        body: form,
      });

      const json = await res.json();
      if (!res.ok) {
        setMessage(json?.message || "Upload failed");
        return;
      }

      const uploaded = (json.data || []) as KbFile[];
      setFiles((prev) => [...uploaded, ...prev]);
      setMessage("Files uploaded successfully");
    } catch (e) {
      console.error("Upload error", e);
      setMessage("Upload error");
    } finally {
      setUploading(false);
    }
  };

  const handleDrop: React.DragEventHandler<HTMLDivElement> = (e) => {
    e.preventDefault();
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      handleFilesSelected(e.dataTransfer.files);
      e.dataTransfer.clearData();
    }
  };

  const handleConfirm = async (file: KbFile) => {
    if (!API_URL || !API_KEY) return;
    try {
      const res = await fetch(`${API_URL}/api/kb/files/${file.id}/confirm`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-API-KEY": API_KEY,
        },
      });
      const json = await res.json();
      if (!res.ok) {
        setMessage(json?.message || "Confirm failed");
        return;
      }
      // update local
      setFiles((prev) =>
        prev.map((f) => (f.id === file.id ? (json.data as KbFile) : f)),
      );
      setMessage("Embedding started");
    } catch (e) {
      console.error("Confirm error", e);
      setMessage("Confirm error");
    }
  };

  const handleDelete = async (file: KbFile) => {
    if (!API_URL || !API_KEY) return;
    if (!window.confirm(`Delete KB file "${file.original_name}" ?`)) return;
    try {
      const res = await fetch(`${API_URL}/api/kb/files/${file.id}`, {
        method: "DELETE",
        headers: {
          "X-API-KEY": API_KEY,
        },
      });
      if (!res.ok) {
        const json = await res.json();
        setMessage(json?.message || "Delete failed");
        return;
      }
      setFiles((prev) => prev.filter((f) => f.id !== file.id));
      if (selectedFileId === file.id) {
        setSelectedFileId(null);
      }
    } catch (e) {
      console.error("Delete error", e);
      setMessage("Delete error");
    }
  };

  const handleSaveTags = async () => {
    if (!selectedFile || !API_URL || !API_KEY) return;

    const newTags = tagInput
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);
    const newAutoTags = autoTagInput
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);

    try {
      const res = await fetch(
        `${API_URL}/api/kb/files/${selectedFile.id}/tags`,
        {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            "X-API-KEY": API_KEY,
          },
          body: JSON.stringify({
            tags: newTags,
            auto_tags: newAutoTags,
          }),
        },
      );
      const json = await res.json();
      if (!res.ok) {
        setMessage(json?.message || "Update tags failed");
        return;
      }
      const updated = json.data as KbFile;
      setFiles((prev) => prev.map((f) => (f.id === updated.id ? updated : f)));
      setMessage("Tags updated");
    } catch (e) {
      console.error("Update tags error", e);
      setMessage("Update tags error");
    }
  };

  const formatSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`;
    const kb = bytes / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    const mb = kb / 1024;
    return `${mb.toFixed(1)} MB`;
  };

  const statusColor = (status: KbFile["status"]) => {
    switch (status) {
      case "uploaded":
      case "parsing":
        return "bg-sky-500/20 text-sky-400 border-sky-500/40";
      case "tagged":
        return "bg-amber-500/20 text-amber-400 border-amber-500/40";
      case "embedding":
        return "bg-purple-500/20 text-purple-400 border-purple-500/40";
      case "ready":
        return "bg-emerald-500/20 text-emerald-400 border-emerald-500/40";
      case "failed":
        return "bg-rose-500/20 text-rose-400 border-rose-500/40";
      default:
        return "bg-slate-500/20 text-slate-300 border-slate-500/40";
    }
  };

  const buildLogs = (file: KbFile | null): LogEntry[] => {
    if (!file) return [];
    const logs: LogEntry[] = [];

    logs.push({
      level: "info",
      message: `Uploaded: ${file.original_name}`,
    });

    switch (file.status) {
      case "uploaded":
        logs.push({
          level: "info",
          message: "Status: uploaded (waiting for parsing job)",
        });
        break;
      case "parsing":
        logs.push({
          level: "info",
          message: "Status: parsing content",
        });
        break;
      case "tagged":
        logs.push({
          level: "success",
          message: "Status: tagged (waiting for your confirmation to embed)",
        });
        break;
      case "embedding":
        logs.push({
          level: "info",
          message: "Status: embedding to vector DB",
        });
        break;
      case "ready":
        logs.push({
          level: "success",
          message: "Status: ready (available for query)",
        });
        break;
      case "failed":
        logs.push({
          level: "error",
          message: "Status: failed",
        });
        break;
    }

    if (file.error_message) {
      logs.push({
        level: "error",
        message: `Error: ${file.error_message}`,
      });
    }

    return logs;
  };

  // sync tag input when select file
  useEffect(() => {
    if (!selectedFile) {
      setTagInput("");
      setAutoTagInput("");
      return;
    }
    setTagInput((selectedFile.tags || []).join(", "));
    setAutoTagInput((selectedFile.auto_tags || []).join(", "));
  }, [selectedFile]);

  const logsForSelected = buildLogs(selectedFile);
  useEffect(() => {
    if (!selectedFileId || !API_URL || !API_KEY) return;

    const interval = setInterval(async () => {
      try {
        const res = await fetch(`${API_URL}/api/kb/files/${selectedFileId}`, {
          headers: {
            "X-API-KEY": API_KEY,
          },
        });
        const json = await res.json();
        if (json?.data) {
          setFiles((prev) =>
            prev.map((f) => (f.id === json.data.id ? json.data : f)),
          );
        }
      } catch (e) {
        console.error("Failed to update progress", e);
      }
    }, 2000);

    return () => clearInterval(interval);
  }, [selectedFileId]);

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8">
      <div className="max-w-6xl mx-auto">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-semibold">Upload Knowledge Base</h1>
            <p className="text-sm text-slate-400">
              Upload documents, review auto-tags, and trigger embedding.
            </p>
          </div>
        </div>

        {/* Message */}
        {message && (
          <div className="mb-4 rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-xs text-slate-100">
            {message}
          </div>
        )}

        {/* Upload area */}
        <div className="mb-6">
          <div
            className="border border-dashed border-slate-600 bg-slate-900/60 rounded-xl p-6 flex flex-col md:flex-row items-center justify-between gap-4"
            onDragOver={(e) => e.preventDefault()}
            onDrop={handleDrop}
          >
            <div>
              <div className="text-sm font-medium mb-1">
                Drag & drop files here
              </div>
              <div className="text-xs text-slate-400">
                Or click the button to select. Supported: PDF, DOCX, PPTX, TXT,
                MD, JSON, images…
              </div>
            </div>
            <div className="flex items-center gap-3">
              <label className="inline-flex cursor-pointer items-center rounded-full bg-sky-500 hover:bg-sky-400 px-4 py-2 text-xs font-medium text-white">
                {uploading ? "Uploading..." : "Choose files"}
                <input
                  type="file"
                  multiple
                  className="hidden"
                  onChange={(e) => handleFilesSelected(e.target.files)}
                />
              </label>
            </div>
          </div>
        </div>

        {/* Main content: table + logs */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Table */}
          <section className="lg:col-span-2 rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
            <div className="flex items-center justify-between mb-3">
              <div className="text-sm font-semibold">KB Files</div>
              {loading && (
                <div className="text-[10px] text-slate-400 animate-pulse">
                  Refreshing…
                </div>
              )}
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="text-[10px] uppercase text-slate-400 border-b border-slate-800">
                  <tr>
                    <th className="text-left py-2 pr-2">File</th>
                    <th className="text-left py-2 pr-2">Size</th>
                    <th className="text-left py-2 pr-2">Status</th>
                    <th className="text-left py-2 pr-2">Auto Tags</th>
                    <th className="text-right py-2 pl-2">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {files.length === 0 && (
                    <tr>
                      <td
                        colSpan={5}
                        className="py-6 text-center text-xs text-slate-500"
                      >
                        No KB files yet. Try uploading some documents.
                      </td>
                    </tr>
                  )}

                  {files.map((file) => (
                    <tr
                      key={file.id}
                      className={`border-b border-slate-800/60 hover:bg-slate-800/60 cursor-pointer ${
                        selectedFileId === file.id ? "bg-slate-800/80" : ""
                      }`}
                      onClick={() => setSelectedFileId(file.id)}
                    >
                      <td className="py-2 pr-2 align-top">
                        <div className="text-xs font-medium truncate max-w-[220px]">
                          {file.original_name}
                        </div>
                        <div className="text-[10px] text-slate-500">
                          {file.mime_type || "unknown"}
                        </div>
                      </td>
                      <td className="py-2 pr-2 align-top">
                        <div className="text-xs">
                          {formatSize(file.size_bytes)}
                        </div>
                      </td>
                      <td className="py-2 pr-2 align-top">
                        <div
                          className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium ${statusColor(
                            file.status,
                          )}`}
                        >
                          {file.status}
                        </div>
                        <div className="mt-1 h-1.5 w-full bg-slate-800 rounded-full overflow-hidden">
                          <div
                            className="h-full bg-sky-500 rounded-full transition-all"
                            style={{ width: `${file.progress}%` }}
                          />
                        </div>
                      </td>
                      <td className="py-2 pr-2 align-top">
                        <div className="flex flex-wrap gap-1 max-w-[200px]">
                          {(file.tags && file.tags.length > 0
                            ? file.tags
                            : file.auto_tags || []
                          ).map((t) => (
                            <span
                              key={t}
                              className="inline-flex items-center rounded-full bg-slate-800 px-2 py-0.5 text-[10px] text-slate-200"
                            >
                              {t}
                            </span>
                          ))}
                          {(!file.auto_tags || file.auto_tags.length === 0) &&
                            (!file.tags || file.tags.length === 0) && (
                              <span className="text-[10px] text-slate-500">
                                No tags
                              </span>
                            )}
                        </div>
                      </td>
                      <td className="py-2 pl-2 align-top text-right space-x-1">
                        <button
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            setSelectedFileId(file.id);
                          }}
                          className="inline-flex items-center rounded-full border border-slate-600 px-2 py-0.5 text-[10px] hover:bg-slate-700"
                        >
                          Edit tags
                        </button>
                        <button
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            handleConfirm(file);
                          }}
                          disabled={file.status !== "tagged"}
                          className="inline-flex items-center rounded-full border border-emerald-500/60 px-2 py-0.5 text-[10px] text-emerald-300 hover:bg-emerald-500/20 disabled:opacity-40"
                        >
                          Confirm
                        </button>
                        <button
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            handleDelete(file);
                          }}
                          className="inline-flex items-center rounded-full border border-rose-500/60 px-2 py-0.5 text-[10px] text-rose-300 hover:bg-rose-500/20"
                        >
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          {/* Logs + Tags side panel */}
          <section className="rounded-2xl border border-slate-800 bg-slate-900/60 p-4 flex flex-col">
            <div className="text-sm font-semibold mb-2">Details & Logs</div>
            {!selectedFile && (
              <div className="flex-1 flex items-center justify-center text-[11px] text-slate-500 text-center px-4">
                Select a KB file from the table to view tags and logs.
              </div>
            )}

            {selectedFile && (
              <>
                <div className="mb-3">
                  <div className="text-xs font-medium mb-1">
                    {selectedFile.original_name}
                  </div>
                  <div className="text-[10px] text-slate-500">
                    Status: {selectedFile.status} •{" "}
                    {formatSize(selectedFile.size_bytes)}
                  </div>
                </div>

                {/* Tag editors */}
                <div className="mb-3">
                  <div className="text-[11px] font-semibold mb-1">
                    Confirmed Tags
                  </div>
                  <input
                    className="w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-[11px] outline-none"
                    placeholder="tag1, tag2, tag3"
                    value={tagInput}
                    onChange={(e) => setTagInput(e.target.value)}
                  />
                </div>
                <div className="mb-3">
                  <div className="text-[11px] font-semibold mb-1">
                    Auto Tags (model suggestion)
                  </div>
                  <input
                    className="w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-[11px] outline-none"
                    placeholder="auto tags (optional)"
                    value={autoTagInput}
                    onChange={(e) => setAutoTagInput(e.target.value)}
                  />
                </div>
                <div className="mb-4">
                  <button
                    onClick={handleSaveTags}
                    className="rounded-full bg-sky-500 hover:bg-sky-400 px-3 py-1 text-[11px] font-medium text-white"
                  >
                    Save tags
                  </button>
                </div>

                {/* Logs */}
                <div className="text-[11px] font-semibold mb-1">Logs</div>
                <div className="flex-1 overflow-y-auto border border-slate-800 rounded-md bg-slate-950/60 p-2 space-y-1 text-[11px]">
                  {logsForSelected.length === 0 && (
                    <div className="text-slate-500">
                      No logs available for this file.
                    </div>
                  )}
                  {logsForSelected.map((log, idx) => (
                    <div key={idx} className="flex items-start gap-1">
                      <span>
                        {log.level === "info" && "🔵"}
                        {log.level === "warn" && "🟡"}
                        {log.level === "error" && "🔴"}
                        {log.level === "success" && "🟢"}
                      </span>
                      <span
                        className={
                          log.level === "error"
                            ? "text-rose-300"
                            : log.level === "warn"
                              ? "text-amber-300"
                              : log.level === "success"
                                ? "text-emerald-300"
                                : "text-slate-200"
                        }
                      >
                        {log.message}
                      </span>
                    </div>
                  ))}
                </div>
              </>
            )}
          </section>
        </div>
      </div>
    </div>
  );
}
