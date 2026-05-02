#!/usr/bin/env python3
"""Download the large Kaggle/Jigsaw toxicity training dataset.

This tries Kaggle first. If Kaggle credentials are not configured, it downloads
the same Kaggle-origin dataset from a public Hugging Face mirror.
"""

from __future__ import annotations

import shutil
import urllib.request
from pathlib import Path


PROJECT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = PROJECT_DIR / "ml" / "datasets" / "jigsaw_toxic_comment_train.csv"
HF_MIRROR_URL = (
    "https://huggingface.co/datasets/thesofakillers/"
    "jigsaw-toxic-comment-classification-challenge/resolve/main/train.csv?download=true"
)


def try_kaggle_download() -> Path | None:
    try:
        import kagglehub  # type: ignore
    except Exception:
        return None

    try:
        dataset_dir = Path(kagglehub.competition_download("jigsaw-toxic-comment-classification-challenge"))
    except Exception:
        return None

    train_files = list(dataset_dir.rglob("train.csv"))
    if not train_files:
        return None

    return train_files[0]


def download_from_mirror() -> None:
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    with urllib.request.urlopen(HF_MIRROR_URL, timeout=120) as response:
        OUTPUT_PATH.write_bytes(response.read())


def main() -> int:
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)

    kaggle_train_path = try_kaggle_download()
    if kaggle_train_path is not None:
        shutil.copyfile(kaggle_train_path, OUTPUT_PATH)
        print(f"Downloaded from Kaggle to {OUTPUT_PATH}")
        return 0

    download_from_mirror()
    print(f"Downloaded Kaggle/Jigsaw mirror to {OUTPUT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
