# Security Gate - unique-number validation without login

Security Gate is a public, narrowly scoped PWA at `/security/gate`. It does not create an application login and never lists documents. The driver must bring the random 12-character unique number issued after signed handover.

## Operational flow

1. PPC creates and submits the SMB.
2. Procurement & Sales approves.
3. HRA Department Head approves; the configured HRA Section Head can act as substitute.
4. The warehouse pickup PIC confirms pickup and enters the actual handler name.
5. PPC uploads the signed driver/recipient evidence. The application issues a random code such as `AB12-CD34-EF56`.
6. Security opens `/security/gate`, enters the exact code, reviews the full SMB and signed evidence, enters the actual officer name, and confirms goods, vehicle, and document checks.
7. The server locks and rechecks the document, records release, closes the code, and marks the SMB Completed.

## Security and audit boundaries

- The code is random, belongs to one SMB, and is looked up by a keyed hash. Its displayable value is encrypted at rest.
- A verified code grants the current browser session access only to that one document; document IDs cannot be enumerated through the gate.
- Invalid lookups and approvals are rate limited. The code is never placed in a URL.
- Release requires current signed evidence, the active Security workflow step, all three physical-check confirmations, and a fresh inspection version.
- Double submission and stale pages are rejected. The code is marked used after release.
- Audit history records the actual Security name, timestamp, channel, transition, and notes. The internal `security.gate` service account cannot log in and only supplies the workflow actor required by the audit model.
- Yasin's MKM account receives workflow notifications; it is not used as the identity of every Security officer.

Use HTTPS on shared/LAN hosting. The PWA remains online-only for code lookup and approval. Keep the unique number with the driver and do not send it to unrelated parties.
