<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE author_applications ADD CONSTRAINT author_rejection_reason CHECK (status<>'rejected' OR (decision_summary IS NOT NULL AND length(trim(decision_summary)) BETWEEN 10 AND 5000))");
        // Deferred checks allow the application/profile/role/audit writes to be atomic in any transaction.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_author_round_consistency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a author_applications%ROWTYPE; p author_profiles%ROWTYPE;
BEGIN
SELECT * INTO p FROM author_profiles WHERE id=NEW.author_profile_id;
SELECT * INTO a FROM author_applications WHERE author_profile_id=p.id ORDER BY round_number DESC LIMIT 1;
IF p.status<>a.status THEN RAISE EXCEPTION 'Current author profile and round must agree'; END IF;
IF a.status='approved' AND NOT EXISTS (SELECT 1 FROM model_has_roles m JOIN roles r ON r.id=m.role_id WHERE m.model_id=a.user_id AND m.model_type='App\Models\User' AND r.name='author' AND r.guard_name='web') THEN RAISE EXCEPTION 'Approved application requires existing author role'; END IF;
IF NOT EXISTS (SELECT 1 FROM author_application_events e WHERE e.author_application_id=NEW.id AND e.to_status=NEW.status) THEN RAISE EXCEPTION 'Author transition requires retained audit event'; END IF;
RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER author_round_consistency AFTER INSERT OR UPDATE ON author_applications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION templaara_author_round_consistency();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Author decision integrity requires forward migrations.');
    }
};
