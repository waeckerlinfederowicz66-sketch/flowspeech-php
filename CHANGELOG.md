# Changelog

## Unreleased

- Use fixed HTTP/terminal error messages and bounded, key-free ASCII code/trace
  tokens. Preserve status, Retry-After, recovery IDs and null job error semantics.
- Preserve safe message and boolean retryable fields in terminal error objects;
  omit unrecognized nested error metadata.
- Omit exception call arguments from string rendering, including private result
  URLs. Add contract and actual CLI output regressions with fixture credentials.
- These local candidate changes are not in the published 0.2.0 package.

## 0.2.0

- Require valid response request UUIDs, normalize returned/recovery IDs, and pin
  both identities through polling. Independent reads/cancellation clear stale IDs.
- Preserve the actual quota.subscription field (including null), without requiring
  the outdated entitlement field.
- Verify the production cURL transport with real PHPUnit and loopback TLS tests.

- Migrate to public API v1 quota, voices and asynchronous speech jobs.
- Preserve single/dialogue helpers with explicit input mapping and locally
  encoded compatibility audio; add structured segments, recovery and cancellation.
- Add stable UUID retries, Retry-After, total deadlines, structured exceptions,
  strict result validation and bounded, credential-free WAV downloads.
- Add injectable fake transport/clock, contract tests, cURL option tests and
  allowlisted archive packaging.
- Require an active paid API subscription and developer key. Legacy endpoints
  and synchronous API base64 responses are no longer used.

## Previous Development Version

- Initial untagged Composer development client.
