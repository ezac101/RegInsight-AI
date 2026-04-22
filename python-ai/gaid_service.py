import os
import json
import re
from pathlib import Path
from typing import Any

import chromadb
import fitz
from dotenv import load_dotenv
from fastapi import FastAPI, HTTPException
from groq import Groq
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer

SERVICE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SERVICE_DIR.parent

# Load env files from both service directory and project root so launch cwd does not matter.
load_dotenv(SERVICE_DIR / '.env')
load_dotenv(PROJECT_ROOT / '.env')


def resolve_env_path(raw_value: str | None, default_path: Path) -> Path:
    if raw_value is None or raw_value.strip() == '':
        return default_path

    candidate = Path(raw_value)
    if candidate.is_absolute():
        return candidate

    service_relative = (SERVICE_DIR / candidate).resolve()
    if service_relative.exists():
        return service_relative

    return (PROJECT_ROOT / candidate).resolve()

app = FastAPI(title='GAID Guardian Service', version='1.0.0')

REGULATION_DEFAULT_PATH = SERVICE_DIR / 'data' / 'GAID_2025_Regulations.pdf'
REGULATION_PDF_PATH = resolve_env_path(os.getenv('GAID_REGULATION_PDF'), REGULATION_DEFAULT_PATH)
CHROMA_DIR = resolve_env_path(os.getenv('CHROMA_DIR'), SERVICE_DIR / 'chroma')
CHROMA_COLLECTION_NAME = os.getenv('CHROMA_COLLECTION_NAME', 'gaid_2025_clauses')

EMBEDDING_MODEL_NAME = os.getenv('EMBEDDING_MODEL', 'sentence-transformers/all-MiniLM-L6-v2')
GROQ_MODEL = os.getenv('GROQ_MODEL', 'mixtral-8x7b-32768')

LOADED_CLAUSES: list[dict[str, Any]] = []
EMBEDDING_MODEL: SentenceTransformer | None = None
CHROMA_CLIENT: chromadb.PersistentClient | None = None
CHROMA_COLLECTION: Any = None
GROQ_CLIENT: Groq | None = None

ALLOWED_CATEGORIES = {
    'dpo',
    'dpia',
    'car',
    'breach',
    'consent',
    'retention',
    'registration',
    'transfer',
    'other',
}

class ClassifyRequest(BaseModel):
    organisation_name: str
    organisation_email: str
    parastatal: str = 'NDPC'
    sector: str
    data_subjects: int
    uses_ai: bool
    processes_sensitive_data: bool
    transfers_data_outside_nigeria: bool
    has_dpo: bool | None = None
    has_data_retention_policy: bool | None = None
    has_breach_policy: bool | None = None
    has_vendor_dp_agreements: bool | None = None
    annual_revenue_band: str | None = None


class ObligationsRequest(BaseModel):
    sector: str
    uses_ai: bool
    transfers_data_outside_nigeria: bool
    classification: dict[str, Any]


class GapObligation(BaseModel):
    id: int | None = None
    clause_reference: str
    title: str
    description: str | None = None
    plain_language: str | None = None
    deadline: str | None = None
    risk: str | None = None


class GapFile(BaseModel):
    id: int | None = None
    file_path: str
    file_name: str


class GapRequest(BaseModel):
    reference_code: str
    classification: dict[str, Any] | None = None
    obligations: list[GapObligation]
    files: list[GapFile]


class CarRequest(BaseModel):
    reference_code: str
    organisation_name: str
    organisation_email: str
    parastatal: str
    sector: str
    classification: dict[str, Any] | None = None
    obligations: list[dict[str, Any]]
    gaps: list[dict[str, Any]]
    compliance_score: float | None = None


