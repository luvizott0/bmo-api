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

    # Format: DD/mon/YYYY or DD-mon-YYYY (e.g. 07/set/2026 or 07-setembro-2026)
    m_mon = re.search(r"(\d{1,2})[/.-]([a-z]{3}\w*)[/.-](\d{4})", date_str, re.IGNORECASE)
    if m_mon:
        d, mon_str, y = m_mon.groups()
        mon = MONTH_NAMES.get(mon_str.lower()[:3])
        if mon:
            try:
                return datetime(int(y), mon, int(d)).strftime("%Y-%m-%d")
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
        r"(\d{1,2}[/.-][a-z]{3}\w*[/.-]\d{4})",
        r"(?:data|data\s+da\s+transa[cç][aã]o|hor[aá]rio|realizado\s+em)[\s:]*([0-9]{1,2}[/.-][0-9]{1,2}[/.-][0-9]{4})",
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

    # If payment_date was not found in raw text, extract from standard Pix EndToEnd ID
    if not payment_date and transaction_id:
        m_e2e = re.search(r"^[ED]\d{8}(\d{4})(\d{2})(\d{2})", transaction_id)
        if m_e2e:
            y, m, d = m_e2e.groups()
            try:
                payment_date = datetime(int(y), int(m), int(d)).strftime("%Y-%m-%d")
            except ValueError:
                pass

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


def strip_html_tags(html: str) -> str:
    """Convert HTML content into plain readable text."""
    if not html:
        return ""
    # Remove script and style blocks
    cleaned = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", html, flags=re.DOTALL | re.IGNORECASE)
    # Replace br, p, div, tr, li with newlines
    cleaned = re.sub(r"</?(?:br|p|div|tr|li|h[1-6]|td|th)[^>]*>", "\n", cleaned, flags=re.IGNORECASE)
    # Remove all remaining tags
    cleaned = re.sub(r"<[^>]+>", " ", cleaned)
    # Unescape common HTML entities
    import html as html_lib
    cleaned = html_lib.unescape(cleaned)
    # Collapse excessive whitespace
    lines = [re.sub(r"[ \t]+", " ", line).strip() for line in cleaned.split("\n")]
    return "\n".join(line for line in lines if line)


