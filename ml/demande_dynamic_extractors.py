import re


def normalize_ws(text):
    return re.sub(r"\s+", " ", str(text or "")).strip()


def norm(text):
    value = normalize_ws(text).lower()
    value = (
        value.replace("à", "a")
        .replace("â", "a")
        .replace("ä", "a")
        .replace("é", "e")
        .replace("è", "e")
        .replace("ê", "e")
        .replace("ë", "e")
        .replace("î", "i")
        .replace("ï", "i")
        .replace("ô", "o")
        .replace("ö", "o")
        .replace("ù", "u")
        .replace("û", "u")
        .replace("ü", "u")
        .replace("ç", "c")
    )
    return value


def capitalize_entity(value):
    parts = normalize_ws(value).split()
    return " ".join(p[:1].upper() + p[1:] for p in parts if p)


def clean_entity_text(value):
    text = normalize_ws(value)
    if not text:
        return ""
    text = re.sub(r"\b(?:pour|afin|car|avec|le|la|une|un)\b.*$", "", text, flags=re.IGNORECASE)
    text = re.sub(r"\b\d{4}-\d{2}-\d{2}\b", "", text)
    text = re.sub(r"\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b", "", text)
    return normalize_ws(text).strip(" ,;:-")


def is_likely_proper_noun(word, stopwords=None, month_aliases=None):
    stopwords = stopwords or set()
    month_aliases = month_aliases or {}
    n = norm(word)
    if not n or n in stopwords or n in month_aliases:
        return False
    if len(n) < 2:
        return False
    if re.fullmatch(r"\d+", n):
        return False
    return True


def extract_location_pair(corrected_text, stopwords=None, month_aliases=None):
    lowered = norm(corrected_text)
    patterns = [
        r"\bde\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)",
        r"\bdepuis\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)",
        r"\bde\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+(?:a|à|jusqu)\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)",
        r"\bdepart\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b.*?\b(?:destination|arrivee)\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b",
    ]
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        raw_dep = clean_entity_text(match.group(1))
        raw_dest = clean_entity_text(match.group(2))
        dep_valid = any(is_likely_proper_noun(w, stopwords, month_aliases) for w in raw_dep.split())
        dest_valid = any(is_likely_proper_noun(w, stopwords, month_aliases) for w in raw_dest.split())
        if dep_valid and dest_valid and norm(raw_dep) != norm(raw_dest):
            return capitalize_entity(raw_dep), capitalize_entity(raw_dest)
    return None, None


def extract_subject_name(corrected_text, subject_keyword, stopwords=None, month_aliases=None):
    lowered = norm(corrected_text)
    patterns = [
        rf"\b{re.escape(subject_keyword)}\s+(?:en\s+|sur\s+|de\s+)?([a-z][a-z0-9\s\-\./+#]*[a-z0-9+#])(?=\s+le\b|\s+du\b|\s+de\b|\s+vers\b|\s+pour\b|\s+a\b|$)",
        rf"\bpour\s+(?:une?\s+)?{re.escape(subject_keyword)}\s+(?:en\s+|sur\s+|de\s+)?([a-z][a-z0-9\s\-\./+#]*[a-z0-9+#])(?=\s+le\b|\s+du\b|\s+de\b|\s+vers\b|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        cleaned = clean_entity_text(match.group(1))
        if not cleaned or len(cleaned) < 2:
            continue
        words = cleaned.split()
        if any(is_likely_proper_noun(w, stopwords, month_aliases) for w in words):
            return capitalize_entity(cleaned)
        return cleaned[:1].upper() + cleaned[1:]
    return None