@app.on_event('startup')
def load_regulation_clauses_on_startup() -> None:
    global LOADED_CLAUSES, EMBEDDING_MODEL, CHROMA_CLIENT, CHROMA_COLLECTION, GROQ_CLIENT

    if REGULATION_PDF_PATH.exists() is False:
        LOADED_CLAUSES = []
        return

    LOADED_CLAUSES = build_clauses_from_regulation_pdf(REGULATION_PDF_PATH)

    if len(LOADED_CLAUSES) == 0:
        return

    EMBEDDING_MODEL = SentenceTransformer(EMBEDDING_MODEL_NAME)

    CHROMA_DIR.mkdir(parents=True, exist_ok=True)
    CHROMA_CLIENT = chromadb.PersistentClient(path=str(CHROMA_DIR))

    try:
        CHROMA_CLIENT.delete_collection(name=CHROMA_COLLECTION_NAME)
    except Exception:
        pass

    CHROMA_COLLECTION = CHROMA_CLIENT.create_collection(name=CHROMA_COLLECTION_NAME)

    documents = [f"{item['title']}\n{item['description']}" for item in LOADED_CLAUSES]
    embeddings = EMBEDDING_MODEL.encode(documents).tolist()

    CHROMA_COLLECTION.add(
        ids=[f'clause-{index + 1}' for index in range(len(LOADED_CLAUSES))],
        documents=documents,
        embeddings=embeddings,
        metadatas=LOADED_CLAUSES,
    )

    groq_api_key = os.getenv('GROQ_API_KEY', '').strip()
    if groq_api_key != '':
        GROQ_CLIENT = Groq(api_key=groq_api_key)


@app.get('/health')
def health() -> dict[str, Any]:
    return {
        'status': 'ok',
        'service': 'gaid-guardian-python',
        'obligations_source': 'regulation-pdf' if LOADED_CLAUSES else 'not-loaded',
        'loaded_clause_count': len(LOADED_CLAUSES),
        'regulation_pdf_path': str(REGULATION_PDF_PATH),
        'chroma_ready': CHROMA_COLLECTION is not None,
        'groq_ready': GROQ_CLIENT is not None,
    }


@app.post('/classify')
def classify(payload: ClassifyRequest) -> dict[str, Any]:
    tier = 'not_classified'
    fee = 0
    deadline = None

    if (
        payload.data_subjects >= 50000
        or (payload.sector == 'Fintech' and payload.data_subjects >= 25000)
        or (payload.uses_ai and payload.processes_sensitive_data and payload.data_subjects >= 10000)
    ):
        tier = 'ultra_high'
        fee = 1000000
        deadline = '2026-03-31'
    elif payload.data_subjects >= 10000 or (payload.processes_sensitive_data and payload.transfers_data_outside_nigeria):
        tier = 'extra_high'
        fee = 500000
        deadline = '2026-03-31'
    elif payload.data_subjects >= 1000:
        tier = 'ordinary_high'
        fee = 200000
        deadline = '2026-06-30'

    reasons: list[str] = []
    if payload.data_subjects >= 50000:
        reasons.append('More than 50,000 data subjects')
    if payload.sector == 'Fintech':
        reasons.append('Fintech sector risk weighting')
    if payload.uses_ai:
        reasons.append('AI systems in operation')
    if payload.processes_sensitive_data:
        reasons.append('Sensitive personal data processing')
    if payload.transfers_data_outside_nigeria:
        reasons.append('Cross-border transfer exposure')

    return {
        'tier': tier,
        'car_filing_fee': fee,
        'filing_deadline': deadline,
        'dpo_required': tier != 'not_classified',
        'dpia_required': payload.uses_ai,
        'reasons': reasons,
        'deterministic': True,
    }


