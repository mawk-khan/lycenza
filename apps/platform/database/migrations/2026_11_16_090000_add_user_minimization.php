<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.4 (E21-L1 project-adopted, India-aligned development position,
 * pending qualified ratification): User identity minimization and the F1
 * database-safety fix.
 *
 * - F1: the runtime role loses DELETE on `users`. A raw delete would
 *   otherwise null audit and grant actors (SET NULL) and delete every
 *   membership of the User in every School (CASCADE). No User is ever
 *   hard-deleted by the application; physical destruction is not
 *   authorized (E21.4 §35).
 * - `users.minimized_at`: the lifecycle. NULL is a current User; a value is
 *   a minimized (tombstoned) User, kept only as the stable non-login
 *   identity retained history refers to. The database enforces its shape
 *   (`users_minimized_shape`): disabled, the neutral name, a non-routable
 *   address derived from the id alone (`minimized-<id>@users.invalid`, RFC
 *   2606), no verification time and no remember token. It also makes the
 *   tombstone immutable (`trg_users_minimized_immutable`): no column that
 *   carries identity or credentials can change again, and it can never be
 *   un-minimized. Re-establishing a person means a new User through the
 *   normal invitation flows.
 * - A tombstone can never become a current principal again: every insert or
 *   reactivation that makes a User current (a membership, a role or Group
 *   or platform grant, an Employee link, a Guardian/Student account link,
 *   an elevation, a personal access token) refuses a minimized User
 *   (`users_assert_not_minimized`). The check takes FOR SHARE on the User,
 *   which conflicts with the minimization's FOR UPDATE: whichever commits
 *   first wins, and the other sees it.
 *
 * Rollback removes the lifecycle (refusing once any User is minimized: a
 * tombstone without its marker would read as an ordinary disabled account)
 * and the guards. It deliberately does NOT re-grant DELETE on `users`: F1
 * is a security defect, and no rollback restores it.
 */
