const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || "";
const API_KEY = process.env.NEXT_PUBLIC_API_KEY || "";

async function kbFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const res = await fetch(`${BASE_URL}${path}`, {
    ...options,
    headers: {
      "X-API-KEY": API_KEY,
      ...(options.headers || {}),
    },
  });

  if (!res.ok) {
    throw new Error(await res.text());
  }

  return res.json();
}

export const kbApi = {
  upload(files: File[]): Promise<any[]> {
    const formData = new FormData();
    files.forEach((f) => formData.append("files[]", f));

    return kbFetch("/api/kb/upload", {
      method: "POST",
      body: formData,
    });
  },

  getFile(id: number): Promise<any> {
    return kbFetch(`/api/kb/files/${id}`);
  },

  updateTags(id: number, tags: string[]): Promise<any> {
    return kbFetch(`/api/kb/files/${id}`, {
      method: "PATCH",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ tags }),
    });
  },

  confirmEmbed(id: number): Promise<any> {
    return kbFetch(`/api/kb/files/${id}/confirm`, {
      method: "POST",
    });
  },
  getChunks(id: number): Promise<any[]> {
    return kbFetch(`/api/kb/files/${id}/chunks`);
  },

  deleteChunk(fileId: number, chunkId: number): Promise<{ status: string }> {
    return kbFetch(`/api/kb/files/${fileId}/chunks/${chunkId}`, {
      method: "DELETE",
    });
  },
};
