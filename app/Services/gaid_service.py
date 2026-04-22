"""
GAID Guardian — AI Microservice
================================
FastAPI service for GAID 2025 compliance intelligence.

Endpoints:
  POST /gaid/obligations      — RAG: extract obligations from GAID 2025 knowledge base
  POST /gaid/analyse-document — PDF extraction + Claude clause mapping
  POST /gaid/gap-analysis     — Compare obligations vs. evidence, score compliance
  POST /gaid/generate-car     — Generate structured CAR draft (NDPC template)
  GET  /health

Install:
  pip install fastapi uvicorn anthropic spacy pymupdf langchain langchain-anthropic
              langchain-community chromadb sentence-transformers pydantic python-dotenv reportlab

Run:
  uvicorn gaid_service:app --host 0.0.0.0 --port 8001 --reload
"""

import os, json, time, hashlib
from pathlib import Path
from dotenv import load_dotenv

import fitz                    # PyMuPDF
import anthropic
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Optional

load_dotenv()

app    = FastAPI(title="GAID Guardian AI Service", version="1.0.0")
claude = anthropic.Anthropic(api_key=os.getenv("ANTHROPIC_API_KEY"))
MODEL  = "claude-sonnet-4-20250514"

# ── Vector store (ChromaDB) — loaded once at startup ──────────────────────────
# Populated by the separate ingestion script (ingest_gaid.py)
try:
    import chromadb
    from langchain_community.vectorstores import Chroma
    from langchain_anthropic import ChatAnthropic
    from langchain.text_splitter import RecursiveCharacterTextSplitter

    _chroma_client = chromadb.PersistentClient(path="./gaid_vectorstore")
    VECTOR_DB_READY = True
except Exception as e:
    print(f"[WARN] ChromaDB not ready: {e} — falling back to prompt-only mode")
    VECTOR_DB_READY = False


# ══════════════════════════════════════════════════════════════════════════════
# Pydantic schemas
# ══════════════════════════════════════════════════════════════════════════════

class ObligationsRequest(BaseModel):
    submission_id: int
    tier:          str
    sector:        str
    parastatal:    str
    uses_ai:       bool
    sensitive:     bool
    transfers:     bool
    opa_decision:  dict

class AnalyseDocumentRequest(BaseModel):
    document_id:   int
    submission_id: int
    file_path:     str
    document_type: str
    parastatal:    str
    tier:          str

class GapItem(BaseModel):
    id:       int
    title:    str
    category: str
    clause:   str

class DocItem(BaseModel):
    id:       int
    type:     str
    analysis: Optional[dict] = None
    clauses:  Optional[list] = None

class GapAnalysisRequest(BaseModel):
    submission_id: int
    tier:          str
    obligations:   list[GapItem]
    documents:     list[DocItem]

class CarRequest(BaseModel):
    submission_id:    int
    reference_code:   str
    organisation:     str
    parastatal:       str
    sector:           str
    tier:             str
    car_filing_fee:   float
    filing_deadline:  Optional[str]
    obligations:      list
    gaps:             list
    compliance_score: Optional[float]


# ══════════════════════════════════════════════════════════════════════════════
# 1. /gaid/obligations — RAG obligation extraction
# ══════════════════════════════════════════════════════════════════════════════

@app.post("/gaid/obligations")
async def extract_obligations(req: ObligationsRequest) -> dict:
    """
    Uses the GAID 2025 vector store (or prompt-only fallback) to extract
    personalised obligations for this org's tier + sector profile.
    """
    context = _rag_query(
        f"DCPMI tier {req.tier} obligations for {req.sector} sector "
        f"{'with AI systems ' if req.uses_ai else ''}"
        f"{'processing sensitive data ' if req.sensitive else ''}"
        f"{'transferring data outside Nigeria' if req.transfers else ''}",
        top_k=10
    ) if VECTOR_DB_READY else _gaid_fallback_context(req.tier)

    prompt = f"""
You are a Nigerian data protection compliance expert. Based on GAID 2025 (Nigeria's General AI
and Data Protection Implementation Directive 2025), extract the specific obligations for this organisation:

PROFILE:
- DCPMI Tier: {req.tier}
- Sector: {req.sector}
- Parastatal: {req.parastatal}
- Uses AI: {req.uses_ai}
- Processes sensitive data: {req.sensitive}
- Transfers data outside Nigeria: {req.transfers}
- OPA classification: {json.dumps(req.opa_decision, indent=2)}

GAID 2025 CONTEXT:
{context}

Return ONLY a JSON object:
{{
  "obligations": [
    {{
      "clause_reference": "GAID 2025 §X.X.X",
      "title": "Obligation title",
      "description": "Full regulatory description",
      "plain_language": "What this means in plain English for a Lagos startup",
      "deadline": "YYYY-MM-DD or null",
      "penalty": "₦X,XXX,XXX or X% revenue or null",
      "category": "dpo|dpia|car|breach|consent|retention|registration|transfer|other",
      "mandatory": true
    }}
  ]
}}
Return ONLY valid JSON. No preamble.
"""

    response = claude.messages.create(model=MODEL, max_tokens=3000,
        messages=[{"role": "user", "content": prompt}])
    return _parse_json(response.content[0].text)


