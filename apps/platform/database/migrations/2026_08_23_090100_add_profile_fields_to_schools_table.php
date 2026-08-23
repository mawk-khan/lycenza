<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D section 7: genuine organizational profile fields only --
     * no government-identifier fields (section 7 explicitly defers
     * those pending a dedicated, more careful design). `status` is
     * untouched here: it remains the PLATFORM tenant-lifecycle column
     * established in Phase 0B (active|suspended|archived) -- section 8
     * deliberately does not add a School-editable operational status,
     * to keep platform tenant lifecycle and School operational
     * configuration structurally distinct (nothing in this migration or
     * `School.php`'s fillable list lets a School-scoped capability
     * touch `status`).
     *
     * `code` is a School-chosen short reference code, distinct from
     * `slug` (which exists for domain-routing/URL identity, ADR 0020).
     * `education_board_id` is the School's DEFAULT board (section 42's
     * chosen V1 model -- see docs/modules/ACADEMIC-STRUCTURE.md);
     * nullable because a School need not have configured one yet.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('code')->nullable()->unique()->after('legal_name');
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('IN');
            $table->foreignUuid('education_board_id')->nullable()->constrained('education_boards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('education_board_id');
            $table->dropColumn([
                'legal_name', 'code', 'website', 'email', 'phone',
                'address_line1', 'address_line2', 'city', 'state_region',
                'postal_code', 'country_code',
            ]);
        });
    }
};
