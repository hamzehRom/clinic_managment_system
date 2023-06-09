<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('doc_clinics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->references('id')->on('clinics')->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->references('id')->on('doctors')->cascadeOnDelete();
            $table->date('join_date');
            $table->date('end_date')->nullable();
            $table->float('price');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_clinics');
    }
};