@app.post('/get-obligations')
def get_obligations(payload: ObligationsRequest) -> dict[str, Any]:
    if CHROMA_COLLECTION is None or EMBEDDING_MODEL is None:
        raise HTTPException(
            status_code=503,
            detail=f'GAID regulation PDF not indexed. Upload file to {REGULATION_PDF_PATH} and restart Python service.',
        )

    if GROQ_CLIENT is None:
        raise HTTPException(status_code=503, detail='GROQ_API_KEY is missing. Obligations extraction requires Groq LLM.')

    query = (
        f"sector={payload.sector}; "
        f"uses_ai={payload.uses_ai}; "
        f"transfers_data_outside_nigeria={payload.transfers_data_outside_nigeria}; "
        f"tier={payload.classification.get('tier', 'not_classified')}"
    )

    query_embedding = EMBEDDING_MODEL.encode([query]).tolist()[0]
    result = CHROMA_COLLECTION.query(query_embeddings=[query_embedding], n_results=8)

    metadatas = (result.get('metadatas') or [[]])[0]

    if len(metadatas) == 0:
        raise HTTPException(status_code=500, detail='No clauses retrieved from ChromaDB.')

    context_lines = [
        f"- {item['clause_reference']} | {item['title']} | {item['description']}"
        for item in metadatas
    ]

    prompt = (
        'Using ONLY the text below, list the exact obligations for this organisation. '
        'Include clause numbers, plain-English explanation, risk, and category. '
        'Return strict JSON with key obligations (array).\n\n'
        f"Organisation profile: {query}\n\n"
        'Regulation context:\n'
        + '\n'.join(context_lines)
    )

    llm_data = llm_json(prompt)
    raw_obligations = llm_data.get('obligations', [])

    if not isinstance(raw_obligations, list) or len(raw_obligations) == 0:
        raise HTTPException(status_code=502, detail='Groq returned invalid obligations payload.')

    obligations: list[dict[str, Any]] = []
    for item in raw_obligations[:8]:
        if not isinstance(item, dict):
            continue

        clause_reference = str(item.get('clause_reference', '')).strip()
        title = str(item.get('title', '')).strip()
        description = str(item.get('description', '')).strip()
        plain_language = str(item.get('plain_language', '')).strip()
        risk = str(item.get('risk', '')).strip()

        if clause_reference == '' or title == '' or plain_language == '':
            continue

        inferred_category = infer_category(f"{title} {description} {plain_language}")

        obligations.append(
            {
                'clause_reference': clause_reference,
                'title': title,
                'description': description if description != '' else plain_language,
                'plain_language': plain_language,
                'deadline': payload.classification.get('filing_deadline'),
                'risk': risk if risk != '' else 'Regulatory sanctions may apply for non-compliance.',
                'category': inferred_category,
                'mandatory': True,
            }
        )

    if len(obligations) == 0:
        raise HTTPException(status_code=502, detail='No valid obligations parsed from Groq response.')

    return {'obligations': obligations}


@app.post('/analyse-gaps')
def analyse_gaps(payload: GapRequest) -> dict[str, Any]:
    if GROQ_CLIENT is None:
        raise HTTPException(status_code=503, detail='GROQ_API_KEY is missing. Gap analysis requires Groq LLM.')

    extracted_docs: list[dict[str, Any]] = []

    for file_meta in payload.files:
        text = extract_pdf_text(file_meta.file_path)
        extracted_docs.append({'id': file_meta.id, 'name': file_meta.file_name.lower(), 'text': text.lower()})

    combined_text = '\n\n'.join(
        [f"[document: {item['name']}]\n{item['text'][:9000]}" for item in extracted_docs]
    ).strip()

    if combined_text == '':
        raise HTTPException(status_code=422, detail='Unable to extract readable text from uploaded documents.')

    gaps: list[dict[str, Any]] = []
    covered_equivalent_score = 0.0

    for obligation in payload.obligations:
        prompt = (
            'You are validating GAID compliance evidence.\n'
            'Assess whether uploaded policy text proves the obligation is covered.\n'
            'Return strict JSON with keys: status, confidence, detail, recommendation, risk_level.\n'
            'Allowed status values: covered, partial, not_evidenced.\n'
            'Allowed risk_level values: low, medium, high.\n\n'
            f"Obligation clause: {obligation.clause_reference}\n"
            f"Obligation title: {obligation.title}\n"
            f"Obligation detail: {obligation.description or obligation.plain_language or ''}\n\n"
            'Evidence text:\n'
            f'{combined_text}'
        )

        verdict = llm_json(prompt)

        status = str(verdict.get('status', 'not_evidenced')).strip().lower()
        if status not in {'covered', 'partial', 'not_evidenced'}:
            status = 'not_evidenced'

        confidence = float(verdict.get('confidence', 0.0))
        confidence = max(0.0, min(1.0, confidence))

        detail = str(verdict.get('detail', '')).strip()
        recommendation = str(verdict.get('recommendation', '')).strip()
        risk = str(verdict.get('risk_level', '')).strip().lower()
        if risk not in {'low', 'medium', 'high'}:
            risk = 'medium'

        if status == 'covered':
            covered_equivalent_score += 1.0
        elif status == 'partial':
            covered_equivalent_score += 0.5

        gaps.append(
            {
                'obligation_id': obligation.id,
                'clause_reference': obligation.clause_reference,
                'document_id': extracted_docs[0]['id'] if len(extracted_docs) > 0 else None,
                'status': status,
                'confidence': confidence,
                'detail': detail,
                'recommendation': recommendation,
                'risk_level': risk,
            }
        )

    total = max(len(payload.obligations), 1)
    compliance_score = round((covered_equivalent_score / total) * 100, 2)

    return {
        'gaps': gaps,
        'compliance_score': compliance_score,
        'summary': 'Gap analysis completed using extracted policy text and clause-level checks.',
    }


