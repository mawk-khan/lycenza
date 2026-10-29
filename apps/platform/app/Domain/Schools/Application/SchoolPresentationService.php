<?php

namespace App\Domain\Schools\Application;

use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\NavigationPresentationCatalogue as Catalogue;
use App\Support\Presentation\BrandColor;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ORG.12F (ADR 0080 §11.5-§11.7, OD-12.16–OD-12.19): the ONE writer of a
 * School's presentation profile -- navigation overrides per implemented
 * audience, and the School's brand (own label/accent, optional explicit
 * Network brand source).
 *
 * - Authority: `school.settings.manage`, re-read under the School lock
 *   (holdsInSchoolNow on an operational School). No new capability; no
 *   fresh MFA for ordinary presentation (not an approval workflow).
 * - Presentation only: keys come from the code-owned catalogue; overrides
 *   are stored only where they differ from code defaults (no backfill); a
 *   required item is never hidden; labels are plain text. Nothing here is
 *   read by authorization.
 * - Brand source: only a Group the School currently belongs to and that is
 *   active (also a composite key in the database). Never chosen by
 *   relationship type, never forced by a Network.
 * - Stale-safe: the caller submits the fingerprint of the profile it opened;
 *   a changed profile is refused. An unchanged save writes and audits nothing.
 * - Audited on the School ledger with keys and field names only.
 */
class SchoolPresentationService
{
    public const MANAGE = 'school.settings.manage';

    public const MAX_BRAND_LABEL = 60;

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** The fingerprint of an audience's stored overrides (stale-update check). */
    public function navigationFingerprint(School $school, string $audience): string
    {
        $rows = $this->context->withSchool($school, fn () => DB::table('school_navigation_preferences')
            ->where('school_id', $school->id)->where('audience', $audience)->orderBy('navigation_key')
            ->get(['navigation_key', 'hidden', 'sort_order', 'label_override'])->map(fn ($r) => (array) $r)->all());

        return hash('sha256', (string) json_encode($rows));
    }