return new class extends Migration
{
    /** table => [user or membership column, guard function] */
    private const GUARDS = [
        'school_memberships' => 'users_guard_membership',
        'membership_role_assignments' => 'users_guard_membership_child',
        'student_guardian_account_links' => 'users_guard_membership_child',
        'employees' => 'users_guard_principal_column',
        'platform_role_assignments' => 'users_guard_principal_column',
        'group_role_assignments' => 'users_guard_principal_column',
        'school_elevations' => 'users_guard_principal_column',
        'personal_access_tokens' => 'users_guard_access_token',
    ];

    private const COLUMN = [
        'employees' => 'user_id', 'platform_role_assignments' => 'user_id', 'group_role_assignments' => 'user_id', 'school_elevations' => 'actor_user_id',
    ];

    public function up(): void
    {
        DB::statement('REVOKE DELETE ON users FROM school_os_app');

        DB::statement('ALTER TABLE users ADD COLUMN minimized_at timestamp NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_minimized_shape CHECK (
                minimized_at IS NULL OR (
                    is_disabled AND disabled_at IS NOT NULL
                    AND name = 'Former user'
                    AND email = 'minimized-' || id::text || '@users.invalid'
                    AND email_verified_at IS NULL
                    AND remember_token IS NULL
                )
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION users_minimized_immutable() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.minimized_at IS NOT NULL THEN
                        RAISE EXCEPTION 'users: a User is created current, never minimized (users_minimized)';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.minimized_at IS NOT NULL AND (
                       NEW.minimized_at IS DISTINCT FROM OLD.minimized_at
                    OR NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at
                    OR NEW.password IS DISTINCT FROM OLD.password
                    OR NEW.remember_token IS DISTINCT FROM OLD.remember_token
                    OR NEW.is_disabled IS DISTINCT FROM OLD.is_disabled
                    OR NEW.disabled_at IS DISTINCT FROM OLD.disabled_at
                    OR NEW.credential_version IS DISTINCT FROM OLD.credential_version) THEN
                    RAISE EXCEPTION 'users: a minimized User is immutable and never restored (users_minimized)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_users_minimized_immutable BEFORE INSERT OR UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION users_minimized_immutable();

            -- Refuses a minimized User as a current principal. FOR SHARE conflicts with the
            -- minimization's FOR UPDATE, so the two serialize on the User row.
            CREATE FUNCTION users_assert_not_minimized(p_user_id uuid) RETURNS void
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_minimized timestamp;
            BEGIN
                IF p_user_id IS NULL THEN
                    RETURN;
                END IF;
                SELECT minimized_at INTO v_minimized FROM public.users WHERE id = p_user_id FOR SHARE;
                IF v_minimized IS NOT NULL THEN
                    RAISE EXCEPTION 'users: a minimized User cannot become a current principal (users_minimized)';
                END IF;
            END;
            $$;

            -- A membership that is (or becomes) invited or active.
            CREATE FUNCTION users_guard_membership() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF NEW.status IN ('invited', 'active') AND (TG_OP = 'INSERT' OR NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.status IS DISTINCT FROM OLD.status) THEN
                    PERFORM public.users_assert_not_minimized(NEW.user_id);
                END IF;
                RETURN NEW;
            END;
            $$;

            -- A role grant or an account link on a membership.
            CREATE FUNCTION users_guard_membership_child() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'INSERT' OR NEW.school_membership_id IS DISTINCT FROM OLD.school_membership_id THEN
                    PERFORM public.users_assert_not_minimized((SELECT user_id FROM public.school_memberships WHERE id = NEW.school_membership_id));
                END IF;
                RETURN NEW;
            END;
            $$;

            -- A column that names the User directly (TG_ARGV[0]).
            CREATE FUNCTION users_guard_principal_column() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_new uuid := (to_jsonb(NEW) ->> TG_ARGV[0])::uuid;
            BEGIN
                IF v_new IS NOT NULL AND (TG_OP = 'INSERT' OR v_new IS DISTINCT FROM (to_jsonb(OLD) ->> TG_ARGV[0])::uuid) THEN
                    PERFORM public.users_assert_not_minimized(v_new);
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE FUNCTION users_guard_access_token() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF NEW.tokenable_type = 'App\Models\User' THEN
                    PERFORM public.users_assert_not_minimized(NEW.tokenable_id);
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        foreach (self::GUARDS as $table => $function) {
            $argument = isset(self::COLUMN[$table]) ? "'".self::COLUMN[$table]."'" : '';
            $when = $table === 'personal_access_tokens' ? 'INSERT' : 'INSERT OR UPDATE';
            DB::statement("CREATE TRIGGER trg_{$table}_not_minimized BEFORE {$when} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}({$argument})");
        }

        DB::statement('REVOKE ALL ON FUNCTION users_assert_not_minimized(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION users_assert_not_minimized(uuid) TO school_os_app');
    }

    public function down(): void
    {
        if (DB::table('users')->whereNotNull('minimized_at')->exists()) {
            throw new RuntimeException('Refusing to roll back: a User has been minimized (E21.4). The removed identity cannot be restored, and dropping the marker would make a tombstone read as an ordinary disabled account.');
        }

        foreach (array_keys(self::GUARDS) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_not_minimized ON {$table}");
        }
        foreach (['users_guard_access_token()', 'users_guard_principal_column()', 'users_guard_membership_child()', 'users_guard_membership()', 'users_assert_not_minimized(uuid)'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}");
        }
        DB::statement('DROP TRIGGER IF EXISTS trg_users_minimized_immutable ON users');
        DB::statement('DROP FUNCTION IF EXISTS users_minimized_immutable()');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_minimized_shape');
        DB::statement('ALTER TABLE users DROP COLUMN minimized_at');
        // DELETE on users is deliberately NOT re-granted: F1 stays fixed.
    }
};
