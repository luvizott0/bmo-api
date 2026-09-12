import io
import re
from datetime import datetime
from typing import Any, Dict, Optional

try:
    from PIL import Image
except ImportError:
    Image = None

try:
    from pypdf import PdfReader
except ImportError:
    PdfReader = None

try:
    import pytesseract
except ImportError:
    pytesseract = None

MONTH_NAMES = {
    "jan": 1,
    "fev": 2,
    "mar": 3,
    "abr": 4,
    "mai": 5,
    "jun": 6,
    "jul": 7,
    "ago": 8,
    "set": 9,
    "out": 10,
    "nov": 11,
    "dez": 12,
}


def extract_text_from_pdf(pdf_bytes: bytes) -> str:
    """Extract digital text from PDF bytes."""
    text_chunks = []
    try:
        reader = PdfReader(io.BytesIO(pdf_bytes))
        for page in reader.pages:
            t = page.extract_text()
            if t:
                text_chunks.append(t)
    except Exception as e:
        print(f"Error reading PDF: {e}")
    return "\n".join(text_chunks)


def extract_text_from_image(image_bytes: bytes) -> str:
    """Extract text from image using Tesseract OCR."""
    if not pytesseract:
        return ""
    try:
        img = Image.open(io.BytesIO(image_bytes))
        # Convert to RGB if needed
        if img.mode not in ("RGB", "L"):
            img = img.convert("RGB")
        text = pytesseract.image_to_string(img, lang="por")
        return text
    except Exception as e:
        print(f"Error running OCR on image: {e}")
        return ""


def parse_brazilian_amount(amount_str: str) -> Optional[float]:
    """Parse Brazilian currency string into float. e.g. 1.250,50 -> 1250.50"""
    try:
        cleaned = amount_str.strip().replace(" ", "").replace("R$", "")
        if "," in cleaned and "." in cleaned:
            # 1.250,50 format
            cleaned = cleaned.replace(".", "").replace(",", ".")
        elif "," in cleaned:
            # 250,50 format
            cleaned = cleaned.replace(",", ".")
        return float(cleaned)
    except Exception:
        return None


def parse_date(date_str: str) -> Optional[str]:
    """Normalize various date formats into YYYY-MM-DD."""
    date_str = date_str.strip()

    # Format: DD/MM/YYYY or DD-MM-YYYY
    m1 = re.search(r"(\d{2})[/.-](\d{2})[/.-](\d{4})", date_str)
    if m1:
        d, m, y = m1.groups()
        try:
            return datetime(int(y), int(m), int(d)).strftime("%Y-%m-%d")
        except ValueError:
            pass

    # Format: YYYY-MM-DD
    m2 = re.search(r"(\d{4})-(\d{2})-(\d{2})", date_str)
    if m2:
        y, m, d = m2.groups()
        try:
            return datetime(int(y), int(m), int(d)).strftime("%Y-%m-%d")
        except ValueError:
            pass

    # Written month: 11 de setembro de 2026 or 11 set 2026
    m3 = re.search(r"(\d{1,2})\s+(?:de\s+)?([a-z]{3})\w*\s+(?:de\s+)?(\d{4})", date_str, re.IGNORECASE)
    if m3:
        d, mon_str, y = m3.groups()
        mon = MONTH_NAMES.get(mon_str.lower()[:3])
        if mon:
            try:
                return datetime(int(y), mon, int(d)).strftime("%Y-%m-%d")
            except ValueError:
                pass

    return None