def extract_invoice_data(text: str, subject: str = "", sender: str = "") -> Dict[str, Any]:
    """Extract utility bill / invoice details (CPFL, Alares, etc.) from combined email + PDF text."""
    combined_header = f"{sender}\n{subject}\n{text}"
    lower_all = combined_header.lower()

    provider = None
    bill_type = None
    if "cpfl" in lower_all:
        provider = "CPFL"
        bill_type = "luz"
    elif "alares" in lower_all:
        provider = "Alares"
        bill_type = "internet"
    elif any(k in lower_all for k in ("energia", "eletric", "elétric", "kwh", "instalacao", "instalação")):
        provider = "Energia"
        bill_type = "luz"
    elif any(k in lower_all for k in ("internet", "fibra", "banda larga", "telecom")):
        provider = "Internet"
        bill_type = "internet"

    # 1. Extract Pix Copy and Paste (EMV BRCode)
    # Sometimes PDF text breaks Pix code across lines, so we also test a whitespace-collapsed version
    pix_code = None
    pix_Match_direct = re.search(
        r"(000201[0-9A-Za-z\.\-\/\@\s\*]{30,350}?6304[0-9A-Fa-f]{4})",
        text
    )
    if pix_Match_direct:
        candidate_pix = re.sub(r"[\r\n]+", "", pix_Match_direct.group(1)).strip()
        if "br.gov.bcb.pix" in candidate_pix.lower():
            pix_code = candidate_pix

    if not pix_code:
        no_newline_text = re.sub(r"[\r\n]+", "", text)
        pix_match_joined = re.search(
            r"(000201[0-9A-Za-z\.\-\/\@\s\*]{30,350}?6304[0-9A-Fa-f]{4})",
            no_newline_text
        )
        if pix_match_joined and "br.gov.bcb.pix" in pix_match_joined.group(1).lower():
            pix_code = pix_match_joined.group(1).strip()

    # 2. Extract Barcode / Linha Digitável
    barcode = None
    # A. Utility bill (Concessionária like CPFL): starts with 8, 4 blocks of 11-12 digits
    concessionaria_rx = r"\b(8\d{10}[-\s]?\d\s+\d{11}[-\s]?\d\s+\d{11}[-\s]?\d\s+\d{11}[-\s]?\d)\b"
    m_conc = re.search(concessionaria_rx, text)
    if m_conc:
        barcode = re.sub(r"\s+", " ", m_conc.group(1)).strip()
    else:
        # Continuous 44 or 48 digits starting with 83 or 84
        m_conc_cont = re.search(r"\b(8[34]\d{42,46})\b", re.sub(r"[-\s\.]", "", text))
        if m_conc_cont:
            barcode = m_conc_cont.group(1)

    # B. Standard bank boleto (47 digits with dots/spaces, e.g. Alares)
    if not barcode:
        boleto_rx = r"\b(\d{5}\.?\d{5}\s+\d{5}\.?\d{6}\s+\d{5}\.?\d{6}\s+\d\s+\d{14})\b"
        m_bol = re.search(boleto_rx, text)
        if m_bol:
            barcode = re.sub(r"\s+", " ", m_bol.group(1)).strip()

    # 3. Extract Due Date (Vencimento)
    due_date = None
    due_regexes = [
        r"(?:data\s+de\s+vencimento|vencimento|vence\s+em|pagar\s+at[eé])[\s:]*(\d{2}[/.-]\d{2}[/.-]\d{4})",
        r"vencimento[\s\S]{1,40}?(\d{2}/\d{2}/\d{4})",
    ]
    for rx in due_regexes:
        m = re.search(rx, combined_header, re.IGNORECASE)
        if m:
            parsed_d = parse_date(m.group(1))
            if parsed_d:
                due_date = parsed_d
                break

    if not due_date:
        # Look for all DD/MM/YYYY dates and pick the most plausible due date
        all_dates = re.findall(r"\b(\d{2}/\d{2}/\d{4})\b", combined_header)
        for d_str in all_dates:
            parsed_d = parse_date(d_str)
            if parsed_d and parsed_d >= "2025-01-01":
                due_date = parsed_d
                break

    # 4. Extract Invoice Amount (Valor Total a Pagar)
    amount = None
    amount_regexes = [
        r"(?:total\s+a\s+pagar|valor\s+a\s+pagar|valor\s+da\s+fatura|valor\s+do\s+documento|valor\s+cobrado|valor\s+total)[\s:R\$]*([0-9]{1,3}(?:\.[0-9]{3})*,\s*[0-9]{2})",
        r"total\s+a\s+pagar[\s\S]{1,50}?r\$\s*([0-9]{1,3}(?:\.[0-9]{3})*,\s*[0-9]{2})",
        r"valor[\s:]*r\$\s*([0-9]{1,3}(?:\.[0-9]{3})*,\s*[0-9]{2})",
        r"r\$\s*([0-9]{1,3}(?:\.[0-9]{3})*,\s*[0-9]{2})",
    ]
    for rx in amount_regexes:
        matches = re.findall(rx, combined_header, re.IGNORECASE)
        for match_str in matches:
            parsed = parse_brazilian_amount(match_str)
            if parsed and parsed >= 10.0:  # Utility bills are typically >= R$ 10,00
                amount = parsed
                break
        if amount:
            break

    reference_month = due_date[:7] if due_date else datetime.now().strftime("%Y-%m")

    return {
        "success": bool(amount and provider),
        "provider": provider,
        "bill_type": bill_type,
        "amount": amount,
        "due_date": due_date,
        "reference_month": reference_month,
        "pix_code": pix_code,
        "barcode": barcode,
        "subject": subject,
        "sender": sender,
    }


def _decode_mime_header(raw_header: Optional[str]) -> str:
    if not raw_header:
        return ""
    from email.header import decode_header
    parts = decode_header(raw_header)
    decoded = []
    for content, charset in parts:
        if isinstance(content, bytes):
            try:
                decoded.append(content.decode(charset or "utf-8", errors="replace"))
            except Exception:
                decoded.append(content.decode("latin-1", errors="replace"))
        else:
            decoded.append(str(content))
    return "".join(decoded).strip()


