<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('author_profiles', function (Blueprint $t): void {
            $t->string('professional_headline', 160)->nullable();
            $t->unsignedSmallInteger('years_of_experience')->nullable();
            $t->unsignedSmallInteger('founded_year')->nullable();
            $t->string('team_size', 20)->nullable();
            $t->string('state_region', 100)->nullable();
            $t->string('city', 100)->nullable();
            $t->boolean('public_location')->default(false);
            $t->jsonb('languages')->nullable();
            $t->string('support_availability', 200)->nullable();
            $t->text('confidential_work')->nullable();
            $t->unsignedInteger('revision')->default(0);
        });
        foreach (['technology' => 'technologies', 'category' => 'categories'] as $singular => $table) {
            Schema::create('author_profile_'.$singular, function (Blueprint $t) use ($singular, $table): void {
                $t->foreignId('author_profile_id')->constrained()->restrictOnDelete();
                $t->foreignId($singular.'_id')->constrained($table)->restrictOnDelete();
                $t->primary(['author_profile_id', $singular.'_id']);
                $t->index($singular.'_id');
                $t->timestamps();
            });
        }
        Schema::create('author_portfolio_items', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('author_profile_id')->constrained()->restrictOnDelete();
            $t->string('title', 160);
            $t->string('short_description', 500);
            $t->text('description')->nullable();
            $t->string('project_type', 20);
            foreach (['project_url', 'case_study_url', 'repository_url'] as $f) {
                $t->string($f, 2048)->nullable();
            }
            $t->string('client_name', 150)->nullable();
            $t->unsignedSmallInteger('completion_year')->nullable();
            $t->boolean('is_public')->default(false);
            $t->boolean('is_featured')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
            $t->index(['author_profile_id', 'is_public', 'sort_order', 'id']);
        });
        Schema::create('author_portfolio_technology', function (Blueprint $t): void {
            $t->foreignId('author_portfolio_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('technology_id')->constrained()->restrictOnDelete();
            $t->primary(['author_portfolio_item_id', 'technology_id']);
            $t->index('technology_id');
            $t->timestamps();
        });
        Schema::create('author_profile_media', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->foreignId('author_profile_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_portfolio_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('purpose', 20);
            $t->string('disk', 100);
            $t->string('path', 500)->unique();
            $t->string('mime_type', 30);
            $t->unsignedInteger('size_bytes');
            $t->unsignedSmallInteger('width');
            $t->unsignedSmallInteger('height');
            $t->boolean('is_current')->default(true);
            $t->timestampsTz();
            $t->index(['author_profile_id', 'purpose', 'is_current']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE author_profiles ADD CONSTRAINT author_professional_bounds CHECK ((years_of_experience IS NULL OR years_of_experience BETWEEN 0 AND 60) AND (founded_year IS NULL OR founded_year BETWEEN 1900 AND 2100) AND (team_size IS NULL OR team_size IN ('solo','2-10','11-50','51-200','201+')) AND revision>=0 AND (languages IS NULL OR (jsonb_typeof(languages)='array' AND jsonb_array_length(languages)<=10)))");
        DB::statement('ALTER TABLE author_portfolio_items ADD CONSTRAINT author_portfolio_order UNIQUE(author_profile_id,sort_order) DEFERRABLE INITIALLY DEFERRED');
        DB::statement("ALTER TABLE author_portfolio_items ADD CONSTRAINT author_portfolio_bounds CHECK (project_type IN ('personal','client','company','open_source') AND sort_order BETWEEN 0 AND 999 AND revision>=0 AND (completion_year IS NULL OR completion_year BETWEEN 1900 AND 2100))");
        DB::statement("ALTER TABLE author_profile_media ADD CONSTRAINT author_profile_image_bounds CHECK (purpose IN ('avatar','cover','portfolio') AND mime_type IN ('image/jpeg','image/png','image/webp') AND size_bytes>0 AND width BETWEEN 64 AND 8000 AND height BETWEEN 64 AND 8000 AND (purpose<>'portfolio' OR author_portfolio_item_id IS NOT NULL OR NOT is_current))");
        DB::statement("CREATE UNIQUE INDEX author_identity_current ON author_profile_media(author_profile_id,purpose) WHERE is_current AND purpose IN ('avatar','cover')");
        DB::statement("CREATE UNIQUE INDEX author_portfolio_image_current ON author_profile_media(author_portfolio_item_id) WHERE is_current AND purpose='portfolio'");
        // Extend the existing guard; retain every existing workflow/audit/ownership check.
        $definition = DB::selectOne("SELECT pg_get_functiondef('templaara_author_guard()'::regprocedure) AS definition")->definition;
        if (! is_string($definition)) {
            throw new RuntimeException('Author guard definition unavailable.');
        }
        $old = "IF OLD.status NOT IN ('draft','changes_requested') AND (to_jsonb(NEW)-ARRAY['status','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','updated_at']) THEN RAISE EXCEPTION 'Submitted profile frozen'; END IF;";
        $new = "IF OLD.status IN ('submitted','under_review','rejected') AND (to_jsonb(NEW)-ARRAY['status','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','updated_at']) THEN RAISE EXCEPTION 'Submitted profile frozen'; END IF;
IF OLD.status='approved' AND (to_jsonb(NEW)-ARRAY['updated_at','revision','bio','website_url','portfolio_url','github_url','linkedin_url','professional_headline','years_of_experience','founded_year','team_size','state_region','city','public_location','languages','support_availability','confidential_work']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['updated_at','revision','bio','website_url','portfolio_url','github_url','linkedin_url','professional_headline','years_of_experience','founded_year','team_size','state_region','city','public_location','languages','support_availability','confidential_work']) THEN RAISE EXCEPTION 'Verified identity changes require review'; END IF;";
        if (substr_count($definition, $old) !== 1) {
            throw new RuntimeException('Unexpected author integrity definition.');
        }
        $definition = str_replace($old, $new, $definition);
        $old = "NEW.snapshot IS DISTINCT FROM (to_jsonb(p)-ARRAY['id','user_id','status','is_legacy','created_at','updated_at'])";
        $exclude = "ARRAY['id','user_id','status','is_legacy','created_at','updated_at','professional_headline','years_of_experience','founded_year','team_size','state_region','city','public_location','languages','support_availability','confidential_work','revision']";
        if (substr_count($definition, $old) !== 1) {
            throw new RuntimeException('Unexpected author snapshot definition.');
        }
        $definition = str_replace($old, "(NEW.snapshot-'professional') IS DISTINCT FROM (to_jsonb(p)-$exclude)", $definition);
        DB::connection()->getPdo()->exec($definition);
        $constraint = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='author_applications'::regclass AND conname='author_application_state'")->definition;
        if (! is_string($constraint)) {
            throw new RuntimeException('Author constraint definition unavailable.');
        }
        DB::statement('ALTER TABLE author_applications DROP CONSTRAINT author_application_state');
        DB::statement('ALTER TABLE author_applications ADD CONSTRAINT author_application_state '.str_replace('snapshot_version = 1', 'snapshot_version IN (1,2)', $constraint));
        DB::unprepared(<<<'SQL'
CREATE FUNCTION templaara_author_professional_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p author_profiles%ROWTYPE;
BEGIN
IF NEW.snapshot_version=2 THEN
 SELECT * INTO p FROM author_profiles WHERE id=NEW.author_profile_id;
 IF ((NEW.snapshot->'professional')-ARRAY['technologies','expertise','portfolio','avatar','cover']) IS DISTINCT FROM jsonb_build_object('professional_headline',p.professional_headline,'years_of_experience',p.years_of_experience,'founded_year',p.founded_year,'team_size',p.team_size,'state_region',p.state_region,'city',p.city,'public_location',p.public_location,'languages',p.languages,'support_availability',p.support_availability,'confidential_work',p.confidential_work) THEN RAISE EXCEPTION 'Professional snapshot must match profile'; END IF;
 IF NEW.snapshot->'professional'->'technologies' IS DISTINCT FROM COALESCE((SELECT jsonb_agg(jsonb_build_object('id',t.id,'name',t.name) ORDER BY t.id) FROM author_profile_technology a JOIN technologies t ON t.id=a.technology_id WHERE a.author_profile_id=p.id),'[]'::jsonb) THEN RAISE EXCEPTION 'Submitted technology evidence mismatch'; END IF;
 IF NEW.snapshot->'professional'->'expertise' IS DISTINCT FROM COALESCE((SELECT jsonb_agg(jsonb_build_object('id',c.id,'name',c.name) ORDER BY c.id) FROM author_profile_category a JOIN categories c ON c.id=a.category_id WHERE a.author_profile_id=p.id),'[]'::jsonb) THEN RAISE EXCEPTION 'Submitted expertise evidence mismatch'; END IF;
 IF jsonb_array_length(NEW.snapshot->'professional'->'portfolio')<>(SELECT count(*) FROM author_portfolio_items WHERE author_profile_id=p.id) OR EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.snapshot->'professional'->'portfolio') e WHERE NOT EXISTS(SELECT 1 FROM author_portfolio_items i WHERE i.author_profile_id=p.id AND i.public_uuid::text=e->>'public_uuid' AND i.title=e->>'title' AND i.short_description=e->>'short_description' AND i.revision=(e->>'revision')::int)) THEN RAISE EXCEPTION 'Submitted portfolio evidence mismatch'; END IF;
END IF;
RETURN NEW;
END; $$;
CREATE TRIGGER author_professional_snapshot BEFORE INSERT ON author_applications FOR EACH ROW EXECUTE FUNCTION templaara_author_professional_snapshot();
CREATE FUNCTION templaara_author_portfolio_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_TABLE_NAME='author_portfolio_items' THEN
 IF NOT EXISTS (SELECT 1 FROM author_profiles WHERE id=COALESCE(NEW.author_profile_id,OLD.author_profile_id) AND status IN ('draft','changes_requested','approved')) THEN RAISE EXCEPTION 'Submitted portfolio frozen'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
END IF;
IF TG_OP='UPDATE' AND (NEW.author_profile_id<>OLD.author_profile_id OR NEW.public_uuid<>OLD.public_uuid) THEN RAISE EXCEPTION 'Portfolio ownership immutable'; END IF;
IF TG_TABLE_NAME='author_profile_media' THEN
 IF NEW.author_portfolio_item_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM author_portfolio_items WHERE id=NEW.author_portfolio_item_id AND author_profile_id=NEW.author_profile_id) THEN RAISE EXCEPTION 'Portfolio media ownership mismatch'; END IF;
END IF;
RETURN NEW; END; $$;
CREATE TRIGGER author_portfolio_owner BEFORE INSERT OR UPDATE OR DELETE ON author_portfolio_items FOR EACH ROW EXECUTE FUNCTION templaara_author_portfolio_owner();
CREATE TRIGGER author_media_owner BEFORE INSERT OR UPDATE ON author_profile_media FOR EACH ROW EXECUTE FUNCTION templaara_author_portfolio_owner();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Professional author evidence requires forward migrations.');
    }
};
