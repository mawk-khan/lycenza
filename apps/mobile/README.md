# apps/mobile — School OS Flutter client

Phase 0A skeleton only. See `docs/architecture/adr/0007-flutter-mobile-architecture.md`.

> **Note on this checkpoint:** the Flutter SDK is not installed in the
> environment this scaffold was authored in, so `flutter create`,
> `flutter pub get`, `flutter analyze`, and `flutter test` could not
> actually be executed here. This directory was hand-authored to match
> what `flutter create --org com.schoolos --project-name school_os_mobile`
> would produce, with `pubspec.yaml`, `lib/main.dart`, a lint config, and
> one widget test. **Before relying on this module, a developer with the
> Flutter SDK installed must run `flutter pub get`, `flutter analyze`,
> and `flutter test` to confirm it actually builds** — that has not been
> verified as part of this checkpoint.

## Local development (once verified)

```bash
flutter pub get
flutter analyze
flutter test
```
