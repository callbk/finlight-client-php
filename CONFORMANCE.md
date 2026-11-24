# Conformance map

Every case of the finlight API v3 contract kit (`conformance/`, version in `conformance/CONTRACT_VERSION`)
runs as its own PHPUnit test. This table maps each case id to its test: `Class::method` in
`tests/Conformance/`, and `#<data set>` for data-provider cases. `tests/Conformance/CaseMapTest.php` fails
when a case has no row or a row points to a test that does not exist.

How the cases run in PHP:

- HTTP cases (R, P, E, F) run through the injected PSR-18 mock (`tests/Support/MockHttpClient.php`), so the
  request is checked after serialisation: method, path, decoded query, parsed body and headers.
- PHP has no WebSocket transport. The S cases run at the decoder level: `Subscribe` builds what a loop
  sends, `Frame::decode()` reads what the server sends, and a reference loop without I/O
  (`tests/Support/SessionModel.php`) applies the connection rules from the README to reach the outcome.
  S-02 writes the dial headers to a TCP listener on 127.0.0.1 and reads the raw bytes.
- S-29: `Subscribe` does not check vocabularies, so it builds the frame with the unknown `sourceTypes` value;
  the test checks the server's `VALIDATION_FAILED` as decoded (field `sourceTypes`, `allowedValues`).

What the S cases verify of the SDK itself (PHP stops at the decoder level, by owner decision):

- Rows without a mark: the outcome comes from SDK code (`Subscribe`, `Frame::decode()`, the decoded
  `FinlightApiException` and its kind).
- `(part ref)`: the SDK decodes the frame (preempt reason, error code and its exception class); the
  reconnect class that follows is decided by the reference loop in `tests/Support/SessionModel.php`.
- `(ref)`: decoder level only. The outcome is decided by the reference loop in `tests/Support`, not by
  code in `src/`. S-04 (dedupe), S-16 (watchdog) and S-18 (rotation) are loop rules; S-19 to S-28
  (refused dials: status and body, long wait, takeover hint, hourly quota wait) have no SDK code
  at all, since PHP has no transport that dials. S-02 only shows that `Subscribe::dialHeaders()` uses
  lowercase names; the bytes are written by the test. These cases document the rules the README
  gives for your own loop and are not counted as SDK conformance.

