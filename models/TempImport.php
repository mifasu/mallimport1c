<?php namespace Dmdev\MallImport1c\Models;

use Model;

/**
 * TempImport Model
 *
 * Модель для работы с временной таблицей импорта.
 */
class TempImport extends Model
{
    /**
     * @var string Таблица, связанная с моделью
     */
    protected $table = 'dmdev_mallimport1c_temp_import';

    /**
     * @var array Атрибуты, которые можно массово заполнять
     */
    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'category_id',
        'brand_id',
        'brand_name',
        'website_category_id',
        'website_category_name',
        'description',
        'data',
        'status',
        'error_message',
        'file_last_modified',
    ];

    /**
     * @var array Кастинг атрибутов
     */
    protected $casts = [
        'data' => 'array', // Автоматическое преобразование JSON в массив
        'file_last_modified' => 'datetime',
    ];

    /**
     * Проверка, есть ли записи со статусом "pending".
     */
    public static function hasPending()
    {
        return self::where('status', 'pending')->exists();
    }

    /**
     * Получение записей со статусом "pending".
     */
    public static function getPending($limit = 100)
    {
        return self::where('status', 'pending')->limit($limit)->get();
    }

    /**
     * Получение всех product_id из временной таблицы.
     *
     * @return array
     */
    public static function getAllProductIds(): array
    {
        return self::pluck('product_id')->toArray();
    }

    /**
     * Проверка, завершена ли обработка данных.
     *
     * @return bool
     */
    public static function isProcessingComplete(): bool
    {
        return !self::hasPending();
    }

    /**
     * Очистка временной таблицы.
     */
    public static function clearTable()
    {
        self::truncate();
    }

}