# ══════════════════════════════════════════════════════════════════════════════
# 2. /gaid/analyse-document — PDF extraction + clause mapping
# ══════════════════════════════════════════════════════════════════════════════

@app.post("/gaid/analyse-document")
async def analyse_document(req: AnalyseDocumentRequest) -> dict:
    """
    Extracts text from uploaded policy PDF, then Claude maps it to GAID 2025 clauses.
    """
    extracted = _extract_pdf(req.file_path)
    if not extracted:
        raise HTTPException(400, "Could not extract text from document.")

    prompt = f"""
You are a Nigerian data protection compliance auditor.

DOCUMENT TYPE: {req.document_type}
ORGANISATION PARASTATAL: {req.parastatal}
DCPMI TIER: {req.tier}

Analyse this policy document against GAID 2025 requirements.

DOCUMENT TEXT (first 5000 chars):
---
{extracted[:5000]}
---

Return ONLY JSON:
{{
  "analysis": {{
    "document_summary": "2-3 sentence summary",
    "strengths": ["what it covers well"],
    "weaknesses": ["what it's missing"]
  }},
  "clauses_covered": [
    {{ "clause": "GAID 2025 §X.X", "topic": "What this clause covers", "evidence": "Quote or paraphrase from doc", "confidence": 0.0-1.0 }}
  ],
  "coverage_score": 0.0-1.0,
  "extracted_text": "first 2000 chars of extracted text"
}}
Return ONLY valid JSON.
"""

    response = claude.messages.create(model=MODEL, max_tokens=3000,
        messages=[{"role": "user", "content": prompt}])
    result = _parse_json(response.content[0].text)
    result["extracted_text"] = extracted[:2000]
    return result


# ══════════════════════════════════════════════════════════════════════════════
# 3. /gaid/gap-analysis — compare obligations vs evidence
# ══════════════════════════════════════════════════════════════════════════════

@app.post("/gaid/gap-analysis")
async def gap_analysis(req: GapAnalysisRequest) -> dict:
    """
    Compares extracted obligations against analysed documents.
    Returns per-obligation gap status + compliance score.
    """
    docs_summary = json.dumps([
        {"type": d.type, "clauses": d.clauses, "analysis": d.analysis}
        for d in req.documents
    ], indent=2)[:4000]

    obligations_text = json.dumps([
        {"id": o.id, "title": o.title, "category": o.category, "clause": o.clause}
        for o in req.obligations
    ], indent=2)

    prompt = f"""
You are conducting a GAID 2025 gap analysis for a {req.tier} DCPMI.

OBLIGATIONS TO CHECK:
{obligations_text}

UPLOADED POLICY DOCUMENTS (analysed):
{docs_summary}

For each obligation, determine whether the uploaded documents provide sufficient evidence.
Return ONLY JSON:
{{
  "gaps": [
    {{
      "obligation_id": <int>,
      "document_id": <int or null — which doc provides evidence>,
      "status": "covered|partial|not_evidenced|not_applicable",
      "confidence": 0.0-1.0,
      "detail": "What is missing or what was found",
      "recommendation": "Concrete action to close this gap",
      "risk_level": "high|medium|low"
    }}
  ],
  "compliance_score": 0-100,
  "summary": "2-3 sentence overall compliance summary"
}}
Return ONLY valid JSON.
"""

    response = claude.messages.create(model=MODEL, max_tokens=3000,
        messages=[{"role": "user", "content": prompt}])
    return _parse_json(response.content[0].text)