def extract_receipt_data(text: str) -> Dict[str, Any]:
    """Extract structured Pix/transfer details from raw text."""
    lines = [line.strip() for line in text.split("\n") if line.strip()]
    full_text = "\n".join(lines)

    amount = None
    payment_date = None
    transaction_id = None
    payer_name = None
    recipient_name = None

    # 1. Extract Amount (Valor)
    # Priority 1: line with 'valor' or 'total'
    val_regexes = [
        r"(?:valor|total|transfer[ií]do|pago|quantia)[\s:a-z]*r?\$?\s*([0-9]{1,3}(?:\.[0-9]{3})*,\s*[0-9]{2})",
        r"r\$\s*([0-9]{1,3}(?:\.[0-9]{3})*,\s*[0-9]{2})",
        r"r\$\s*([0-9]+\.[0-9]{2})\b",
        r"(?:valor|total)[\s:]*([0-9]+,[0-9]{2})",
    ]
    for rx in val_regexes:
        m = re.search(rx, full_text, re.IGNORECASE)
        if m:
            parsed = parse_brazilian_amount(m.group(1))
            if parsed and parsed > 0:
                amount = parsed
                break

    # 2. Extract Date (Data)
    date_regexes = [
        r"(?:data|data\s+da\s+transa[cç][aã]o|hor[aá]rio|realizado\s+em)[\s:]*([0-9]{2}/[0-9]{2}/[0-9]{4})",
        r"(\d{2}/\d{2}/\d{4})",
        r"(\d{1,2}\s+(?:de\s+)?[a-z]{3}\w*\s+(?:de\s+)?\d{4})",
        r"(\d{4}-\d{2}-\d{2})",
    ]
    for rx in date_regexes:
        m = re.search(rx, full_text, re.IGNORECASE)
        if m:
            parsed_d = parse_date(m.group(1))
            if parsed_d:
                payment_date = parsed_d
                break

    # 3. Extract Pix EndToEnd ID or Transaction ID
    # Pix EndToEnd format: starts with E or D followed by 31 alphanumeric characters
    e2e_match = re.search(r"\b([ED][0-9A-Za-z]{31})\b", full_text)
    if e2e_match:
        transaction_id = e2e_match.group(1)
    else:
        # Fallback to ID da transação or Autenticação
        id_regexes = [
            r"(?:id\s+da\s+transa[cç][aã]o|id\s+transa[cç][aã]o|id)[\s:]*([A-Za-z0-9\-]+)",
            r"(?:autentica[cç][aã]o|c[oó]digo\s+de\s+autentica[cç][aã]o)[\s:]*([A-Za-z0-9\-]+)",
            r"(?:protocolo|controle)[\s:]*([A-Za-z0-9\-]+)",
        ]
        for rx in id_regexes:
            m = re.search(rx, full_text, re.IGNORECASE)
            if m and len(m.group(1)) >= 8:
                transaction_id = m.group(1)
                break

    # 4. Extract Payer (Origem / De / Pagador)
    payer_regexes = [
        r"^[ \t]*(?:origem|pagador|nome\s+do\s+pagador|quem\s+pagou)[\s:]+([A-Za-zÀ-ÖØ-öø-ÿ\s]{3,50})",
        r"^[ \t]*de[\s:]+([A-Za-zÀ-ÖØ-öø-ÿ\s]{3,50})",
    ]
    stopwords = ("banco", "conta", "agencia", "agência", "pix", "comprovante", "transferência", "transferencia", "pagamento")
    for rx in payer_regexes:
        m = re.search(rx, full_text, re.IGNORECASE | re.MULTILINE)
        if m:
            candidate = m.group(1).split("\n")[0].strip()
            if len(candidate) > 2 and not any(candidate.lower().startswith(sw) for sw in stopwords):
                payer_name = candidate
                break

    # 5. Extract Recipient (Destino / Para / Recebedor / Favorecido)
    recipient_regexes = [
        r"^[ \t]*(?:destino|recebedor|favorecido|nome\s+do\s+recebedor|quem\s+recebeu|benefici[aá]rio)[\s:]+([A-Za-zÀ-ÖØ-öø-ÿ\s]{3,50})",
        r"^[ \t]*para[\s:]+([A-Za-zÀ-ÖØ-öø-ÿ\s]{3,50})",
    ]
    for rx in recipient_regexes:
        m = re.search(rx, full_text, re.IGNORECASE | re.MULTILINE)
        if m:
            candidate = m.group(1).split("\n")[0].strip()
            if len(candidate) > 2 and not any(candidate.lower().startswith(sw) for sw in stopwords):
                recipient_name = candidate
                break

    is_valid = bool(amount and (payment_date or transaction_id))

    # Optional AI fallback if local extraction was incomplete and an API key is provided
    if not is_valid:
        ai_data = try_extract_with_ai(full_text)
        if ai_data and ai_data.get("amount"):
            return ai_data

    return {
        "success": is_valid,
        "amount": amount,
        "payment_date": payment_date,
        "transaction_id": transaction_id,
        "payer_name": payer_name,
        "recipient_name": recipient_name,
        "raw_text_length": len(full_text),
    }


def try_extract_with_ai(text: str) -> Optional[Dict[str, Any]]:
    """Optional fallback using Gemini or OpenAI if text is ambiguous."""
    import json
    import os
    import urllib.request

    gemini_key = os.environ.get("GEMINI_API_KEY")
    if not gemini_key or len(text) < 10:
        return None

    try:
        url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={gemini_key}"
        prompt = (
            "Extraia os dados deste comprovante de pagamento brasileiro em JSON estrito com as chaves: "
            "amount (float), payment_date (YYYY-MM-DD), transaction_id (string ou null), "
            "payer_name (string ou null), recipient_name (string ou null), success (true/false).\n\n"
            f"Texto do comprovante:\n{text[:3000]}"
        )
        payload = {
            "contents": [{"parts": [{"text": prompt}]}],
            "generationConfig": {"response_mime_type": "application/json"},
        }
        req = urllib.request.Request(
            url,
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            content = data["candidates"][0]["content"]["parts"][0]["text"]
            parsed = json.loads(content)
            parsed["raw_text_length"] = len(text)
            return parsed
    except Exception as e:
        print(f"AI extraction fallback error: {e}")
        return None
