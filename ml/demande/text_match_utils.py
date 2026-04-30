import re
import unicodedata


def _normalize_ws(text):
    return re.sub(r"\s+", " ", str(text or "")).strip()


def _norm(text):
    value = unicodedata.normalize("NFD", str(text or ""))
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    return _normalize_ws(value).lower()


def _has_word(text_norm, word):
    word_norm = _norm(word)
    if not word_norm:
        return False
    pattern = r"(?<!\w)" + re.escape(word_norm) + r"(?!\w)"
    return bool(re.search(pattern, text_norm))


def _has_any_word(text_norm, words):
    return any(_has_word(text_norm, word) for word in words or [])


def _has_phrase(text_norm, phrase):
    tokens = [_norm(token) for token in str(phrase or "").split() if token.strip()]
    if not tokens:
        return False
    pattern = r"(?<!\w)" + r"\s+".join(re.escape(token) for token in tokens) + r"(?!\w)"
    return bool(re.search(pattern, text_norm))


def _has_any_phrase(text_norm, phrases):
    return any(_has_phrase(text_norm, phrase) for phrase in phrases or [])