# ══════════════════════════════════════════════════════════════════════════════
# 4. /gaid/generate-car — structured CAR draft
# ══════════════════════════════════════════════════════════════════════════════

@app.post("/gaid/generate-car")
async def generate_car(req: CarRequest) -> dict:
    """
    Generates a structured CAR (Compliance Audit Return) document
    mirroring the NDPC template, pre-populated from the org's data.
    """
    gaps_summary = json.dumps([
        {"obligation": g.get("obligation", {}).get("obligation_title", ""),
         "status": g.get("status"), "risk": g.get("risk_level")}
        for g in req.gaps[:20]
    ], indent=2)

    prompt = f"""
You are generating a Compliance Audit Return (CAR) document for NDPC Nigeria.
This mirrors the official NDPC CAR template format.

ORGANISATION DETAILS:
- Name: {req.organisation}
- Parastatal: {req.parastatal}
- Sector: {req.sector}
- Reference: {req.reference_code}
- DCPMI Tier: {req.tier}
- Filing Fee: ₦{req.car_filing_fee:,.0f}
- Filing Deadline: {req.filing_deadline or 'N/A'}
- Compliance Score: {req.compliance_score or 0:.1f}%

GAP SUMMARY:
{gaps_summary}

Generate the CAR document content. Return ONLY JSON:
{{
  "car_data": {{
    "section_a_org_details": {{
      "organisation_name": "...",
      "parastatal": "...",
      "sector": "...",
      "dcpmi_tier": "...",
      "reference_code": "...",
      "reporting_period": "2025"
    }},
    "section_b_data_processing": {{
      "data_subjects_count": ...,
      "categories_of_data": [...],
      "processing_purposes": [...]
    }},
    "section_c_compliance_posture": {{
      "overall_score": ...,
      "dpo_status": "appointed|pending|not_required",
      "dpia_status": "completed|pending|not_required",
      "breach_procedure": "implemented|partial|pending",
      "narrative": "..."
    }},
    "section_d_gaps_and_remediation": [
      {{ "obligation": "...", "status": "...", "remediation_plan": "...", "target_date": "..." }}
    ],
    "section_e_declaration": {{
      "declaration_text": "We confirm this CAR accurately reflects our data protection posture as at the date of submission.",
      "filing_fee": "₦{req.car_filing_fee:,.0f}"
    }}
  }}
}}
Return ONLY valid JSON.
"""

    response = claude.messages.create(model=MODEL, max_tokens=4000,
        messages=[{"role": "user", "content": prompt}])
    result   = _parse_json(response.content[0].text)

    # Generate PDF via reportlab
    file_path = _generate_car_pdf(req, result.get("car_data", {}))
    result["file_path"] = file_path
    return result


# ══════════════════════════════════════════════════════════════════════════════
# Health
# ══════════════════════════════════════════════════════════════════════════════
@app.get("/health")
async def health():
    return {"status": "ok", "model": MODEL, "vector_db": VECTOR_DB_READY, "service": "gaid-guardian"}


# ══════════════════════════════════════════════════════════════════════════════
# Helpers
# ══════════════════════════════════════════════════════════════════════════════

def _extract_pdf(file_path: str) -> str:
    try:
        doc  = fitz.open(file_path)
        text = "\n".join(page.get_text() for page in doc)
        doc.close()
        return text.strip()
    except Exception as e:
        print(f"PDF extraction error: {e}")
        return ""

def _rag_query(query: str, top_k: int = 8) -> str:
    """Query ChromaDB vector store for relevant GAID 2025 chunks."""
    try:
        col     = _chroma_client.get_collection("gaid_2025")
        results = col.query(query_texts=[query], n_results=top_k)
        chunks  = results.get("documents", [[]])[0]
        return "\n\n---\n\n".join(chunks)
    except Exception as e:
        return _gaid_fallback_context("ultra_high")