| Case | Title | Test |
|---|---|---|
| V-01 | Known constants equal vocab.json | `VocabTest::testKnownConstantsEqualVocab` |
| D-01 | Full article: every include, every entitlement | `DecodeTest::testDecodeFixture#D-01` |
| D-02 | Raw skeleton (raw channel ignores include and entitlements) | `DecodeTest::testDecodeFixture#D-02` |
| D-03 | No include, every entitlement | `DecodeTest::testDecodeFixture#D-03` |
| D-04 | Every include requested, no entitlement | `DecodeTest::testDecodeFixture#D-04` |
| D-05 | events requested and entitled, companies not requested | `DecodeTest::testDecodeFixture#D-05` |
| D-06 | events and companies requested, entityAccess missing | `DecodeTest::testDecodeFixture#D-06` |
| D-07 | Legacy values delivered as they are | `DecodeTest::testDecodeFixture#D-07` |
| D-08 | Non-ASCII text | `DecodeTest::testDecodeFixture#D-08` |
| D-09 | Sentiment with a null label, event with null direction/importance/confidence, companies [] | `DecodeTest::testDecodeFixture#D-09` |
| D-10 | Decode matrix (include x entitlements x enriched/raw) | `DecodeTest::testMatrix#D-10.*` |
| D-11 | Blank summary and content arrive as null | `DecodeTest::testDecodeFixture#D-11` |
| F-01 | Unknown fields at every level of the article | `ForwardCompatTest::testUnknownFieldsAtEveryLevel` |
| F-02 | Unknown values in every open response enum | `ForwardCompatTest::testUnknownEnumValuesAreKept` |
| F-03 | New keys on REST, webhook and WebSocket envelopes | `ForwardCompatTest::testNewEnvelopeKeysAreIgnored` |
| F-04 | Unknown webhook event name, unknown problem code, unknown 429 limitType | `ForwardCompatTest::testUnknownEventsCodesAndLimitTypes` |
| R-01 | articles.search with one parameter sends exactly that parameter | `RequestsTest::testRequestFixture#R-01` |
| R-02 | articles.search with every parameter | `RequestsTest::testRequestFixture#R-02` |
| R-03 | articles.search without parameters | `RequestsTest::testRequestFixture#R-03` |
| R-04 | articles.get with include, comma-joined | `RequestsTest::testRequestFixture#R-04` |
| R-05 | articles.getByLink URL-encodes the link | `RequestsTest::testRequestFixture#R-05` |
| R-06 | companies.search with q and mic | `RequestsTest::testRequestFixture#R-06` |
| R-07 | companies.getMany joins ids with commas | `RequestsTest::testRequestFixture#R-07` |
| R-08 | companies.get returns ETag and Cache-Control | `RequestsTest::testRequestFixture#R-08` |
| R-09 | companies.get with If-None-Match answers 304 | `RequestsTest::testRequestFixture#R-09` |
| R-10 | sources.list with fullArticleAccess | `RequestsTest::testRequestFixture#R-10` |
| R-11 | sources.list without fullArticleAccess | `RequestsTest::testRequestFixture#R-11` |
| R-12 | An unknown request key is dropped silently by the server | `RequestsTest::testRequestFixture#R-12` |
| R-13 | WebSocket subscribe frame, enriched | `RequestsTest::testRequestFixture#R-13` |
| R-14 | WebSocket subscribe frame, raw | `RequestsTest::testRequestFixture#R-14` |
| P-01 | Three-page cursor walk | `PaginationTest::testThreePageWalk` |
| P-02 | limit and include may change between pages | `PaginationTest::testServerRule#P-02` |
| P-03 | Adding "to" on page 2 breaks the cursor | `PaginationTest::testServerRule#P-03` |
| P-04 | Adding the default languages ["en"] on page 2 breaks the cursor | `PaginationTest::testServerRule#P-04` |
| P-05 | Changing order on page 2 | `PaginationTest::testServerRule#P-05` |
| P-06 | Reordering list values keeps the cursor valid | `PaginationTest::testServerRule#P-06` |
| E-01 | Unknown enum value | `ErrorsTest::testErrorCase#E-01` |
| E-02 | Several invalid fields at once | `ErrorsTest::testErrorCase#E-02` |
| E-03 | Unknown field in the query language | `ErrorsTest::testErrorCase#E-03` |
| E-04 | Empty languages array | `ErrorsTest::testErrorCase#E-04` |
| E-05 | Unknown include value on a GET lookup | `ErrorsTest::testErrorCase#E-05` |
| E-06 | companies.search without q and ids | `ErrorsTest::testErrorCase#E-06` |
| E-07 | companies.search with q and ids | `ErrorsTest::testErrorCase#E-07` |
| E-08 | Non-integer company id in ids | `ErrorsTest::testErrorCase#E-08` |
| E-09 | Non-integer companyId in the path | `ErrorsTest::testErrorCase#E-09` |
| E-10 | Cursor that is not a cursor | `ErrorsTest::testErrorCase#E-10` |
| E-11 | Cursor of an unsupported version | `ErrorsTest::testErrorCase#E-11` |
| E-12 | No API key | `ErrorsTest::testErrorCase#E-12` |
| E-13 | Unknown API key | `ErrorsTest::testErrorCase#E-13` |
| E-14 | Company lookup without entityAccess | `ErrorsTest::testErrorCase#E-14` |
| E-15 | Unknown article id | `ErrorsTest::testErrorCase#E-15` |
| E-16 | Unknown article link | `ErrorsTest::testErrorCase#E-16` |
| E-17 | Unknown company id | `ErrorsTest::testErrorCase#E-17` |
| E-18 | Monthly token limit reached | `ErrorsTest::testErrorCase#E-18` |
| E-19 | Burst limit reached | `ErrorsTest::testErrorCase#E-19` |
| E-20 | Server failure | `ErrorsTest::testErrorCase#E-20` |
| E-21 | Malformed JSON body (NestJS fallback form) | `ErrorsTest::testErrorCase#E-21` |
| E-22 | Unknown route under /v3 (NestJS fallback form) | `ErrorsTest::testErrorCase#E-22` |
| E-23 | No X-Request-Id sent: the server generates one | `ErrorsTest::testErrorCase#E-23` |
| E-24 | Non-JSON error body | `ErrorsTest::testErrorCase#E-24` |
| E-25 | Cursor issued for another sort order | `ErrorsTest::testErrorCase#E-25` |
| W-01 | article.created, enriched, every include, non-ASCII body | `WebhooksTest::testWebhookCase#W-01` |
| W-02 | article.updated with changedFields | `WebhooksTest::testWebhookCase#W-02` |
| W-03 | article.updated for a plan without fullArticleAccess | `WebhooksTest::testWebhookCase#W-03` |
| W-04 | article.created on a raw webhook | `WebhooksTest::testWebhookCase#W-04` |
| W-05 | Dashboard test webhook (evt_test…) | `WebhooksTest::testWebhookCase#W-05` |
| W-06 | v2 body is reported as wrong payload version | `WebhooksTest::testWebhookCase#W-06` |
| W-07 | Body changed after signing | `WebhooksTest::testWebhookCase#W-07` |
| W-08 | Timestamp older than the tolerance | `WebhooksTest::testWebhookCase#W-08` |
| W-09 | Timestamp further in the future than the tolerance | `WebhooksTest::testWebhookCase#W-09` |
| W-10 | Timestamp header is not ISO 8601 | `WebhooksTest::testWebhookCase#W-10` |
| W-11 | Timestamp in unix seconds instead of ISO 8601 | `WebhooksTest::testWebhookCase#W-11` |
| W-12 | No X-Webhook-Timestamp header | `WebhooksTest::testWebhookCase#W-12` |
| W-13 | No X-Webhook-Signature header | `WebhooksTest::testWebhookCase#W-13` |
| W-14 | Signature without the sha256= prefix | `WebhooksTest::testWebhookCase#W-14` |
| W-15 | Signature computed with the hex-decoded secret | `WebhooksTest::testWebhookCase#W-15` |
| W-16 | Case-insensitive header lookup | `WebhooksTest::testWebhookCase#W-16` |
| S-01 | Ping before subscribe gets a v2 pong; after the v3 subscribe pongs are v3 | `SessionsTest::testSession#S-01` |
| S-02 | Lowercase x-api-key on the wire (ref) | `WsRawBytesTest::testLowercaseApiKeyOnTheWire` |
| S-03 | connection.admitted echoes the nonce; a re-subscribe re-admits only with the same nonce | `SessionsTest::testSession#S-03` |
| S-04 | article.created then article.updated for the same id, then an exact duplicate (ref) | `SessionsTest::testSession#S-04` |
| S-05 | An unknown event name arrives | `SessionsTest::testSession#S-05` |
| S-06 | connection.error PING_ON_DEFAULT_ROUTE | `SessionsTest::testSession#S-06` |
| S-07 | VALIDATION_FAILED for a v2 key in a v3 subscribe | `SessionsTest::testSession#S-07` |
| S-08 | VALIDATION_FAILED for include on raw | `SessionsTest::testSession#S-08` |
| S-09 | connection.preempted, reason connection_limit (another connection took over) (part ref) | `SessionsTest::testSession#S-09` |
| S-10 | connection.preempted, reason quota_exceeded (part ref) | `SessionsTest::testSession#S-10` |
| S-11 | Preempted before the first subscribe: the frame comes in v2 form | `SessionsTest::testSession#S-11` |
| S-12 | Error for an unreadable message comes in v2 form | `SessionsTest::testSession#S-12` |
| S-13 | connection.error HANDSHAKE_FAILED (part ref) | `SessionsTest::testSession#S-13` |
| S-14 | connection.error SEARCH_FAILED (part ref) | `SessionsTest::testSession#S-14` |
| S-15 | connection.error INTERNAL_ERROR | `SessionsTest::testSession#S-15` |
| S-16 | No pong for 60 s (ref) | `SessionsTest::testSession#S-16` |
| S-17 | Raw stream: admitted, no initial push, skeleton frames | `SessionsTest::testSession#S-17` |
| S-18 | Proactive rotation before the 2 h connection lifetime (ref) | `SessionsTest::testSession#S-18` |
| S-19 | Dial refused: no API key (ref) | `SessionsTest::testSession#S-19` |
| S-20 | Dial refused: invalid API key (ref) | `SessionsTest::testSession#S-20` |
| S-21 | Dial refused: API key in the wrong format (ref) | `SessionsTest::testSession#S-21` |
| S-22 | Dial refused: plan without WebSockets (ref) | `SessionsTest::testSession#S-22` |
| S-23 | Dial refused: connection limit reached, no takeover (ref) | `SessionsTest::testSession#S-23` |
| S-24 | Dial refused: WebSocket quota exhausted (ref) | `SessionsTest::testSession#S-24` |
| S-25 | Dial refused: lost the admit race (ref) | `SessionsTest::testSession#S-25` |
| S-26 | Dial refused: server error (ref) | `SessionsTest::testSession#S-26` |
| S-27 | connection.preempted, reason quota_exceeded, with quotaReconnect turned off (ref) | `SessionsTest::testSession#S-27` |
| S-28 | Dial refused: WebSocket quota exhausted, with quotaReconnect turned off (ref) | `SessionsTest::testSession#S-28` |
| S-29 | VALIDATION_FAILED for an unknown sourceTypes value | `SessionsTest::testSession#S-29` |

Total: 106 cases. 92 verify SDK code, 4 of them only up to the decoded frame `(part ref)`; 14 are
marked `(ref)` and verify only the reference loop in `tests/Support`.
