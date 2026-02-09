<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateBrandMappingTable extends Migration
{
    public function up()
    {
        Schema::create('dmdev_mall_brand_mapping', function (Blueprint $table) {
            $table->increments('id');
            $table->string('external_id', 255)->unique()->comment('ID бренда из 1С');
            $table->string('external_name', 255)->comment('Название бренда из 1С');
            $table->unsignedInteger('mall_brand_id')->nullable()->comment('ID бренда в Mall');
            $table->boolean('auto_mapped')->default(false)->comment('Автоматически сопоставлен по названию');
            $table->boolean('is_active')->default(true)->comment('Активное сопоставление');
            $table->text('notes')->nullable()->comment('Заметки для администратора');
            $table->timestamps();
            
            // Индексы
            $table->index('external_id');
            $table->index('mall_brand_id');
            $table->index(['is_active', 'mall_brand_id']);
            
            // Внешний ключ на таблицу брендов Mall
            $table->foreign('mall_brand_id')
                  ->references('id')
                  ->on('offline_mall_brands')
                  ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('dmdev_mall_brand_mapping');
    }
}
