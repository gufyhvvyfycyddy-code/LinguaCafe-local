# GPT Sense Mapping Work Package

## Task

You need to read the new English material and decide whether words or phrases in it match the learned sense table. Output strict JSON only. Do not output explanations. Do not output Markdown. Do not omit schema_version.

## Judgment Rules

- Prefer matching an existing sense_id when the contextual meaning clearly matches.
- Do not match only because Chinese glosses look similar.
- Use lemma, part of speech, sense_en, sense_zh, aliases_zh, collocations, example_sentences, and sentence context together.
- If the Chinese gloss is similar but the English meaning differs, use new_sense or uncertain.
- If the same English word has a different meaning in this sentence, use new_sense or uncertain.
- Prefer phrase matches before single-word matches.
- If uncertain, use uncertain instead of forcing a match.
- When confidence < 0.90, auto_fsrs_allowed must be false.
- phrase_match is only a marker for now and will not enter FSRS.
- Use ignore for items that do not need learning or processing.

## Required Output Schema

```json
{
  "schema_version": 1,
  "document_id": "example-document",
  "language": "english",
  "sentences": [
    {
      "sentence_id": "s001",
      "en": "They charge a fee.",
      "zh": "",
      "matches": [
        {
          "type": "word",
          "surface": "charge",
          "lemma": "charge",
          "pos": "verb",
          "decision": "match_existing_sense",
          "matched_sense_id": 1,
          "sense_key": "charge-money",
          "sense_zh": "charge money",
          "sense_en": "to ask for money as a price",
          "confidence": 0.95,
          "evidence": "The context says a fee is requested.",
          "auto_fsrs_allowed": true
        }
      ]
    }
  ]
}
```

## Learned Senses

```json
[
  {
    "sense_id": 1,
    "lemma": "charge",
    "surface_examples": ["charge"],
    "pos": "verb",
    "sense_key": "charge-money",
    "sense_zh": "charge money",
    "aliases_zh": ["fee"],
    "sense_en": "to ask for money as a price",
    "collocations": ["charge a fee"],
    "example_sentences": [
      {
        "en": "They charge a fee.",
        "zh": ""
      }
    ],
    "fsrs_state": "new",
    "learned_status": "scheduled"
  }
]
```

## New English Material

```text
They charge a fee for late payment.
```
