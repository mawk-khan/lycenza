import time
from dataclasses import dataclass, field


@dataclass(frozen=True)
class AuditEntry:
    school_id: str
    agent: str
    tool: str | None
    action: str
    at: float = field(default_factory=time.time)


class AuditLedger:
    """In-process AI Audit Log used for local dev and tests.

    A real deployment persists entries durably (own store or via a
    Laravel audit contract) and never lets an entry be deleted or
    mutated. Every model call and every tool invocation must produce an
    entry here. See docs/ai/AI-SECURITY.md and
    docs/architecture/adr/0013-ai-gateway-architecture.md.

    Field is `school_id`, not `tenant_id` -- see
    docs/architecture/adr/0020-tenant-identifier-terminology.md:
    School is the tenant, and `school_id` is the one canonical name for
    it everywhere in this system, including this cross-language
    boundary.
    """

    def __init__(self) -> None:
        self._entries: list[AuditEntry] = []

    def record(self, entry: AuditEntry) -> None:
        self._entries.append(entry)

    def entries_for_school(self, school_id: str) -> list[AuditEntry]:
        return [e for e in self._entries if e.school_id == school_id]


audit_ledger = AuditLedger()
