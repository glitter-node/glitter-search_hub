<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glitter_search_hub_queries', function (Blueprint $table) {
            $table->id()->comment('검색 통계 집계 ID');
            $table->date('stat_date')->comment('집계 날짜');
            $table->string('provider_key', 64)->comment('제공자 또는 전체 검색 식별자');
            $table->unsignedBigInteger('request_count')->default(0)->comment('검색 요청 수');
            $table->unsignedBigInteger('success_count')->default(0)->comment('검색 성공 수');
            $table->unsignedBigInteger('failure_count')->default(0)->comment('검색 실패 수');
            $table->unsignedBigInteger('empty_count')->default(0)->comment('결과 없음 수');
            $table->timestamps();
            $table->unique(['stat_date', 'provider_key'], 'gsh_stat_date_provider_unique');
            $table->index('stat_date', 'gsh_stat_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glitter_search_hub_queries');
    }
};
