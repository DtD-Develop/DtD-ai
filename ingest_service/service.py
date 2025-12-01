import hashlib
import os
import time
import uuid
from pathlib import Path
from typing import List, Optional

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from qdrant_client import QdrantClient
from qdrant_client.http.models import (
    Distance,
    FieldCondition,
    Filter,
    MatchValue,
    PayloadSchemaType,
    VectorParams,
)
from sentence_transformers import SentenceTransformer

try:
    from langchain_text_splitters import RecursiveCharacterTextSplitter
except ImportError:
    from langchain.text_splitter import RecursiveCharacterTextSplitter

app = FastAPI()

QDRANT_URL = os.environ.get("QDRANT_URL", "http://qdrant:6333")
MODEL_NAME = os.environ.get("EMBED_MODEL", "sentence-transformers/all-MiniLM-L6-v2")
KB_COLLECTION = os.environ.get("QDRANT_COLLECTION", "company_kb")
CHAT_COLLECTION = os.environ.get("CHAT_COLLECTION", "chat_messages")

client = QdrantClient(url=QDRANT_URL)
embedder = SentenceTransformer(MODEL_NAME)


@app.get("/health")
def health():
    return {"status": "ok"}


def embedding_dimension() -> int:
    try:
        return embedder.get_sentence_embedding_dimension()
    except Exception:
        v = embedder.encode(["dimension-probe"])[0]
        return len(v)


def ensure_collection(collection_name: str, vector_size: int):
    try:
        client.get_collection(collection_name=collection_name)
    except Exception:
        client.create_collection(
            collection_name=collection_name,
            vectors_config=VectorParams(size=vector_size, distance=Distance.COSINE),
        )


def ensure_payload_indexes():
    # Index สำหรับคีย์ที่กรองบ่อย
    for field in ["source", "doc_id", "tags"]:
        try:
            client.create_payload_index(
                collection_name=KB_COLLECTION,
                field_name=field,
                field_schema=PayloadSchemaType.KEYWORD,
            )
        except Exception:
            pass


def extract_text_from_bytes(filename, data_bytes):
    p = Path(filename)
    if p.suffix.lower() == ".pdf":
        from io import BytesIO

        from PyPDF2 import PdfReader

        reader = PdfReader(BytesIO(data_bytes))
        texts = [pg.extract_text() for pg in reader.pages]
        return "\n".join([t for t in texts if t])
    elif p.suffix.lower() == ".docx":
        from io import BytesIO

        import docx

        doc = docx.Document(BytesIO(data_bytes))
        return "\n".join([pr.text for pr in doc.paragraphs])
    else:
        try:
            return data_bytes.decode("utf-8", errors="ignore")
        except Exception:
            return ""


def make_doc_id(source_name: str) -> str:
    return hashlib.sha256(source_name.encode("utf-8")).hexdigest()


def upsert_document_text(source_name: str, text: str, tags: Optional[List[str]] = None):
    splitter = RecursiveCharacterTextSplitter(chunk_size=1000, chunk_overlap=200)
    chunks = splitter.split_text(text)
    if not chunks:
        return {"status": "no_text", "chunks": 0}

    vectors = embedder.encode(chunks)
    dim = len(vectors[0])
    ensure_collection(KB_COLLECTION, dim)
    ensure_payload_indexes()

    # ลบเวอร์ชันเดิมทั้งหมดของไฟล์นี้ด้วย doc_id
    doc_id = make_doc_id(source_name)
    try:
        client.delete(
            collection_name=KB_COLLECTION,
            points_selector=Filter(
                must=[FieldCondition(key="doc_id", match=MatchValue(value=doc_id))]
            ),
        )
    except Exception:
        pass

    ts = time.time()
    points = []
    for i, v in enumerate(vectors):
        points.append(
            {
                "id": str(uuid.uuid4()),
                "vector": v.tolist(),
                "payload": {
                    "doc_id": doc_id,
                    "source": source_name,
                    "tags": tags or [],
                    "chunk_idx": i,
                    "text": chunks[i],
                    "timestamp": ts,
                },
            }
        )
    client.upsert(collection_name=KB_COLLECTION, points=points)
    return {"status": "ok", "chunks": len(chunks), "doc_id": doc_id}


# ---------- KB ingestion: เดิม (ไฟล์เดียว) ----------
@app.post("/process")
async def process(file: UploadFile = File(...)):
    content = await file.read()
    text = extract_text_from_bytes(file.filename, content)
    if not text:
        raise HTTPException(status_code=400, detail="No text extracted")
    res = upsert_document_text(file.filename, text, [])
    return JSONResponse(res)


# ---------- Embedding API ----------
class EmbedRequest(BaseModel):
    text: str


@app.post("/embed")
def embed(req: EmbedRequest):
    vec = embedder.encode([req.text])[0].tolist()
    return {"vector": vec, "dim": len(vec), "model": MODEL_NAME}


