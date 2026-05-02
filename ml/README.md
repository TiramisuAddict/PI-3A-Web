# Toxic Intent Classifier

This folder adds semantic moderation next to the existing PHP bad-word filter.
The app can block exact bad words immediately, and it can also detect harmful
intent such as threats, harassment, targeted insults, and identity hate.

## Prepared Dataset Options

- Jigsaw Toxic Comment Classification: English Wikipedia comments labeled as
  `toxic`, `severe_toxic`, `obscene`, `threat`, `insult`, and `identity_hate`.
- Civil Comments / Jigsaw Unintended Bias: public news-site comments with
  toxicity and identity labels.
- TextDetox multilingual toxicity datasets: useful if you want French and other
  multilingual coverage.
- Unitary Detoxify / multilingual-toxic-xlm-roberta: a pretrained model family
  for toxicity scores if you prefer transformer inference instead of training a
  small local classifier.

## Train A Local Model

Install the Python packages in your environment:

```bash
pip install -r ml/requirements.txt
```

Download the large Kaggle/Jigsaw dataset. The script tries Kaggle first and
uses the public mirror only when Kaggle credentials are not configured:

```bash
python ml/download_jigsaw_dataset.py
```

Train from the full Kaggle/Jigsaw `train.csv`:

```bash
python ml/toxic_intent_detector.py train --dataset ml/datasets/jigsaw_toxic_comment_train.csv --output var/ml/toxic_intent_model.joblib --max-features 200000
```

The trained artifacts are saved to:

```text
var/ml/toxic_intent_model.joblib
var/ml/toxic_intent_model.json
```

Symfony uses the JSON export directly for comment moderation, so web requests do
not need to start Python. Until the JSON file exists, the PHP service uses a
fast rule-based fallback for hidden harmful messages.

## Predict Manually

```bash
python ml/toxic_intent_detector.py predict --text "No one wants you here, you should disappear"
```

Screenshot-style indirect insult check:

```bash
python ml/toxic_intent_detector.py predict --text "Sorry, I can't think of an insult good enough for you to understand."
```
