<?php namespace Dmdev\MallImport1c\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * CreateTempImportTable Migration
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
        Schema::create('dmdev_mallimport1c_temp_import', function(Blueprint $table) {
            $table->id();
            $table->string('product_id')->index(); // ID продукта
            $table->json('data'); // JSON-данные продукта
            $table->enum('status', ['pending', 'processed', 'error'])->default('pending'); // Статус обработки
            $table->text('error_message')->nullable(); // Сообщение об ошибке
            $table->timestamp('file_last_modified'); // Временная метка файла
            $table->timestamps(); // created_at и updated_at
        });
    }

    /**
     * down reverses the migration
     */
    public function down()
    {
        Schema::dropIfExists('dmdev_mallimport1c_temp_import');
    }
};