@app.post('/generate-car')
def generate_car(payload: CarRequest) -> dict[str, Any]:
    remediation = []
    for gap in payload.gaps:
        status = str(gap.get('status', 'not_evidenced'))
        if status in ['partial', 'not_evidenced']:
            remediation.append(
                {
                    'obligation': gap.get('obligation', {}).get('title', 'Unnamed obligation'),
                    'status': status,
                    'recommendation': gap.get('recommendation', 'Define remediation action and owner.'),
                    'target_date': gap.get('target_date', '2026-06-30'),
                }
            )

    return {
        'car_data': {
            'header': {
                'title': 'Compliance Audit Return (CAR)',
                'reference_code': payload.reference_code,
                'regulator': 'National Data Protection Commission',
            },
            'organisation': {
                'name': payload.organisation_name,
                'email': payload.organisation_email,
                'parastatal': payload.parastatal,
                'sector': payload.sector,
            },
            'classification': payload.classification or {},
            'compliance_score': payload.compliance_score or 0,
            'remediation': remediation,
            'footer': 'Built for Microsoft AI Skills Week 2026 - RegTech Hackathon',
        }
    }


def llm_json(prompt: str) -> dict[str, Any]:
    if GROQ_CLIENT is None:
        raise HTTPException(status_code=503, detail='GROQ_API_KEY is missing.')

    completion = GROQ_CLIENT.chat.completions.create(
        model=GROQ_MODEL,
        messages=[
            {
                'role': 'system',
                'content': 'Return valid JSON only. Do not include markdown code fences.',
            },
            {
                'role': 'user',
                'content': prompt,
            },
        ],
        temperature=0,
    )

    content = (completion.choices[0].message.content or '').strip()
    if content == '':
        raise HTTPException(status_code=502, detail='Groq returned an empty response.')

    try:
        return json.loads(content)
    except json.JSONDecodeError as exc:
        raise HTTPException(status_code=502, detail=f'Groq returned non-JSON response: {content[:200]}') from exc


def extract_pdf_text(path: str) -> str:
    if not Path(path).exists():
        return ''

    try:
        document = fitz.open(path)
        text = []
        for page in document:
            text.append(page.get_text())
        document.close()
        return '\n'.join(text)
    except Exception:
        return ''