    /**
     * @param  list<array{key?: mixed, hidden?: mixed, order?: mixed, label?: mixed}>  $items  the full desired presentation of the audience
     * @return list<string> the keys whose presentation changed
     */
    public function saveNavigation(User $actor, School $school, string $audience, array $items, string $expectedFingerprint): array
    {
        $catalogue = $this->catalogue($audience);
        $desired = [];
        foreach ($items as $index => $item) {
            $key = is_string($item['key'] ?? null) ? $item['key'] : '';
            if (! isset($catalogue[$key])) {
                throw ValidationException::withMessages(["items.{$index}.key" => 'That is not a navigation item of this audience.']);
            }
            $default = $catalogue[$key];
            $hidden = filter_var($item['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($hidden && ! $default['hideable']) {
                throw ValidationException::withMessages(["items.{$index}.hidden" => "\"{$default['label']}\" cannot be hidden."]);
            }
            $order = $item['order'] ?? null;
            if ($order !== null && (filter_var($order, FILTER_VALIDATE_INT) === false || (int) $order < 0 || (int) $order > 999)) {
                throw ValidationException::withMessages(["items.{$index}.order" => 'Use a position between 0 and 999.']);
            }
            $label = $this->plainLabel($item['label'] ?? null, Catalogue::MAX_LABEL, "items.{$index}.label");
            $row = [
                'hidden' => $hidden,
                'sort_order' => $order === null || (int) $order === $default['order'] ? null : (int) $order,
                'label_override' => $label === null || $label === $default['label'] ? null : $label,
            ];
            if ($row['hidden'] || $row['sort_order'] !== null || $row['label_override'] !== null) {
                $desired[$key] = $row;
            }
        }

        return DB::transaction(function () use ($actor, $school, $audience, $desired, $expectedFingerprint): array {
            $locked = $this->lock($actor, $school);
            if (! hash_equals($this->navigationFingerprint($locked, $audience), $expectedFingerprint)) {
                throw ValidationException::withMessages(['navigation' => 'The navigation changed since you opened this page. Reload and try again.']);
            }

            return $this->context->withSchool($locked, function () use ($actor, $locked, $audience, $desired): array {
                $current = DB::table('school_navigation_preferences')->where('school_id', $locked->id)->where('audience', $audience)
                    ->get(['navigation_key', 'hidden', 'sort_order', 'label_override'])->keyBy('navigation_key');
                $changes = ['hidden' => [], 'shown' => [], 'relabelled' => [], 'reordered' => []];
                $touched = [];
                foreach (array_unique([...array_keys($desired), ...$current->keys()->all()]) as $key) {
                    $before = $current->get($key);
                    $after = $desired[$key] ?? null;
                    // Strict, typed comparison: a new order of 0 is a change from "default" (null).
                    $was = ['hidden' => (bool) ($before->hidden ?? false), 'sort_order' => isset($before->sort_order) ? (int) $before->sort_order : null, 'label_override' => $before->label_override ?? null];
                    $now = $after ?? ['hidden' => false, 'sort_order' => null, 'label_override' => null];
                    if ($was === $now) {
                        continue;
                    }
                    $touched[] = $key;
                    if ($was['hidden'] !== $now['hidden']) {
                        $changes[$now['hidden'] ? 'hidden' : 'shown'][] = $key;
                    }
                    if ($was['label_override'] !== $now['label_override']) {
                        $changes['relabelled'][] = $key;
                    }
                    if ($was['sort_order'] !== $now['sort_order']) {
                        $changes['reordered'][] = $key;
                    }
                    if ($after === null) {
                        DB::table('school_navigation_preferences')->where('school_id', $locked->id)->where('audience', $audience)->where('navigation_key', $key)->delete();
                    } elseif ($before === null) {
                        DB::table('school_navigation_preferences')->insert([...$after, 'id' => (string) Str::uuid7(), 'school_id' => $locked->id, 'audience' => $audience, 'navigation_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                    } else {
                        DB::table('school_navigation_preferences')->where('school_id', $locked->id)->where('audience', $audience)->where('navigation_key', $key)->update([...$after, 'updated_at' => now()]);
                    }
                }
                if ($touched !== []) {
                    sort($touched);
                    $this->audit->school($locked, 'school.presentation.navigation_updated', actor: $actor, subject: $locked, metadata: [
                        'audience' => $audience,
                        ...array_filter($changes),
                    ]);
                }

                return $touched;
            });
        });
    }

    /** Clears every override of the audience (code defaults return; stale keys are removed too). */
    public function resetNavigation(User $actor, School $school, string $audience, string $expectedFingerprint): int
    {
        $this->catalogue($audience);

        return DB::transaction(function () use ($actor, $school, $audience, $expectedFingerprint): int {
            $locked = $this->lock($actor, $school);
            if (! hash_equals($this->navigationFingerprint($locked, $audience), $expectedFingerprint)) {
                throw ValidationException::withMessages(['navigation' => 'The navigation changed since you opened this page. Reload and try again.']);
            }

            return $this->context->withSchool($locked, function () use ($actor, $locked, $audience): int {
                $removed = DB::table('school_navigation_preferences')->where('school_id', $locked->id)->where('audience', $audience)->delete();
                if ($removed > 0) {
                    $this->audit->school($locked, 'school.presentation.navigation_reset', actor: $actor, subject: $locked, metadata: ['audience' => $audience, 'removed' => $removed]);
                }

                return $removed;
            });
        });
    }

    /** The fingerprint of the School's stored brand (stale-update check). */
    public function brandFingerprint(School $school): string
    {
        $row = $this->context->withSchool($school, fn () => DB::table('school_brand_profiles')->where('school_id', $school->id)
            ->first(['brand_source_group_id', 'display_label', 'primary_accent']));

        return hash('sha256', (string) json_encode($row === null ? null : (array) $row));
    }

    /**
     * The Groups this School may choose as its brand source: active Groups it
     * is currently a member of (platform tables; never another Group).
     *
     * @return list<array{id: string, name: string}>
     */
    public function eligibleBrandSources(School $school): array
    {
        return DB::table('school_group_members as m')->join('school_groups as g', 'g.id', '=', 'm.school_group_id')
            ->where('m.school_id', $school->id)->where('g.status', SchoolGroup::STATUS_ACTIVE)
            ->orderBy('g.name')->get(['g.id', 'g.name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all();
    }

    /** @return list<string> changed fields */
    public function saveBrand(User $actor, School $school, ?string $sourceGroupId, ?string $label, ?string $accent, string $expectedFingerprint): array
    {
        $sourceGroupId = $sourceGroupId === null || trim($sourceGroupId) === '' ? null : strtolower(trim($sourceGroupId));
        $label = $this->plainLabel($label, self::MAX_BRAND_LABEL, 'display_label');
        $accent = BrandColor::normalize($accent);
        if ($accent !== null && ! BrandColor::isValid($accent)) {
            throw ValidationException::withMessages(['primary_accent' => 'Use a colour like #1D4E89.']);
        }

        return DB::transaction(function () use ($actor, $school, $sourceGroupId, $label, $accent, $expectedFingerprint): array {
            $locked = $this->lock($actor, $school);
            if (! hash_equals($this->brandFingerprint($locked), $expectedFingerprint)) {
                throw ValidationException::withMessages(['brand' => 'The branding changed since you opened this page. Reload and try again.']);
            }
            // The membership row is share-locked: a concurrent removal waits, so the composite key cannot fail here.
            if ($sourceGroupId !== null && ! DB::table('school_group_members as m')->join('school_groups as g', 'g.id', '=', 'm.school_group_id')
                ->where('m.school_id', $locked->id)->where('m.school_group_id', $sourceGroupId)->where('g.status', SchoolGroup::STATUS_ACTIVE)
                ->lock('for share of m')->exists()) {
                throw ValidationException::withMessages(['brand_source_group_id' => 'Choose an active School Group this School belongs to.']);
            }

            return $this->context->withSchool($locked, function () use ($actor, $locked, $sourceGroupId, $label, $accent): array {
                $before = DB::table('school_brand_profiles')->where('school_id', $locked->id)->first(['brand_source_group_id', 'display_label', 'primary_accent']);
                $after = ['brand_source_group_id' => $sourceGroupId, 'display_label' => $label, 'primary_accent' => $accent];
                $changed = array_keys(array_filter($after, fn ($value, string $field): bool => $value !== ($before->{$field} ?? null), ARRAY_FILTER_USE_BOTH));
                if ($changed === []) {
                    return [];
                }
                if ($sourceGroupId === null && $label === null && $accent === null) {
                    DB::table('school_brand_profiles')->where('school_id', $locked->id)->delete();
                } elseif ($before === null) {
                    DB::table('school_brand_profiles')->insert([...$after, 'id' => (string) Str::uuid7(), 'school_id' => $locked->id, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('school_brand_profiles')->where('school_id', $locked->id)->update([...$after, 'updated_at' => now()]);
                }
                $this->audit->school($locked, 'school.presentation.brand_updated', actor: $actor, subject: $locked, metadata: [
                    'changed' => $changed,
                    'brand_source_group_id' => $sourceGroupId,
                ]);

                return $changed;
            });
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function catalogue(string $audience): array
    {
        if (! in_array($audience, Catalogue::AUDIENCES, true)) {
            throw ValidationException::withMessages(['audience' => 'That is not a presentation audience.']);
        }

        return Catalogue::items($audience);
    }

    private function lock(User $actor, School $school): School
    {
        $locked = School::query()->whereKey($school->id)->lock('for no key update')->firstOrFail();
        // Fresh authority under the lock (an operational School, the capability held now).
        $this->operational->requireOperational($locked->id);
        if (! $this->capabilities->holdsInSchoolNow($actor, self::MANAGE, $locked)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $locked;
    }

    private function plainLabel(mixed $value, int $max, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => 'Use plain text.']);
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max || preg_match('/[<>\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw ValidationException::withMessages([$field => "Use plain text of at most {$max} characters, without < or > or invisible characters."]);
        }

        return $value;
    }
}
