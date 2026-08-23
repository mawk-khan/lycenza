# ADR 0007: Flutter for the Mobile Client

- Status: Accepted
- Date: 2026-08-22

## Context

School OS needs mobile apps primarily for parents, teachers, and
students — audiences on a very wide range of Android device tiers
(India's Android install base skews toward budget/mid-range hardware)
plus a smaller iOS audience. The mobile app talks to the backend only
through the versioned `/api/v1` HTTP contract (ADR 0009) — it cannot
share Inertia's server-driven model the web console uses (ADR 0005).

## Decision

The mobile client (`apps/mobile`) is built in **Flutter/Dart**, as a
single codebase targeting Android and iOS, consuming the same versioned
`/api/v1` API that any third-party integration would use.

## Rationale

- One codebase for Android and iOS is significantly cheaper to build
  and maintain than two native codebases for a team of this size,
  without the runtime-bridge performance concerns of some
  React-Native-era approaches.
- Flutter's rendering approach gives consistent behavior across the
  wide range of Android device tiers common in the target market,
  rather than depending on OEM WebView/browser-engine variance the way
  a hybrid WebView-based app would.
- A native mobile app (rather than a mobile web wrapper of the Inertia
  console) is important for offline-tolerant flows (e.g. a teacher
  marking attendance in a low-connectivity classroom) that this product
  will need in later phases — that requirement rules out simply
  reusing the Inertia web UI in a WebView as the mobile strategy.
- Flutter has a mature plugin ecosystem for the kind of device
  integrations a school app eventually needs (push notifications,
  camera for document capture, biometric login, offline storage).

## Alternatives considered

1. **React Native.** Comparable cross-platform reach; rejected in favor
   of Flutter primarily for more predictable rendering consistency
   across low/mid-tier Android hardware and a more self-contained
   toolchain (single SDK/tool install vs. the JS-native-bridge
   ecosystem's larger surface of version-compatibility issues).
2. **Native Android (Kotlin) + native iOS (Swift), two codebases.**
   Rejected for this stage: doubles mobile engineering cost for a
   product whose Android and iOS feature sets need to stay in lockstep;
   revisit only if a specific platform-specific capability later
   demands it for one platform.
3. **Progressive Web App / mobile web wrapper around the Inertia
   console.** Rejected as the primary strategy: weaker offline story,
   worse native-feel UX for the audiences (parents, teachers) this app
   targets, though a lightweight PWA may still be useful later as a
   *secondary*, low-friction access path — not a replacement for the
   Flutter app.

## Consequences

- Flutter/Dart is a second application-layer language the team must
  maintain competency in, beyond PHP/TypeScript/Python.
- The mobile app has zero direct access to Laravel internals — it must
  treat `/api/v1` exactly as a third-party integration would (ADR
  0009), which is a useful forcing function for API quality but means
  mobile-only backend shortcuts are not available.
- `apps/mobile` in this Phase 0A checkpoint is a hand-authored skeleton,
  not scaffolded via `flutter create`, because the Flutter SDK was not
  available in the environment this repository was scaffolded in — see
  `apps/mobile/README.md`. A developer with the Flutter SDK installed
  must verify `flutter pub get` / `flutter analyze` / `flutter test`
  actually succeed before this module is relied upon.

## Future extraction/evolution path

No extraction applies here — this is already the intended long-term
architecture for mobile. If a specific platform (e.g. a very
low-end-Android-only market segment) later needs a lighter-weight
native path, that would be a new, additive client against the same
`/api/v1` contract, not a replacement of this decision.
