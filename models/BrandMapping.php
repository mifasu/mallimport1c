<?php namespace Dmdev\MallImport1c\Models;

use Model;
use OFFLINE\Mall\Models\Brand;

/**
 * Модель для сопоставления брендов 1С с брендами Mall
 */
class BrandMapping extends Model
{
    use \October\Rain\Database\Traits\Validation;

    /**
     * @var string The database table used by the model.
     */
    public $table = 'dmdev_mall_brand_mapping';

    /**
     * @var array Fillable fields
     */
    protected $fillable = [
        'external_id',
        'external_name', 
        'mall_brand_id',
        'auto_mapped',
        'is_active',
        'notes'
    ];

    /**
     * @var array Validation rules
     */
    public $rules = [
        'external_id' => 'required|string|max:255|unique:dmdev_mall_brand_mapping',
        'external_name' => 'required|string|max:255',
        'mall_brand_id' => 'nullable|integer|exists:offline_mall_brands,id',
    ];

    /**
     * @var array Attribute casting
     */
    protected $casts = [
        'auto_mapped' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * @var array Relations
     */
    public $belongsTo = [
        'mallBrand' => [
            Brand::class,
            'key' => 'mall_brand_id',
            'otherKey' => 'id'
        ]
    ];

    /**
     * Поиск маппинга по external_id
     */
    public static function findByExternalId($externalId)
    {
        return static::where('external_id', $externalId)
                    ->where('is_active', true)
                    ->first();
    }

    /**
     * Создание или обновление маппинга
     */
    public static function createOrUpdate($externalId, $externalName, $mallBrandId = null, $autoMapped = false)
    {
        return static::updateOrCreate(
            ['external_id' => $externalId],
            [
                'external_name' => $externalName,
                'mall_brand_id' => $mallBrandId,
                'auto_mapped' => $autoMapped,
                'is_active' => true
            ]
        );
    }

    /**
     * Получить все активные маппинги
     */
    public static function getActiveMapping()
    {
        return static::where('is_active', true)
                    ->whereNotNull('mall_brand_id')
                    ->with('mallBrand')
                    ->get()
                    ->pluck('mall_brand_id', 'external_id');
    }

    /**
     * Получить немаппированные бренды (без сопоставления с Mall)
     */
    public static function getUnmappedBrands()
    {
        return static::whereNull('mall_brand_id')
                    ->where('is_active', true)
                    ->orderBy('external_name')
                    ->get();
    }

    /**
     * Деактивировать маппинг
     */
    public function deactivate()
    {
        $this->is_active = false;
        $this->save();
    }

    /**
     * Активировать маппинг
     */
    public function activate()
    {
        $this->is_active = true;
        $this->save();
    }

    /**
     * Проверить, существует ли Mall бренд
     */
    public function getMallBrandExistsAttribute()
    {
        return $this->mall_brand_id && $this->mallBrand;
    }

    /**
     * Получить название Mall бренда
     */
    public function getMallBrandNameAttribute()
    {
        return $this->mallBrand ? $this->mallBrand->name : null;
    }
}
