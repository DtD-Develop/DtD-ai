# ingest_service/service.py  (REPLACE or paste into file)
import os
import re
from pathlib import Path
from typing import List, Optional
from uuid import uuid4

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from qdrant_client import QdrantClient
from qdrant_client.http import models as qm
from sentence_transformers import SentenceTransformer

# =========================
# Config
# =========================
MODEL_NAME = os.getenv("EMBED_MODEL", "intfloat/multilingual-e5-large")
EMBED_DIM = int(os.getenv("EMBED_DIM", "1024"))

QDRANT_HOST = os.getenv("QDRANT_HOST", "http://qdrant:6333")
QDRANT_API_KEY = os.getenv("QDRANT_API_KEY") or None
QDRANT_COLLECTION = os.getenv("QDRANT_COLLECTION", "dtd_kb")

app = FastAPI(title="DtD Ingest Service")

# SentenceTransformer model
model = SentenceTransformer(MODEL_NAME)

qdrant = QdrantClient(url=QDRANT_HOST, api_key=QDRANT_API_KEY)

# -------------------------
# Helpers
# -------------------------
def clean_markdown(text: str) -> str:
    '''Remove Markdown, code, images, emojis and collapse whitespace.'''
    if not text:
        return text
    text = re.sub(r"```.*?```", " ", text, flags=re.S)         # code blocks
    text = re.sub(r"`[^`]*`", " ", text)                       # inline code
    text = re.sub(r"!\[.*?\]\(.*?\)", " ", text)               # images
    text = re.sub(r"\[([^\]]+)\]\([^\)]+\)", r"\1", text)      # links -> keep text
    text = re.sub(r"#+\s*", " ", text)                         # headings
    text = re.sub(r"\*\*([^*]+)\*\*", r"\1", text)
    text = re.sub(r"\*([^*]+)\*", r"\1", text)
    text = re.sub(r"[_~>-]{1,}", " ", text)
    # remove non-basic unicode (simple emoji removal)
    text = re.sub(r"[^\x00-\x7Fก-๙\u0E00-\u0E7F0-9A-Za-z\s\.,;:\?\!\'\"\(\)\-/]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text

def extract_title(text: str) -> Optional[str]:
    '''Try extract first heading or first short line as title.'''
    if not text:
        return None
    m = re.search(r"^\s*#\s*(.+)$", text, flags=re.M)
    if m:
        return m.group(1).strip()[:200]
    for line in text.splitlines():
        s = line.strip()
        if len(s) > 10 and len(s.split()) < 20:
            return s[:200]
    return None

def sentence_splitter(text: str) -> List[str]:
    '''Split text into sentences by punctuation (simple).'''
    parts = re.split(r"(?<=[\.\!\?。\n])\s+", text)
    parts = [p.strip() for p in parts if p and len(p.strip()) > 20]
    return parts

def chunk_text(text: str, max_words=300, overlap_words=50) -> List[str]:
    '''
    Group sentences until word count reaches max_words.
    Returns list of chunks (strings).
    '''
    text = clean_markdown(text).strip()
    if not text:
        return []

    sentences = sentence_splitter(text)
    if not sentences:
        words = text.split()
        chunks = []
        for i in range(0, len(words), max_words - overlap_words):
            chunks.append(" ".join(words[i : i + max_words]))
        return chunks

    chunks = []
    current = []
    current_words = 0

    for sent in sentences:
        w = len(sent.split())
        if current_words + w <= max_words or not current:
            current.append(sent)
            current_words += w
        else:
            chunks.append(" ".join(current))
            overlap_text = " ".join(" ".join(current).split()[-overlap_words:])
            current = [overlap_text, sent] if overlap_words > 0 else [sent]
            current_words = len(overlap_text.split()) + w
    if current:
        chunks.append(" ".join(current))

    chunks = [c.strip() for c in chunks if len(c.split()) > 10]
    return chunks

def auto_tag(text: str, max_tags=5) -> List[str]:
    '''Simple frequency-based tagger for Thai/English tokens.'''
    tokens = re.findall(r"[a-zA-Zก-๙]{3,}", text.lower())
    stop = set(["this","that","with","from","การ","และ","ของ","ที่","ใน","เป็น","คือ","จะ","ได้"])
    words = [t for t in tokens if t not in stop and len(t) > 3]
    from collections import Counter
    c = Counter(words)
    tags = [w for w,_ in c.most_common(max_tags)]
    tags = [t for t in tags if 2 <= len(t) <= 40]
    return tags

# -------------------------
# Qdrant helpers
# -------------------------
def ensure_collection():
    if not qdrant.collection_exists(QDRANT_COLLECTION):
        qdrant.recreate_collection(
            collection_name=QDRANT_COLLECTION,
            vectors_config=qm.VectorParams(size=EMBED_DIM, distance=qm.Distance.COSINE),
        )

def read_file(file_path: str) -> str:
    p = Path(file_path)
    if not p.exists():
        raise FileNotFoundError(f"File not found: {file_path}")
    try:
        return p.read_text(encoding="utf-8")
    except Exception:
        return p.read_text(encoding="latin-1")

# -------------------------
# API Models
# -------------------------
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
    kb_file_id: int
    tags: Optional[List[str]] = None

class EmbedResponse(BaseModel):
    chunks_count: int

class EmbedTextRequest(BaseModel):
    text: str

class EmbedTextResponse(BaseModel):
    vector: List[float]

# -------------------------
# Endpoints
# -------------------------
@app.post("/parse", response_model=ParseResponse)
def parse(req: ParseRequest):
    try:
        text = read_file(req.file_path)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

    chunks = chunk_text(text)
    tags = auto_tag(text)
    return ParseResponse(tags=tags, chunks=[Chunk(i=i, text=c) for i,c in enumerate(chunks)])

@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest):
    try:
        text = read_file(req.file_path)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

    chunks = chunk_text(text)
    if not chunks:
        raise HTTPException(status_code=400, detail="No chunks to embed")

    ensure_collection()

    vectors = model.encode(chunks, batch_size=16, convert_to_numpy=True).tolist()
    ids = [str(uuid4()) for _ in range(len(chunks))]
    title = extract_title(text)
    payloads = [
        {
            "kb_file_id": req.kb_file_id,
            "chunk_index": i,
            "tags": req.tags or [],
            "text": chunk,
            "source": os.path.basename(req.file_path),
            "doc_id": req.kb_file_id,
            "title": title,
        }
        for i,chunk in enumerate(chunks)
    ]

    qdrant.upsert(
        collection_name=QDRANT_COLLECTION,
        points=qm.PointsList(
            points=[
                qm.PointStruct(id=ids[i], vector=vectors[i], payload=payloads[i])
                for i in range(len(ids))
            ]
        ),
    )

    return EmbedResponse(chunks_count=len(chunks))

@app.post("/embed-text", response_model=EmbedTextResponse)
def embed_text(req: EmbedTextRequest):
    text = req.text.strip()
    if not text:
        raise HTTPException(status_code=400, detail="Text required")
    vec = model.encode([clean_markdown(text)])[0]
    return EmbedTextResponse(vector=vec.tolist())