def _gaid_fallback_context(tier: str) -> str:
    """Hardcoded GAID 2025 context for when vector DB isn't ready."""
    return f"""
GAID 2025 Key Requirements for {tier} DCPMI:
§3.1  DCPMI Registration with NDPC within 90 days of classification
§4.2  Appointment of DPO within 6 months — required for Ultra/Extra High
§5.1  Filing Compliance Audit Return (CAR) annually by March 31
§6.1  Lawful basis and consent framework documentation required
§7.2  Data retention schedules must be documented and enforced
§8.1  CAR filing fees: Ultra=₦1M, Extra=₦500k, Ordinary=₦200k
§9.1  DPO must be qualified and registered with NDPC
§10.3 Data breach notification to NDPC within 72 hours of discovery
§11.2 DPIA mandatory before deploying AI systems processing personal data
§13.1 Cross-border data transfers require NDPC prior authorisation
"""

def _parse_json(text: str) -> dict:
    """Strip markdown fences and parse JSON."""
    import re
    clean = re.sub(r"^```(json)?|```$", "", text.strip(), flags=re.MULTILINE).strip()
    return json.loads(clean)

def _generate_car_pdf(req: CarRequest, car_data: dict) -> str:
    """Generate a PDF CAR using reportlab and return the file path."""
    try:
        from reportlab.lib.pagesizes import A4
        from reportlab.lib import colors
        from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle
        from reportlab.lib.styles import getSampleStyleSheet

        out_dir  = Path(f"storage/app/gaid/{req.submission_id}/car")
        out_dir.mkdir(parents=True, exist_ok=True)
        out_path = out_dir / f"CAR_{req.reference_code}.pdf"

        doc    = SimpleDocTemplate(str(out_path), pagesize=A4)
        styles = getSampleStyleSheet()
        story  = []

        story.append(Paragraph("NDPC Compliance Audit Return (CAR)", styles['Title']))
        story.append(Paragraph(f"Reference: {req.reference_code} | Generated: {time.strftime('%Y-%m-%d')}", styles['Normal']))
        story.append(Spacer(1, 20))

        # Section A
        story.append(Paragraph("Section A — Organisation Details", styles['Heading2']))
        sec_a = car_data.get("section_a_org_details", {})
        data  = [[k.replace("_", " ").title(), str(v)] for k, v in sec_a.items()]
        if data:
            t = Table(data, colWidths=[200, 280])
            t.setStyle(TableStyle([
                ('BACKGROUND', (0,0), (0,-1), colors.HexColor('#1E293B')),
                ('TEXTCOLOR',  (0,0), (0,-1), colors.white),
                ('GRID',       (0,0), (-1,-1), 0.5, colors.HexColor('#334155')),
                ('FONTSIZE',   (0,0), (-1,-1), 9),
            ]))
            story.append(t)
        story.append(Spacer(1, 16))

        # Section C
        story.append(Paragraph("Section C — Compliance Posture", styles['Heading2']))
        sec_c = car_data.get("section_c_compliance_posture", {})
        if sec_c.get("narrative"):
            story.append(Paragraph(sec_c["narrative"], styles['Normal']))
        story.append(Spacer(1, 16))

        # Section D
        story.append(Paragraph("Section D — Gaps & Remediation", styles['Heading2']))
        sec_d = car_data.get("section_d_gaps_and_remediation", [])
        if sec_d:
            rows  = [["Obligation", "Status", "Remediation Plan", "Target Date"]]
            rows += [[g.get("obligation",""), g.get("status",""), g.get("remediation_plan",""), g.get("target_date","")] for g in sec_d[:15]]
            t = Table(rows, colWidths=[130, 60, 200, 90])
            t.setStyle(TableStyle([
                ('BACKGROUND', (0,0), (-1,0), colors.HexColor('#4F46E5')),
                ('TEXTCOLOR',  (0,0), (-1,0), colors.white),
                ('GRID',       (0,0), (-1,-1), 0.5, colors.HexColor('#334155')),
                ('FONTSIZE',   (0,0), (-1,-1), 8),
                ('ROWBACKGROUNDS', (0,1), (-1,-1), [colors.HexColor('#0F172A'), colors.HexColor('#1E293B')]),
                ('TEXTCOLOR',  (0,1), (-1,-1), colors.HexColor('#94A3B8')),
            ]))
            story.append(t)

        doc.build(story)
        return str(out_path)

    except Exception as e:
        print(f"PDF generation error: {e}")
        return ""
