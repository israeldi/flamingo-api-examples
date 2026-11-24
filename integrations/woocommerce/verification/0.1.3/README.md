# ShelfRelay 0.1.3 upload-boundary verification

Executed on November 24, 2026: **25/25 cases passed**, with no failing cases.

This is a focused component test using genuine HTTP multipart uploads, native
PHP upload provenance and file moves, and unmodified WordPress upload, hook,
MIME, filename, and filesystem functions. It is not a full WordPress or
WooCommerce installation test.

## Runtime and source

- PHP **8.4.28**, native Debian CLI, with `fileinfo`, `XMLReader`, and supporting
  standard/XML modules enabled. No system packages or production services were
  changed. The interpreter and dependency packages were extracted into a local
  temporary tool directory.
- WordPress **7.1.5**, downloaded over HTTPS from
  <https://wordpress.org/latest.tar.gz>. Archive SHA256 recorded for this run:
  `053857d980eaee8702c617c685948becb9be2523ba0dcbc98e4dbb2300367c79`.
- Tested plugin source SHA256:
  - `includes/class-shelfrelay.php`:
    `5e6b455408d5f80f8bfe1c0cc3761d49a67375d7a622a08e7ebe95434e218952`
  - `includes/class-shelfrelay-feed.php`:
    `8ba4df0bc51a720bcb41e10e13b6f2797a219619ed7bd0d62a81acd74260d0cf`

The PHP package hashes were checked against the corresponding official Debian
package download pages before extraction:

| Package | SHA256 |
| --- | --- |
| `php8.4-cli_8.4.28-1~deb13u1_amd64.deb` | `6baa58c1d7f6ab44ebafc8810db669d07d8a469e156dd5a2e114537dcd7b72f2` |
| `php8.4-common_8.4.28-1~deb13u1_amd64.deb` | `63eddb32417e655e405b62fb5bc95dad5180814e2c7ece2e245d98a899652347` |
| `php8.4-xml_8.4.28-1~deb13u1_amd64.deb` | `4360dd9174631c32e0f843ab737cdf4f94648f1bce8f2d8fc121170608e7cae9` |
| `libargon2-1_0~20190702+dfsg-4+b2_amd64.deb` | `a54a6640be69c29c1e43b14ee464484a6f20e33fe73200c02949cfeb03228547` |

## What passed

- Declared XML, declarationless XML, uppercase `.XML`, supported short tags,
  and explicit ONIX releases 3.0/3.1 upload successfully. Browser MIME and
  declared-size misinformation do not change the accepted bytes.
- A pre-existing libxml error does not incorrectly reject the next valid file.
- Wrong extensions, malformed XML, unsupported roots/releases, DTD/entity
  input, empty input, a file one byte above 25 MiB, a forged temporary path,
  and a PHP upload error are rejected.
- Every successful upload preserves the exact input SHA256 and moves the
  genuine PHP temporary upload into the checked private directory with
  **0700 directory permissions and 0600 file permissions**.
- Rejection by WordPress prefilters or its file-type check, an exception in a
  move hook, a short-circuited move, and a later directory redirection clean up
  the operation's reserved file and restore the original filters.
- A post-upload filter returning an unrelated existing path causes rejection;
  the unrelated file remains unchanged and the owned staged file is removed.
- Nested upload/sideload attempts during private staging are rejected. A
  separate genuine upload from the post-upload hook succeeds with ordinary
  WordPress upload behavior after temporary filters have been removed.
- A system temporary directory inside the configured web root is rejected.

The JSON report contains individual outcomes, source hashes, and the runtime
versions. Both changed PHP classes also passed native `php -l` syntax checks.

## Reproduction

Use Python 3 (standard library only), PHP with the modules above, and an
extracted unmodified WordPress 7.1.5 source tree. The runner starts a local PHP
server bound to `127.0.0.1`, runs the tests, writes `upload-results.json`, and
stops the server. No public server or paid API is contacted.

```sh
python3 run_native.py \
  --php /path/to/php \
  --wordpress /path/to/wordpress \
  --plugin /path/to/shelfrelay-for-woocommerce
```

The runner sets `SHELFRELAY_WP_SOURCE` and `SHELFRELAY_PLUGIN_SOURCE` for the
bootstrap and test driver. `--port` can select another loopback port.

## Verification limits

`ShelfRelay::uploaded_feed()` is invoked through reflection. Database access,
localization, current-user capabilities, and salts are isolated test stubs.
Authentication, nonce validation, the enclosing admin action, the WooCommerce
job queue, scheduled jobs, remote ONIX normalization, RapidAPI billing, and a
real storefront are **not exercised**. The unmodified outer action was inspected
to confirm the `manage_woocommerce` capability and nonce checks remain before
upload handling. These tests do not establish WordPress directory approval,
compatibility with every host/plugin combination, or revenue readiness.

The harness uses WordPress 7.1.5 source structure and does not claim validation
of older WordPress or PHP releases.

## Primary WordPress references

- <https://developer.wordpress.org/reference/functions/wp_handle_upload/>
- <https://developer.wordpress.org/reference/functions/_wp_handle_upload/>
- <https://developer.wordpress.org/reference/hooks/wp_handle_upload/>

Core uses the default upload action to enforce PHP HTTP-upload provenance.
Its upload result is filterable; the plugin therefore validates its exact
reserved path before securing or cleaning up that path.