# ---------- Chat APIs ----------
class ChatUpsertRequest(BaseModel):
    conversation_id: str
    user_id: Optional[str] = None  # แยกตาม user
    mode: str = "test"  # "test" | "train" | "prod"
    role: str  # "user" | "assistant" | "system"
    text: str
    timestamp: Optional[float] = None


@app.post("/chat/upsert")
def chat_upsert(req: ChatUpsertRequest):
    v = embedder.encode([req.text])[0]
    dim = len(v)
    ensure_collection(CHAT_COLLECTION, dim)

    ts = req.timestamp if req.timestamp is not None else time.time()
    point = {
        "id": str(uuid.uuid4()),
        "vector": v.tolist(),
        "payload": {
            "conversation_id": req.conversation_id,
            "user_id": req.user_id,
            "mode": req.mode,
            "role": req.role,
            "text": req.text,
            "timestamp": ts,
        },
    }
    client.upsert(collection_name=CHAT_COLLECTION, points=[point])
    return {"status": "ok", "id": point["id"], "timestamp": ts}


class ChatSearchRequest(BaseModel):
    conversation_id: Optional[str] = None
    user_id: Optional[str] = None
    mode: Optional[str] = None  # ถ้าอยากดึงเฉพาะ "test" หรือ "train"
    query: str
    limit: int = 5


@app.post("/chat/search")
def chat_search(req: ChatSearchRequest):
    qv = embedder.encode([req.query])[0]
    dim = len(qv)
    ensure_collection(CHAT_COLLECTION, dim)

    must = []

    if req.conversation_id:
        must.append(
            FieldCondition(
                key="conversation_id",
                match=MatchValue(value=req.conversation_id),
            )
        )

    if req.user_id:
        must.append(
            FieldCondition(
                key="user_id",
                match=MatchValue(value=req.user_id),
            )
        )

    if req.mode:
        must.append(
            FieldCondition(
                key="mode",
                match=MatchValue(value=req.mode),
            )
        )

    qfilter = Filter(must=must) if must else None

    hits = client.search(
        collection_name=CHAT_COLLECTION,
        query_vector=qv.tolist(),
        limit=req.limit,
        query_filter=qfilter,
        with_payload=True,
    )
    out = [
        {
            "id": h.id,
            "score": h.score,
            "payload": h.payload,
        }
        for h in hits
    ]
    return {"results": out}


class ChatRecentRequest(BaseModel):
    conversation_id: Optional[str] = None
    user_id: Optional[str] = None
    mode: Optional[str] = None
    limit: int = 8


@app.post("/chat/recent")
def chat_recent(req: ChatRecentRequest):
    must = []

    if req.conversation_id:
        must.append(
            FieldCondition(
                key="conversation_id",
                match=MatchValue(value=req.conversation_id),
            )
        )

    if req.user_id:
        must.append(
            FieldCondition(
                key="user_id",
                match=MatchValue(value=req.user_id),
            )
        )

    if req.mode:
        must.append(
            FieldCondition(
                key="mode",
                match=MatchValue(value=req.mode),
            )
        )

    scroll_filter = Filter(must=must) if must else None

    all_points = []
    next_page = None
    while True:
        resp = client.scroll(
            collection_name=CHAT_COLLECTION,
            scroll_filter=scroll_filter,
            limit=200,
            with_payload=True,
            with_vectors=False,
            offset=next_page,
        )
        points, next_page = resp
        all_points.extend(points)
        if not next_page or len(all_points) >= 2000:
            break

    items = []
    for p in all_points:
        payload = p.payload or {}
        ts = payload.get("timestamp", 0.0)
        items.append({"id": p.id, "payload": payload, "timestamp": ts})

    items.sort(key=lambda x: x["timestamp"])
    last_items = items[-req.limit :] if req.limit > 0 else items
    return {"results": last_items}


# ---------- KB: ingest หลายไฟล์ + ลบตาม source ----------
@app.post("/kb/ingest_many")
async def kb_ingest_many(
    files: List[UploadFile] = File(...),
    tags: Optional[str] = Form(None),  # CSV: "finance,plan,2025"
):
    tag_list = [t.strip() for t in tags.split(",")] if tags else []
    results = []
    for f in files:
        content = await f.read()
        text = extract_text_from_bytes(f.filename, content)
        if not text:
            results.append({"file": f.filename, "status": "no_text"})
            continue
        res = upsert_document_text(f.filename, text, tag_list)
        results.append({"file": f.filename, **res})
    return {"ingested": results}


class DeleteBySourceRequest(BaseModel):
    source: str


@app.post("/kb/delete_by_source")
def kb_delete_by_source(req: DeleteBySourceRequest):
    doc_id = make_doc_id(req.source)
    client.delete(
        collection_name=KB_COLLECTION,
        points_selector=Filter(
            must=[FieldCondition(key="doc_id", match=MatchValue(value=doc_id))]
        ),
    )
    return {"status": "ok", "deleted_doc_id": doc_id}
