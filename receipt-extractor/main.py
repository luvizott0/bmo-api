import base64
from typing import Optional

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

from extractor import (
    extract_invoice_data,
    extract_receipt_data,
    extract_text_from_image,
    extract_text_from_pdf,
    fetch_imap_invoices,
)

app = FastAPI(title="BMO Receipt Extractor API", version="1.1.0")

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


class InvoiceExtractRequest(BaseModel):
    base64: Optional[str] = None
    mimetype: Optional[str] = "application/pdf"
    filename: Optional[str] = None
    text: Optional[str] = ""
    subject: Optional[str] = ""
    sender: Optional[str] = ""


class ImapFetchRequest(BaseModel):
    email_address: str
    password: Optional[str] = None
    access_token: Optional[str] = None
    host: str = "outlook.office365.com"
    port: int = 993
    folder: str = "INBOX"
    max_messages: int = 400


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


@app.post("/extract-invoice")
async def extract_invoice_endpoint(req: InvoiceExtractRequest):
    parts = []
    if req.text:
        parts.append(req.text)

    if req.base64:
        try:
            raw_b64 = req.base64.split(",", 1)[1] if "," in req.base64 else req.base64
            file_bytes = base64.b64decode(raw_b64)
            mimetype = (req.mimetype or "").lower()
            if "pdf" in mimetype or (req.filename and req.filename.lower().endswith(".pdf")):
                pdf_text = extract_text_from_pdf(file_bytes)
                if not pdf_text.strip():
                    pdf_text = extract_text_from_image(file_bytes)
                parts.append(pdf_text)
            else:
                parts.append(extract_text_from_image(file_bytes))
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"Invalid base64 payload: {str(e)}")

    combined_text = "\n\n".join(p for p in parts if p)
    return extract_invoice_data(combined_text, subject=req.subject or "", sender=req.sender or "")


@app.post("/fetch-imap-invoices")
async def fetch_imap_invoices_endpoint(req: ImapFetchRequest):
    return fetch_imap_invoices(
        email_address=req.email_address,
        password=req.password,
        access_token=req.access_token,
        host=req.host,
        port=req.port,
        folder=req.folder,
        max_messages=req.max_messages,
    )

