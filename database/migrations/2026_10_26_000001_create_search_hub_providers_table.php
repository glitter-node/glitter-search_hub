<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glitter_search_hub_providers', function (Blueprint $table) {
            $table->id()->comment('검색 제공자 설정 ID');
            $table->string('provider_key', 64)->unique()->comment('검색 제공자 식별자');
            $table->boolean('enabled')->default(true)->comment('검색 응답 노출 여부');
            $table->unsignedSmallInteger('sort_order')->default(100)->comment('제공자 표시 순서');
            $table->json('supported_types')->nullable()->comment('지원 검색 유형');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glitter_search_hub_providers');
    }
};
