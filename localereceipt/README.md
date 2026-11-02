# LocaleReceipt demonstration

LocaleReceipt turns a source-language JSON update into a translation handoff. Compare the previous source, current source and selected current translations, then receive exact old/new/target wording, review reasons and input hashes. It runs locally through a capable agent, without an API key, application repository, npm setup or seller backend.

This directory contains original fictional inputs and actual generated reports. The paid Agensi skill supplies its instructions and helper through Agensi; reusable skill source is not included here.

## Complete original demonstration

Inputs: [previous source](proof-previous.json), [current source](proof-current.json), [French translation](proof-fr.json).

Outputs: [complete receipt](receipt.json), [complete offline HTML report](review.html), [verification record](verification.json) and [checksums](checksums.json). Download the HTML file and open it in a browser to use its local filters. Its screenshots and complete receipt are also shown on the product listing.

| Key | Previous source | Current source | Current translation | Review reason |
| --- | --- | --- | --- | --- |
| `/checkout` | Pay now | Start free trial | Payer maintenant | Source wording changed |
| `/greeting` | Hello {name} | Welcome {name} | Bonjour {name} | Source wording changed |
| `/legacy` | Legacy | No entry | Ancien | Source key removed; target retained |
| `/retry` | No entry | Try again | No entry | Source key added; translation missing |

The unchanged `/unchanged` entry is counted, not repeated in the review table. It is not certified as a correct translation. These reports were produced by an actual standalone helper run and independently reproduced from unchanged temporary source. Browser checks covered filters, search, multiple selected translations, a narrow viewport and a no-change control. This is not an Agensi buyer-session record, customer testimonial or paid sale.

## Meaning and limits

A changed source is a review candidate even if its translation is already updated. No translations are rewritten or deleted. All string contents are opaque, including ICU, i18next, printf and markup. Template syntax, placeholders, runtime compatibility, translation quality and release approval are not assessed.

The helper supports Python 3.10+ on POSIX/macOS/Linux; Windows is unsupported. It accepts nested JSON objects with string leaves, rejects duplicate keys and unsupported structures, preserves the originals and requires a new output directory. JSON Pointer paths preserve exact key identity, including literal dots, slashes and tildes. Empty and whitespace-only targets are flagged as blank while retaining their exact values.

Input files are bounded at 1 MiB each, 10,000 string leaves, 20,000 nodes, 32 JSON-node depth levels and 32,768 characters per string. Up to 8 selected translation files and 50,000 review rows are supported, with 32 MiB combined output. Only selected files are covered. Local helper execution makes no network request; the agent provider has its own data handling.
