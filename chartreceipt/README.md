# ChartReceipt: check PowerPoint chart data

A PowerPoint deck can contain two stored versions of a number: the chart cache and the XLSX workbook embedded behind it. ChartReceipt compares supported references and returns exact evidence while preserving the original deck.

[Get ChartReceipt on Agensi — $9 one-time](https://www.agensi.io/skills/chartreceipt-powerpoint-chart-workbook-consistency). Check the listing for current price, purchase terms and supported scope.

## First check

1. Use your Agensi buyer account and an Agensi-connected agent that can access your purchased skill. The agent needs permitted terminal execution of **Python 3.10+**, permission to read the chosen PPTX, and permission to create report files.
2. Attach or select a PPTX you are authorized to check. Use a private workspace and a **new output folder**; the checker refuses an existing output directory.
3. Ask:

> Use ChartReceipt on this deck. Leave it unchanged and give me the HTML and JSON reports, including the exact cells that differ or need review.

Agensi delivers the skill through its agent connection. The agent runs the purchased checker unchanged from a temporary file, removes that temporary script afterward, and leaves the reports in your authorized workspace. The original PPTX stays unchanged.

The checker uses Python’s standard library. **No RapidAPI key, API subscription, Office installation or pip package is required.** Your agent/provider’s execution permissions, file handling and any charges still apply.

## A real run on fictional data

This unchanged ChartReceipt 1.0.2 example uses a deliberately altered fictional deck:

| Evidence | Result |
| --- | --- |
| Slide / chart part | 1 / `ppt/charts/chart1.xml` |
| Series / cell | 1 / `Sheet1!B3` |
| Stored chart value | `20` |
| Embedded workbook value | `999` |
| Verdict | `MISMATCH` |

Three references were checked: the series name, categories and numeric values. Six points agreed; one differed. No unresolved item was observed in this small fixture. This is an actual creator-local run, not a buyer result, a PowerPoint rendering test or proof of business truth.

- [Fictional PPTX](samples/mismatch.pptx)
- [Complete JSON receipt](samples/mismatch.json)
- [Sample provenance and SHA-256 identities](samples/provenance.json)

These samples demonstrate the result. To check another deck, use the purchased skill through your Agensi-connected agent.

## What the reports mean

You receive `report.json`, a machine-readable receipt with source hashes, and `report.html`, a static report for review. Findings can identify the slide, chart part, series, range, point and workbook cell. Review coverage and unresolved items alongside the verdict.

| Verdict | Meaning |
| --- | --- |
| `MATCH` | The supported stored values checked agree. Review the reported coverage. |
| `MISMATCH` | At least one checked stored value differs. Other references may still need review. |
| `REVIEW` | No observed mismatch, but uncertainty or unsupported structure prevents a clear result. This is not a pass. |
| `NO_CLASSIC_CHARTS` | No supported native chart reference was found. |
| `UNAVAILABLE` | An input, package or limit failure prevented a completed audit. |

Inspect differences in your authoring tool and decide which value is intended. After an authorized correction, request a fresh check into a new folder. Perform a separate visual and content review before delivery.

## Scope, uncertainty and privacy

The bounded comparison covers classic native PowerPoint charts with supported single-cell or one-dimensional A1 references to the relationship-linked embedded XLSX. It compares supported series names, categories and numeric values, distinguishes a missing point from zero, and uses exact decimal comparison for supported numeric values.

Formula-cache freshness requires review: ChartReceipt does not calculate formulas. Text labels or titles referring to numeric worksheet cells require review because their formatting is not evaluated. Modern chartEx, multi-level/pivot/extended structures, unsupported named, union, two-dimensional or external ranges, and missing or ambiguous data can leave unresolved coverage.

Selected limits are 24 MiB per PPTX, 500 slides, 100 unique referenced classic chart parts and 50,000 planned comparison points per deck. Additional ZIP, XML, worksheet and reference limits apply; consult the scope reference delivered with the skill. Limit failures never become `MATCH`.

The checker does not repair decks, refresh external data, certify rendered appearance or establish mathematical/business correctness. Agreement with an embedded snapshot does not establish agreement with an external master or current facts.

The checker runs in the workspace you authorize; it does not call a network processing service or fetch external workbook links. Your agent/provider may still handle supplied files under its own policies. Reports contain source filenames, worksheet labels, formulas and values. Keep them private and review sharing permissions before sending them elsewhere.