def build_clauses_from_regulation_pdf(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []

    raw_text = extract_pdf_text(str(path))
    if raw_text.strip() == '':
        return []

    clause_pattern = re.compile(r'(GAID\s*2025\s*§\s*\d+(?:\.\d+)*)', flags=re.IGNORECASE)
    article_pattern = re.compile(r'^Article\s+(\d+[A-Za-z]?)\s*:\s*(.+)$', flags=re.IGNORECASE)
    lines = [line.strip() for line in raw_text.splitlines() if line.strip()]

    extracted: list[dict[str, Any]] = []
    seen_clause_references: set[str] = set()

    # First pass: explicit GAID § references.
    for index, line in enumerate(lines):
        match = clause_pattern.search(line)
        if match is None:
            continue

        clause_reference = normalize_clause_reference(match.group(1))
        if clause_reference in seen_clause_references:
            continue

        title = line[match.end():].strip(' -:;')
        if title == '':
            title = f'GAID obligation {len(extracted) + 1}'

        detail_parts: list[str] = []
        for candidate in lines[index + 1:index + 4]:
            if clause_pattern.search(candidate):
                break
            detail_parts.append(candidate)

        description = ' '.join(detail_parts).strip()
        if description == '':
            description = 'Refer to the GAID 2025 regulation text for full requirement details.'

        category = infer_category(f'{title} {description}')

        extracted.append(
            {
                'clause_reference': clause_reference,
                'title': title,
                'description': description,
                'plain_language': description,
                'category': category,
                'risk': 'Regulatory sanctions may apply for non-compliance.',
            }
        )

        seen_clause_references.add(clause_reference)

        if len(extracted) >= 20:
            break

    # Second pass: GAID PDFs often use "Article X" headings instead of § references.
    if len(extracted) == 0:
        for index, line in enumerate(lines):
            article_match = article_pattern.match(line)
            if article_match is None:
                continue

            article_number = article_match.group(1).strip()
            title = article_match.group(2).strip(' -:;')
            if title == '':
                title = f'Article {article_number}'

            clause_reference = f'GAID 2025 ARTICLE {article_number}'
            if clause_reference in seen_clause_references:
                continue

            detail_parts: list[str] = []
            for candidate in lines[index + 1:index + 13]:
                if article_pattern.match(candidate):
                    break
                detail_parts.append(candidate)

            description = ' '.join(detail_parts).strip()
            if description == '':
                description = f'Refer to Article {article_number} of the GAID 2025 regulation text for full requirement details.'

            category = infer_category(f'{title} {description}')

            extracted.append(
                {
                    'clause_reference': clause_reference,
                    'title': title,
                    'description': description,
                    'plain_language': description,
                    'category': category,
                    'risk': 'Regulatory sanctions may apply for non-compliance.',
                }
            )

            seen_clause_references.add(clause_reference)

            if len(extracted) >= 80:
                break

    # Last resort: split text into meaningful chunks so retrieval still works.
    if len(extracted) == 0:
        paragraph_chunks = [chunk.strip() for chunk in re.split(r'\n\s*\n+', raw_text) if chunk.strip() != '']

        for index, chunk in enumerate(paragraph_chunks):
            normalized_chunk = re.sub(r'\s+', ' ', chunk).strip()
            if len(normalized_chunk) < 140:
                continue

            words = normalized_chunk.split(' ')
            title = ' '.join(words[:10]).strip(' -:;')
            if title == '':
                title = f'Regulatory context block {index + 1}'

            clause_reference = f'GAID 2025 CHUNK {index + 1}'
            description = normalized_chunk[:1200]
            category = infer_category(f'{title} {description}')

            extracted.append(
                {
                    'clause_reference': clause_reference,
                    'title': title,
                    'description': description,
                    'plain_language': description,
                    'category': category,
                    'risk': 'Regulatory sanctions may apply for non-compliance.',
                }
            )

            if len(extracted) >= 80:
                break

    return extracted


def normalize_clause_reference(value: str) -> str:
    normalized = re.sub(r'\s+', ' ', value.strip())
    normalized = normalized.replace('§ ', '§')
    return normalized.upper().replace('GAID', 'GAID')


def infer_category(text: str) -> str:
    lower_text = text.lower()

    keyword_to_category = {
        'dpo': 'dpo',
        'data protection officer': 'dpo',
        'dpia': 'dpia',
        'impact assessment': 'dpia',
        'breach': 'breach',
        'incident': 'breach',
        'consent': 'consent',
        'retention': 'retention',
        'register': 'registration',
        'registration': 'registration',
        'transfer': 'transfer',
        'cross-border': 'transfer',
        'compliance audit return': 'car',
        'car': 'car',
    }

    for keyword, category in keyword_to_category.items():
        if keyword in lower_text:
            return category

    return 'other'


if __name__ == '__main__':
    import uvicorn

    uvicorn.run('gaid_service:app', host='0.0.0.0', port=int(os.getenv('PORT', '8001')), reload=True)
