import mimetypes
import os
from pathlib import Path
from typing import List, Optional

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from qdrant_client import QdrantClient
from qdrant_client.http import models as qm
from sentence_transformers import SentenceTransformer

# =========================
# Config
# =========================

MODEL_NAME = "intfloat/multilingual-e5-large"
EMBED_DIM = 1024

QDRANT_HOST = os.getenv("QDRANT_HOST", "http://qdrant:6333")
QDRANT_API_KEY = os.getenv("QDRANT_API_KEY") or None
QDRANT_COLLECTION = os.getenv("QDRANT_COLLECTION", "dtd_kb")

app = FastAPI(title="DtD Ingest Service")

model = SentenceTransformer(MODEL_NAME)

qdrant = QdrantClient(
    url=QDRANT_HOST,
    api_key=QDRANT_API_KEY,
)


# =========================
# Pydantic models
# =========================


class ParseRequest(BaseModel):
    file_path: str


class Chunk(BaseModel):
    i: int
    text: str


class ParseResponse(BaseModel):
    tags: List[str]
    chunks: List[Chunk]


class EmbedRequest(BaseModel):
    file_path: str
    tags: Optional[List[str]] = None
    kb_file_id: Optional[int] = None


class EmbedResponse(BaseModel):
    chunks_count: int


class EmbedTextRequest(BaseModel):
    text: str


class EmbedTextResponse(BaseModel):
    vector: List[float]


# =========================
# Helpers
# =========================


def ensure_collection():
    if not qdrant.collection_exists(QDRANT_COLLECTION):
        qdrant.recreate_collection(
            collection_name=QDRANT_COLLECTION,
            vectors_config=qm.VectorParams(
                size=EMBED_DIM,
                distance=qm.Distance.COSINE,
            ),
        )


def read_file(file_path: str) -> str:
    path = Path(file_path)
    if not path.exists():
        raise FileNotFoundError(f"File not found: {file_path}")

    ext = path.suffix.lower()

    if ext in [".txt", ".md", ".json"]:
        return path.read_text(encoding="utf-8", errors="ignore")

    if ext in [".pdf"]:
        try:
            import fitz

            lines = []
            with fitz.open(file_path) as doc:
                for page in doc:
                    lines.append(page.get_text())
            return "\n".join(lines)
        except Exception as e:
            raise RuntimeError(f"PDF parse error: {e}")

    if ext in [".docx"]:
        try:
            from docx import Document

            doc = Document(file_path)
            return "\n".join(p.text for p in doc.paragraphs)
        except Exception as e:
            raise RuntimeError(f"DOCX parse error: {e}")

    if ext in [".pptx"]:
        try:
            from pptx import Presentation

            prs = Presentation(file_path)
            lines = []
            for i, slide in enumerate(prs.slides):
                lines.append(f"# Slide {i + 1}")
                for shape in slide.shapes:
                    if hasattr(shape, "text") and shape.text.strip():
                        lines.append(shape.text.strip())
                lines.append("")
            return "\n".join(lines)
        except Exception as e:
            raise RuntimeError(f"PPTX parse error: {e}")

    if ext in [".png", ".jpg", ".jpeg"]:
        try:
            import pytesseract
            from PIL import Image

            img = Image.open(file_path)
            return pytesseract.image_to_string(img)
        except Exception as e:
            raise RuntimeError(f"OCR parse error: {e}")

    return path.read_text(encoding="utf-8", errors="ignore")


def chunk_text(text: str, max_chars=1000, overlap=200) -> List[str]:
    text = text.strip()
    if not text:
        return []

    chunks = []
    start = 0
    L = len(text)

    while start < L:
        end = min(start + max_chars, L)
        chunk = text[start:end]
        newline = chunk.rfind("\n")
        if newline != -1 and end != L:
            end = start + newline
            chunk = text[start:end]

        chunks.append(chunk.strip())
        start = max(0, end - overlap)
        if end == L:
            break

    return [c for c in chunks if c]


def auto_tag(text: str, max_tags=5) -> List[str]:
    import re
    from collections import Counter

    tokens = re.findall(r"[a-zA-Zก-๙]{3,}", text.lower())
    stop = set(["this", "that", "with", "from", "การ", "และ", "ของ", "ที่", "ใน", "เป็น"])
    words = [t for t in tokens if t not in stop and len(t) > 4]
    return [w for w, _ in Counter(words).most_common(max_tags)]


# =========================
# Endpoints
# =========================


@app.post("/parse", response_model=ParseResponse)
def parse(req: ParseRequest):
    try:
        text = read_file(req.file_path)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

    chunks = chunk_text(text)
    tags = auto_tag(text)
    return ParseResponse(
        tags=tags, chunks=[Chunk(i=i, text=c) for i, c in enumerate(chunks)]
    )


@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest):
    try:
        text = read_file(req.file_path)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

    chunks = chunk_text(text)
    if not chunks:
        raise HTTPException(status_code=400, detail="No chunks")

    ensure_collection()

    vectors = model.encode(chunks, batch_size=16).tolist()
    ids = [f"{req.kb_file_id}_{i}" for i in range(len(chunks))]

    qdrant.upsert(
        collection_name=QDRANT_COLLECTION,
        points=qm.Batch(
            ids=ids,
            vectors=vectors,
            payloads=[
                {
                    "kb_file_id": req.kb_file_id,
                    "chunk_index": i,
                    "tags": req.tags or [],
                    "text": chunk,
                    "source": os.path.basename(req.file_path),
                    "doc_id": req.kb_file_id,
                }
                for i, chunk in enumerate(chunks)
            ],
        ),
    )

    return EmbedResponse(chunks_count=len(chunks))


@app.post("/embed-text", response_model=EmbedTextResponse)
def embed_text(req: EmbedTextRequest):
    text = req.text.strip()
    if not text:
        raise HTTPException(status_code=400, detail="Text required")

    vector = model.encode([text])[0]
    return EmbedTextResponse(vector=vector.tolist())
