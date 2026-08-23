/// Thin HTTP client convention for calling the versioned School OS API
/// (see docs/architecture/API.md). Every request carries:
///  - an `Authorization` bearer token,
///  - an `X-Request-Id` for API audit correlation,
///  - implicit tenant scoping resolved server-side from the token —
///    the mobile app never passes a tenant id it chooses itself.
///
/// This is a Phase 0A placeholder: no real endpoints are wired up yet.
class ApiClient {
  ApiClient({required this.baseUrl});

  final String baseUrl;

  Uri endpoint(String path) => Uri.parse('$baseUrl/api/v1$path');
}