def fetch_imap_invoices(
    email_address: str,
    password: Optional[str] = None,
    access_token: Optional[str] = None,
    host: str = "outlook.office365.com",
    port: int = 993,
    folder: str = "INBOX",
    max_messages: int = 400,
) -> Dict[str, Any]:
    """Connect to IMAP via XOAUTH2 or Basic Auth, scan recent emails for CPFL/Alares invoices, and extract data."""
    import email
    import imaplib

    mail = imaplib.IMAP4_SSL(host, port)
    try:
        if access_token:
            auth_string = f"user={email_address}\x01auth=Bearer {access_token}\x01\x01".encode("utf-8")
            mail.authenticate("XOAUTH2", lambda _: auth_string)
        elif password:
            mail.login(email_address, password)
        else:
            return {"success": False, "error": "Neither access_token nor password provided.", "invoices": []}
    except Exception as e:
        return {"success": False, "error": f"IMAP authentication failed: {str(e)}", "invoices": []}

    try:
        status, _ = mail.select(folder, readonly=True)
        if status != "OK":
            return {"success": False, "error": f"Could not select folder {folder}", "invoices": []}

        status, data = mail.search(None, "ALL")
        if status != "OK" or not data or not data[0]:
            return {"success": True, "invoices": [], "scanned": 0}

        msg_ids = data[0].split()
        recent_ids = msg_ids[-max_messages:] if len(msg_ids) > max_messages else msg_ids
        recent_ids = list(reversed(recent_ids))  # Newest first

        invoices = []
        scanned = len(recent_ids)
        target_keywords = ("cpfl", "alares", "energia", "elétric", "eletric", "fatura", "conta", "boleto", "segunda via", "nota fiscal")

        # Batch fetch headers in chunks of 100 for high speed
        candidate_msg_ids = []
        batch_size = 100
        for i in range(0, len(recent_ids), batch_size):
            batch = recent_ids[i : i + batch_size]
            batch_set = b",".join(batch)
            h_status, h_data = mail.fetch(batch_set, "(BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE MESSAGE-ID)])")
            if h_status != "OK" or not h_data:
                continue

            for item in h_data:
                if not isinstance(item, tuple) or len(item) < 2:
                    continue
                # Extract msg_id from response line e.g. b'1234 (BODY[HEADER.FIELDS ...'
                envelope_line = item[0].decode("utf-8", errors="ignore")
                m_id_match = re.match(r"^\s*(\d+)", envelope_line)
                if not m_id_match:
                    continue
                cur_msg_id = m_id_match.group(1).encode("utf-8")

                raw_header_bytes = item[1]
                header_msg = email.message_from_bytes(raw_header_bytes)
                subject = _decode_mime_header(header_msg.get("Subject"))
                sender = _decode_mime_header(header_msg.get("From"))
                message_id_hdr = _decode_mime_header(header_msg.get("Message-ID")) or f"imap-{cur_msg_id.decode()}"
                date_hdr = _decode_mime_header(header_msg.get("Date"))

                combined_meta = f"{sender} {subject}".lower()
                if any(kw in combined_meta for kw in target_keywords):
                    candidate_msg_ids.append((cur_msg_id, subject, sender, message_id_hdr, date_hdr, combined_meta))

        for msg_id, subject, sender, message_id_hdr, date_hdr, combined_meta in candidate_msg_ids:
            # Fetch full message only for candidates
            f_status, f_data = mail.fetch(msg_id, "(BODY.PEEK[])")
            if f_status != "OK" or not f_data or not f_data[0]:
                continue

            raw_email_bytes = f_data[0][1] if isinstance(f_data[0], tuple) else b""
            full_msg = email.message_from_bytes(raw_email_bytes)

            body_texts = []
            pdf_texts = []

            for part in full_msg.walk():
                content_type = (part.get_content_type() or "").lower()
                content_disp = str(part.get("Content-Disposition") or "").lower()
                filename = _decode_mime_header(part.get_filename())

                if content_type == " application/pdf" or content_type == "application/pdf" or filename.lower().endswith(".pdf"):
                    payload = part.get_payload(decode=True)
                    if payload:
                        pdf_text = extract_text_from_pdf(payload)
                        if not pdf_text.strip():
                            pdf_text = extract_text_from_image(payload)
                        if pdf_text.strip():
                            pdf_texts.append(pdf_text)
                elif content_type == "text/plain" and "attachment" not in content_disp:
                    payload = part.get_payload(decode=True)
                    if payload:
                        charset = part.get_content_charset() or "utf-8"
                        body_texts.append(payload.decode(charset, errors="replace"))
                elif content_type == "text/html" and "attachment" not in content_disp:
                    payload = part.get_payload(decode=True)
                    if payload:
                        charset = part.get_content_charset() or "utf-8"
                        html_str = payload.decode(charset, errors="replace")
                        body_texts.append(strip_html_tags(html_str))

            combined_text = "\n\n".join(body_texts + pdf_texts)
            if not any(p in f"{combined_meta} {combined_text.lower()}" for p in ("cpfl", "alares")):
                continue

            extracted = extract_invoice_data(combined_text, subject=subject, sender=sender)
            if extracted.get("success") and extracted.get("provider") in ("CPFL", "Alares"):
                extracted["external_message_id"] = message_id_hdr
                extracted["email_date"] = date_hdr
                extracted["has_pdf"] = len(pdf_texts) > 0
                invoices.append(extracted)

        return {
            "success": True,
            "scanned": scanned,
            "invoices": invoices,
        }
    except Exception as e:
        return {"success": False, "error": f"IMAP fetch error: {str(e)}", "invoices": []}
    finally:
        try:
            mail.logout()
        except Exception:
            pass


