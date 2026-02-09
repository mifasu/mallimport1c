<?php namespace Dmdev\MallImport1c\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * AddBrandCategoryFieldsToTempImport Migration
 * Добавляет дополнительные поля для удобства работы с данными импорта
 *
 * @link https://docs.octobercms.com/3.x/extend/database/structure.html
 */
return new class extends Migration
{
    /**
     * up builds the migration
     */
    public function up()
    {
        Schema::table('dmdev_mallimport1c_temp_import', function(Blueprint $table) {
            // Добавляем только новые поля, которых нет в исходной таблице
            $table->string('name')->nullable()->after('product_id');
            $table->string('sku')->nullable()->after('name');
            $table->string('category_id')->nullable()->after('sku');
            $table->string('brand_id')->nullable()->after('category_id');
            $table->string('brand_name')->nullable()->after('brand_id');
            $table->string('website_category_id')->nullable()->after('brand_name');
            $table->string('website_category_name')->nullable()->after('website_category_id');
            $table->text('description')->nullable()->after('website_category_name');
        });
    }

    /**
     * down reverses the migration
     */
    public function down()
    {
        Schema::table('dmdev_mallimport1c_temp_import', function(Blueprint $table) {
            $table->dropColumn([
                'name',
                'sku', 
                'category_id',
                'brand_id',
                'brand_name',
                'website_category_id',
                'website_category_name',
                'description'
            ]);
        });
    }
};
