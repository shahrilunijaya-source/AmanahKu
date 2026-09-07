<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-30: the tenant's own reaction set, plus room for a key (not an emoji) in the
 * three reaction tables that already exist. Old rows keep their emoji value and
 * read back as a tally under that emoji until nobody looks at them any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label', 60);
            $table->string('icon', 16);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        foreach (['tot_reactions', 'knowledge_reactions', 'birthday_wish_reactions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('emoji', 40)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
