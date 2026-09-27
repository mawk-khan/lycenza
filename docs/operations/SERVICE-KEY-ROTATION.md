# Runbook: service-key rotation and emergency revocation (ADR 0053, OBS-27)

Laravel and the AI Gateway authenticate each other with per-request Ed25519
**service assertions** (ADR 0053). Each calling service holds its own private
signing key, and each receiver holds only the caller's public keys (a
verification **ring**). No real key exists yet: this runbook is how an
operator will rotate keys once a deployment has them.

| Direction | Caller's private key (secret store) | Receiver's public ring (configuration) |
|---|---|---|
| Laravel → Gateway | `AI_GATEWAY_SERVICE_SIGNING_KEY` (Laravel web + workers), identity `platform` | `PLATFORM_VERIFICATION_KEYS` (Gateway) |
| Gateway → Laravel | `SERVICE_SIGNING_KEY` (Gateway), identity `ai-gateway` | `AI_GATEWAY_INBOUND_VERIFICATION_KEYS` (Laravel web) |

**Rules that never change:**
- **Key format:** private keys are one RFC 8037 OKP JWK
  (`{kty,crv,kid,x,d,created}`), Highly Sensitive, and live only in the
  managed secret store.
- **Ring format:** a ring is a JSON list of 1–2 public JWKs
  (`{kty,crv,kid,x,created[,not_after]}`): exactly one *steady* key and at
  most one *transitional* key, whose `not_after` is at most 24 h ahead. A
  transitional key stops verifying at `not_after` even if it is still
  configured.
- **Key life:** keys are refused after **90 days** (`created`), and OBS-27
  warns from day **76**.
- **Change means restart:** keys are read at process start, so any key or
  ring change is a restart of the affected processes; there is no runtime
  reload.
- **Never** reuse `APP_KEY`, the AI context signing key, a webhook secret or
  any other secret as a service key. Never log, commit or bake a key into an
  image.

**Tooling** (in the application image; no key material is ever printed):
- `php artisan platform:service-key-generate {platform|ai-gateway} --output=<dir outside the app> [--kid=]`:
  writes `<kid>.private.jwk` with mode 0600 and `<kid>.public.jwk`, and
  prints only the kid and the public JWK.
- `php artisan platform:verify-service-auth` (read-only): reports the active
  signing kid and its age, the ring's kids, ages and transition end, and the
  guard rules.
- The Gateway reports the same through its startup guard and `/health/ready`
  (503 on any violation, without a reason).

## 1. Routine rotation (at most every 90 days)

Rotate **one direction at a time.** The steps below use the Gateway → Laravel
direction (`ai-gateway` key, Laravel ring); the other direction is the same
with the roles swapped.

1. **Generate** a keypair outside request processing:
   ```
   platform:service-key-generate ai-gateway --output=/secure/tmp
   ```
   Put the private JWK into the secret store as the new version of the
   Gateway's `SERVICE_SIGNING_KEY`, **not yet active**. Destroy the file.
2. **Stage the receiver.** Set Laravel's
   `AI_GATEWAY_INBOUND_VERIFICATION_KEYS` to
   `[<old steady>, <new public JWK + "not_after": "<now + ≤24 h>">]`.
   Deploy it to **every** Laravel web replica and restart them.
3. **Confirm** that every replica loaded the new ring: run
   `platform:verify-service-auth` on each (the ring lists both kids), or use
   the deployment's config inventory. Do not continue until all replicas
   report it. This ordering is what guarantees there is never a moment with
   no accepted key.
4. **Switch the caller.** Make the new private key active for the Gateway,
   then restart every Gateway replica.
5. **Observe** for at least a few minutes:
   - `lycenza_service_auth_total{direction="gateway_to_platform",outcome="success"}`
     keeps rising;
   - there are no `unknown_kid`, `bad_signature` or `key_expired`
     increments (OBS-27 stays quiet);
   - Laravel's `service_auth.*` log lines show the **new** kid.
6. **Retire the old key.** Set the ring to
   `[<new steady (no not_after)>, <old + "not_after": "<now + a few minutes>">]`,
   or drop the old key entirely. Deploy it to every web replica and restart
   them. Once `not_after` passes, the old key is refused even while still
   configured; remove it at the next config change.
7. **Destroy** the old private key version in the secret store.
8. **Record evidence** (Confidential; kids and dates only, **never key
   material**): the date, direction, old and new kid, operator, the metrics
   observed in step 5, and when the old key was destroyed.

## 2. Rollback of a failed planned rotation

If step 4 or 5 shows authentication failures, for example a mis-copied key
or a replica with the old ring:

1. **Revert the caller.** Switch the caller back to the **old** private key
   and restart its replicas. The receiver still trusts both keys, so traffic
   resumes at once.
2. **Fix the cause:** redistribute the ring and repeat steps 2–3.
3. **Retry** from step 4. Retire the old key only after new-key success has
   been observed.

Rollback applies **only** to a planned rotation. **A compromised key is never
rolled back to** (§3).

## 3. Emergency revocation (key compromise)

There is no 24-hour overlap and no waiting. Removing the public key revokes
every assertion it ever signed.

1. **Remove the compromised key** from the receiver ring, keeping or staging
   only a known-good or brand-new key. Deploy to **every** receiver replica
   and restart them immediately. Calls signed with the compromised kid now
   fail closed (`unknown_kid`). If no replacement is ready yet, the AI
   integration stops; the ERP itself is unaffected, because the Gateway is
   optional.
2. **Generate a replacement** (§1 step 1). Install its public key as the
   ring's steady key and its private key at the caller, then restart the
   caller replicas.
3. **Verify the old kid is refused:** a deliberate assertion from the old key
   in a non-production environment, or the receiver's logs showing
   `"outcome":"unknown_kid"` with the old kid.
4. **Investigate:**
   - use the logs, which carry the kid, to find where and when the
     compromised kid was used during the exposure window;
   - scope the compromise by host. A Gateway host held only its own
     `ai-gateway` private key. A Laravel host also held the `platform`
     private key **and** the separate AI context signing key: rotate those
     too (the context key through its own procedure, ADR 0050 §5).
5. **Record** the incident evidence (Confidential), following
   [SUPPLY-CHAIN-INCIDENTS](SUPPLY-CHAIN-INCIDENTS.md)'s incident-record
   conventions.

## 4. OBS-27

- **Key age at 76 days or more:** start a routine rotation (§1). The hard
  stop at 90 days disables AI calls until the rotation is done.
- **`unknown_kid`, `bad_signature` or `key_expired`:** a replica is running
  an outdated ring or key, a transitional key expired before the caller
  switched, or someone is presenting a foreign key. Check
  `platform:verify-service-auth` and the Gateway's readiness on every
  replica. If an unexpected kid is involved, treat it as §3.
- **`rejected_by_receiver`** (Laravel's calls to the Gateway refused): the
  Gateway does not trust Laravel's current `platform` key. Check its ring.

## Deployment evidence still outstanding (ADR 0053 §15)

None of the following has been done; no deployment exists (rule 16):
- real keys installed through the managed secret store;
- the correct ring on every replica;
- one routine rotation (§1) performed in a non-production environment;
- one emergency revocation (§3) demonstrated;
- the real private TLS network, including TLS in front of the Gateway.
