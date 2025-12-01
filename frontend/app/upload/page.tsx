"use client";
import React, { useState } from "react";
import axios from "axios";

export default function UploadPage() {
  const [files, setFiles] = useState<FileList | null>(null);
  const [tags, setTags] = useState("");
  const [status, setStatus] = useState("");

  async function submit() {
    if (!files || files.length === 0) return;

    const fd = new FormData();
    for (let i = 0; i < files.length; i++) {
      fd.append("files", files[i]);
    }
    if (tags.trim()) fd.append("tags", tags);

    setStatus("Uploading...");
    try {
      const res = await axios.post(
        `${process.env.NEXT_PUBLIC_API_URL}/api/upload`,
        fd,
        {
          headers: { "Content-Type": "multipart/form-data" },
        },
      );
      setStatus(JSON.stringify(res.data, null, 2));
    } catch (e: any) {
      setStatus("Error: " + (e.response?.data?.detail || e.message));
    }
  }

  return (
    <div style={{ padding: 20 }}>
      <h2>Upload Knowledge Base</h2>
      <input type="file" multiple onChange={(e) => setFiles(e.target.files)} />

      <div style={{ marginTop: 10 }}>
        <label>Tags (comma separated):</label>
        <input
          type="text"
          placeholder="finance,policy,product"
          value={tags}
          onChange={(e) => setTags(e.target.value)}
          style={{ width: "100%", padding: 8 }}
        />
      </div>

      <button onClick={submit} style={{ marginTop: 12, padding: "8px 16px" }}>
        Upload
      </button>

      <pre style={{ marginTop: 12, whiteSpace: "pre-wrap" }}>{status}</pre>
    </div>
  );
}
