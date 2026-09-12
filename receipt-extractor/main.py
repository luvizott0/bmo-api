import base64
from typing import Optional

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

from extractor import (
    extract_receipt_data,
    extract_text_from_image,
    extract_text_from_pdf,
)

app = FastAPI(title="BMO Receipt Extractor API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


class Base64ExtractRequest(BaseModel):
    base64: str
    mimetype: Optional[str] = "image/jpeg"
    filename: Optional[str] = None


@app.get("/health")
def health():
    return {"status": "ok", "service": "bmo-receipt-extractor"}


@app.post("/extract")
async def extract_from_base64(req: Base64ExtractRequest):
    try:
        raw_b64 = req.base64
        # Remove data:image/...;base64, prefix if present
        if "," in raw_b64:
            raw_b64 = raw_b64.split(",", 1)[1]

        file_bytes = base64.b64decode(raw_b64)
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Invalid base64 payload: {str(e)}")

    mimetype = (req.mimetype or "").lower()
    raw_text = ""

    if "pdf" in mimetype or (req.filename and req.filename.lower().endswith(".pdf")):
        raw_text = extract_text_from_pdf(file_bytes)
        # If PDF was scanned and has no digital text, fallback to image OCR
        if not raw_text.strip():
            raw_text = extract_text_from_image(file_bytes)
    else:
        raw_text = extract_text_from_image(file_bytes)

    result = extract_receipt_data(raw_text)
    return result


@app.post("/extract-file")
async def extract_from_file(file: UploadFile = File(...)):
    try:
        file_bytes = await file.read()
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Failed reading file: {str(e)}")

    mimetype = (file.content_type or "").lower()
    filename = file.filename or ""
    raw_text = ""

    if "pdf" in mimetype or filename.lower().endswith(".pdf"):
        raw_text = extract_text_from_pdf(file_bytes)
        if not raw_text.strip():
            raw_text = extract_text_from_image(file_bytes)
    else:
        raw_text = extract_text_from_image(file_bytes)

    result = extract_receipt_data(raw_text)
    return result
