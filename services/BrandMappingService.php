<?php namespace Dmdev\MallImport1c\Services;

use Dmdev\MallImport1c\Models\BrandMapping;
use OFFLINE\Mall\Models\Brand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для сопоставления брендов 1С с брендами Mall
 */
class BrandMappingService
{
    /**
     * Время кеширования маппинга (в минутах)
     */
    const CACHE_TIME = 60;

    /**
     * Ключ кеша для маппинга брендов
     */
    const CACHE_KEY = 'mall_import_brand_mapping';

    /**
     * Получить ID бренда Mall по external_id из 1С
     */
    public function getMallBrandId($externalId, $externalName = null)
    {
        // Сначала проверяем кеш
        $mapping = $this->getCachedMapping();
        
        if (isset($mapping[$externalId])) {
            return $mapping[$externalId];
        }

        // Ищем в базе данных
        $brandMapping = BrandMapping::findByExternalId($externalId);
        
        if ($brandMapping && $brandMapping->mall_brand_id) {
            // Обновляем кеш
            $this->updateCache($externalId, $brandMapping->mall_brand_id);
            return $brandMapping->mall_brand_id;
        }

        // Если маппинг не найден и есть название, пытаемся автопоиск
        if ($externalName && !$brandMapping) {
            $mallBrandId = $this->tryAutoMapping($externalId, $externalName);
            if ($mallBrandId) {
                $this->updateCache($externalId, $mallBrandId);
                return $mallBrandId;
            }
        }

        // Создаем запись без маппинга для будущей настройки
        if (!$brandMapping && $externalName) {
            BrandMapping::createOrUpdate($externalId, $externalName);
        }

        return null;
    }

    /**
     * Попытка автоматического сопоставления по названию
     */
    protected function tryAutoMapping($externalId, $externalName)
    {
        // Нормализуем название для поиска
        $normalizedName = $this->normalizeBrandName($externalName);
        
        // Ищем точное совпадение (без учета регистра)
        $mallBrand = Brand::whereRaw('LOWER(name) = ?', [mb_strtolower($normalizedName)])->first();
        
        if ($mallBrand) {
            // Создаем автоматический маппинг
            BrandMapping::createOrUpdate($externalId, $externalName, $mallBrand->id, true);
            
            Log::info("Auto-mapped brand: {$externalName} -> {$mallBrand->name} (ID: {$mallBrand->id})");
            
            return $mallBrand->id;
        }

        // Пытаемся найти частичное совпадение
        $partialMatch = $this->findPartialMatch($normalizedName);
        if ($partialMatch) {
            // Создаем автоматический маппинг
            BrandMapping::createOrUpdate($externalId, $externalName, $partialMatch->id, true);
            
            Log::info("Partial auto-mapped brand: {$externalName} -> {$partialMatch->name} (ID: {$partialMatch->id})");
            
            return $partialMatch->id;
        }

        return null;
    }

    /**
     * Поиск частичного совпадения названия бренда
     */
    protected function findPartialMatch($normalizedName)
    {
        // Ищем бренды, где название содержит нормализованное имя или наоборот
        return Brand::where(function($query) use ($normalizedName) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($normalizedName) . '%'])
                  ->orWhereRaw('? LIKE CONCAT("%", LOWER(name), "%")', [mb_strtolower($normalizedName)]);
        })->first();
    }

    /**
     * Нормализация названия бренда
     */
    protected function normalizeBrandName($name)
    {
        // Убираем лишние пробелы и приводим к единому виду
        $name = trim(preg_replace('/\s+/', ' ', $name));
        
        // Убираем спецсимволы, которые могут мешать сопоставлению
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        
        return $name;
    }

    /**
     * Получить кешированный маппинг
     */
    protected function getCachedMapping()
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TIME, function () {
            return BrandMapping::getActiveMapping()->toArray();
        });
    }

    /**
     * Обновить кеш маппинга
     */
    protected function updateCache($externalId, $mallBrandId)
    {
        $mapping = Cache::get(self::CACHE_KEY, []);
        $mapping[$externalId] = $mallBrandId;
        Cache::put(self::CACHE_KEY, $mapping, self::CACHE_TIME);
    }

    /**
     * Очистить кеш маппинга
     */
    public function clearCache()
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Синхронизировать бренды из справочника 1С
     */
    public function syncBrandsFromDirectory($brandsDirectory)
    {
        $synced = 0;
        $autoMapped = 0;
        
        foreach ($brandsDirectory as $brandId => $brandName) {
            // Проверяем, есть ли уже маппинг
            $existing = BrandMapping::where('external_id', $brandId)->first();
            
            if (!$existing) {
                // Пытаемся автопоиск
                $mallBrandId = $this->tryAutoMapping($brandId, $brandName);
                if ($mallBrandId) {
                    $autoMapped++;
                }
                $synced++;
            } else {
                // Обновляем название, если изменилось
                if ($existing->external_name !== $brandName) {
                    $existing->external_name = $brandName;
                    $existing->save();
                }
            }
        }

        // Очищаем кеш после синхронизации
        $this->clearCache();

        return [
            'synced' => $synced,
            'auto_mapped' => $autoMapped
        ];
    }

    /**
     * Получить статистику маппинга
     */
    public function getMappingStats()
    {
        $total = BrandMapping::where('is_active', true)->count();
        $mapped = BrandMapping::where('is_active', true)->whereNotNull('mall_brand_id')->count();
        $autoMapped = BrandMapping::where('is_active', true)->where('auto_mapped', true)->count();
        
        return [
            'total' => $total,
            'mapped' => $mapped,
            'unmapped' => $total - $mapped,
            'auto_mapped' => $autoMapped,
            'manual_mapped' => $mapped - $autoMapped,
            'mapping_percentage' => $total > 0 ? round(($mapped / $total) * 100, 2) : 0
        ];
    }
}
