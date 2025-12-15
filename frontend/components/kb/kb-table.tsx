"use client";

import { useState } from "react";
import { kbApi } from "@/lib/api-kb";

export function KbTable({ files, refresh }: any) {
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [chunks, setChunks] = useState<any[]>([]);
  const [loadingChunks, setLoadingChunks] = useState(false);

  const toggleExpand = async (fileId: number) => {
    if (expandedId === fileId) {
      setExpandedId(null);
      setChunks([]);
      return;
    }
    setExpandedId(fileId);
    loadChunks(fileId);
  };

  async function loadChunks(fileId: number) {
    setLoadingChunks(true);
    try {
      const res = await kbApi.getChunks(fileId);
      setChunks(res);
    } finally {
      setLoadingChunks(false);
    }
  }

  async function deleteChunk(fileId: number, chunkId: number) {
    if (!confirm("Delete this chunk?")) return;

    await kbApi.deleteChunk(fileId, chunkId);

    loadChunks(fileId); // reload for accuracy
    refresh();
  }

  return (
    <table className="w-full text-sm">
      <thead className="border-b">
        <tr>
          <th className="text-left py-2">File</th>
          <th className="text-left">Tags</th>
          <th className="text-left">Status</th>
          <th className="text-right"></th>
        </tr>
      </thead>
      <tbody>
        {files.map((file) => {
          const isExpanded = expandedId === file.id;
          return (
            <>
              <tr
                key={file.id}
                className="border-b hover:bg-accent cursor-pointer"
                onClick={() => toggleExpand(file.id)}
              >
                <td className="py-2 font-medium">{file.original_name}</td>
                <td>
                  <div className="flex flex-wrap gap-1">
                    {(file.tags?.length ? file.tags : file.auto_tags || []).map(
                      (t: string) => (
                        <span
                          key={t}
                          className="rounded bg-muted px-1.5 py-0.5 text-[10px]"
                        >
                          #{t}
                        </span>
                      ),
                    )}
                  </div>
                </td>
                <td>
                  {(() => {
                    const status: string = file.status ?? "unknown";
                    const base =
                      "text-xs px-2 py-0.5 rounded inline-flex items-center gap-1";

                    if (status === "ready") {
                      return (
                        <span
                          className={`${base} bg-emerald-500/10 text-emerald-500`}
                        >
                          ● <span className="capitalize">{status}</span>
                        </span>
                      );
                    }

                    if (
                      status === "processing" ||
                      status === "parsing" ||
                      status === "embedding"
                    ) {
                      return (
                        <span
                          className={`${base} bg-blue-500/10 text-blue-500`}
                        >
                          ● <span className="capitalize">{status}</span>
                          {typeof file.progress === "number" && (
                            <span className="ml-1 text-[10px] opacity-80">
                              {file.progress}%
                            </span>
                          )}
                        </span>
                      );
                    }

                    if (status === "failed") {
                      return (
                        <span className={`${base} bg-red-500/10 text-red-500`}>
                          ● <span className="capitalize">{status}</span>
                        </span>
                      );
                    }

                    return (
                      <span className={`${base} bg-gray-500/10 text-gray-500`}>
                        ● <span className="capitalize">{status}</span>
                      </span>
                    );
                  })()}
                </td>
                <td className="text-right text-muted-foreground text-xs">
                  {isExpanded ? "Hide" : "View"}
                </td>
              </tr>

              {isExpanded && (
                <tr className="bg-muted/30">
                  <td colSpan={4} className="p-3">
                    <div className="space-y-3">
                      {/* Summary / error / meta */}
                      <div className="space-y-1 text-xs">
                        {file.summary && (
                          <div>
                            <div className="font-semibold mb-0.5">Summary</div>

                            <p className="text-muted-foreground whitespace-pre-wrap">
                              {file.summary}
                            </p>
                          </div>
                        )}

                        {file.error_message && (
                          <div>
                            <div className="font-semibold text-red-500 mb-0.5">
                              Error
                            </div>

                            <p className="text-red-500/80 whitespace-pre-wrap">
                              {file.error_message}
                            </p>
                          </div>
                        )}

                        {typeof file.chunks_count === "number" && (
                          <div className="text-muted-foreground">
                            Chunks: {file.chunks_count}
                          </div>
                        )}
                      </div>

                      {/* Chunks */}
                      <div className="space-y-2">
                        <h4 className="text-xs font-semibold">Chunks</h4>

                        {loadingChunks && (
                          <div className="text-xs text-muted-foreground">
                            Loading chunks...
                          </div>
                        )}

                        {!loadingChunks && chunks.length === 0 && (
                          <div className="text-xs text-muted-foreground">
                            No chunks extracted
                          </div>
                        )}

                        {chunks.map((chunk) => (
                          <div
                            key={chunk.id}
                            className="p-2 bg-background border rounded-lg relative group"
                          >
                            <pre className="text-xs whitespace-pre-wrap text-foreground">
                              {chunk.text}
                            </pre>

                            <button
                              className="absolute top-2 right-2 hidden group-hover:inline-block text-[10px] bg-destructive text-white px-2 py-0.5 rounded"
                              onClick={(e) => {
                                e.stopPropagation();

                                deleteChunk(file.id, chunk.id);
                              }}
                            >
                              Delete
                            </button>
                          </div>
                        ))}
                      </div>
                    </div>
                  </td>
                </tr>
              )}
            </>
          );
        })}
      </tbody>
    </table>
  );
}
