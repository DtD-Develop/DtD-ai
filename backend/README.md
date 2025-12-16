# AI Platform Backend (Laravel 12)

This directory contains the **AI Gateway + Admin API** for the AI Platform.

It exposes:

- `/api/ai/*` – AI Platform entrypoints (health, test LLM, RAG chat)
- `/api/kb/*` – Knowledge Base management (upload, list, confirm, chunks)
- `/api/dashboard/*` – Overview metrics and charts for investor/demo
- `/api/logs` – API logs (for observability)
- `/api/legacy/*` – Old chat/query endpoints kept for reference

The backend is designed to use:

- Qdrant as the vector store
- Ollama (local GPU) + optional Gemini (cloud) via a pluggable `LLMRouter`
- A separate `ingest_service` for parsing, chunking and embedding documents

---

## 1. High-level architecture

```text
[Client / Admin UI / Tools]
          |
          v
    /api/ai/chat         /api/kb/*
          |                    |
   [Laravel Backend]  <----  [Jobs: EmbedKbFile, AnalyzeKbFile, ...]
          |
   [LLMRouter + RAG]
          |
   +-----------------------------+
   |     LocalAdapter (Ollama)  |
   |     GeminiAdapter (Cloud)  |
   +-----------------------------+
          |
   [Qdrant Vector DB]  <---  [ingest_service]
