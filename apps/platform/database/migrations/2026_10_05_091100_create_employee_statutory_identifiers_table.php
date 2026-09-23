<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0036, ADR 0041's pattern) -- PAN, UAN, PF
     * Member ID, ESIC IP Number. Searchable PII, not plaintext:
     * `encrypted_value` (Laravel's `encrypted` cast) holds the real
     * value; `lookup_hash` (a keyed HMAC-SHA-256 digest via the new
     * `App\Support\Privacy\StatutoryIdentifierLookupHasher`, its own
     * domain-separation prefix -- never `ContactLookupHasher`'s
     * guardian-contact-specific one) supports duplicate-identifier
     * detection within a School without a full-table decrypt scan.
     * Exactly ADR 0041's `guardian_contacts` shape: `unique(school_id,
     * employment_record_id, identifier_type)` (one PAN/UAN/etc. per
     * EmploymentRecord), `index(school_id, identifier_type,
     * lookup_hash)` for the duplicate-candidate lookup.
     */
    public function up(): void
    {
        Schema::create('employee_statutory_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->string('identifier_type'); // pan|uan|pf_member_id|esic_ip_number
            $table->text('encrypted_value');
            $table->string('lookup_hash', 64);
            $table->unsignedInteger('lookup_key_version')->default(1);
            $table->timestamps();

            $table->unique(['school_id', 'employment_record_id', 'identifier_type']);
            $table->index(['school_id', 'identifier_type', 'lookup_hash']);
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE employee_statutory_identifiers ADD CONSTRAINT employee_statutory_identifiers_type_check CHECK (identifier_type IN ('pan', 'uan', 'pf_member_id', 'esic_ip_number'))");

        TenantRls::enable('employee_statutory_identifiers');
    }

    public function down(): void
    {
        TenantRls::disable('employee_statutory_identifiers');
        Schema::dropIfExists('employee_statutory_identifiers');
    }
};
