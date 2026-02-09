<?php

namespace Dmdev\MallImport1c\Services;

use OFFLINE\Mall\Models\Category as MallCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Dmdev\MallImport1c\Services\GroupHierarchyService;

class CategoryMappingService
{
    /**
     * Кеш для маппинга категорий (website_category_id => mall_category_id)
     */
    private static $categoryMapping = null;

    /**
     * Получает ID категории Mall по коду из 1С с двухуровневой логикой поиска
     *
     * @param string $websiteCategoryId ID из справочника "Группа Номенклатуры для сайта"
     * @param string $productGroupId ID группы товара из 1С
     * @return int ID категории Mall (или 77 для неопознанных)
     */
    public static function getMallCategoryId($websiteCategoryId = null, $productGroupId = null)
    {
        $defaultCategoryId = self::getDefaultCategoryId(); // Категория "Без категории" из конфига
        
        // ПРИОРИТЕТ 1: Поиск по свойству "Группа Номенклатуры для сайта"
        if (!empty($websiteCategoryId)) {
            $mallCategoryId = self::findByWebsiteCategoryId($websiteCategoryId);
            if ($mallCategoryId) {
                Log::info('CategoryMappingService: Найдена категория по website_category_id', [
                    'website_category_id' => $websiteCategoryId,
                    'mall_category_id' => $mallCategoryId,
                    'method' => 'website_property'
                ]);
                return $mallCategoryId;
            }
        }

        // ПРИОРИТЕТ 2: Поиск по группе товара (коду категории 1-го уровня)
        if (!empty($productGroupId)) {
            $mallCategoryId = self::findByProductGroupId($productGroupId);
            if ($mallCategoryId) {
                Log::info('CategoryMappingService: Найдена категория по product_group_id', [
                    'product_group_id' => $productGroupId,
                    'mall_category_id' => $mallCategoryId,
                    'method' => 'group_code'
                ]);
                return $mallCategoryId;
            }
        }

        // FALLBACK: Категория "Без категории"
        $fallbackCategoryId = self::validateDefaultCategory($defaultCategoryId);
        
        Log::warning('CategoryMappingService: Не найден маппинг, используется категория по умолчанию', [
            'website_category_id' => $websiteCategoryId,
            'product_group_id' => $productGroupId,
            'default_category_id' => $fallbackCategoryId,
        ]);
        return $fallbackCategoryId;
    }

    /**
     * Поиск категории Mall по свойству "Группа Номенклатуры для сайта"
     *
     * @param string $websiteCategoryId
     * @return int|null
     */
    private static function findByWebsiteCategoryId($websiteCategoryId)
    {
        // Инициализируем маппинг если еще не загружен
        if (self::$categoryMapping === null) {
            self::loadCategoryMapping();
        }

        return self::$categoryMapping[$websiteCategoryId] ?? null;
    }

    /**
     * Поиск категории Mall по ID группы товара (через код категории 1-го уровня)
     * Теперь с поддержкой иерархии групп
     *
     * @param string $productGroupId
     * @return int|null
     */
    private static function findByProductGroupId($productGroupId)
    {
        try {
            // Сначала пытаемся найти прямо по ID группы
            $category = MallCategory::where('code', $productGroupId)
                ->where('parent_id', null) // Только категории первого уровня
                ->first();

            if ($category) {
                Log::info('CategoryMappingService: Найдена категория по прямому совпадению group_id', [
                    'product_group_id' => $productGroupId,
                    'mall_category_id' => $category->id
                ]);
                return $category->id;
            }

            // Если не найдено, ищем через иерархию - получаем корневую группу
            $rootGroupId = GroupHierarchyService::getRootGroupId($productGroupId);
            if ($rootGroupId && $rootGroupId !== $productGroupId) {
                $rootCategory = MallCategory::where('code', $rootGroupId)
                    ->where('parent_id', null) // Только категории первого уровня
                    ->first();

                if ($rootCategory) {
                    Log::info('CategoryMappingService: Найдена категория через корневую группу', [
                        'product_group_id' => $productGroupId,
                        'root_group_id' => $rootGroupId,
                        'mall_category_id' => $rootCategory->id
                    ]);
                    return $rootCategory->id;
                }
            }

            Log::info('CategoryMappingService: Категория не найдена ни по прямому совпадению, ни через иерархию', [
                'product_group_id' => $productGroupId,
                'root_group_id' => $rootGroupId
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('CategoryMappingService: Ошибка поиска категории по group_id', [
                'product_group_id' => $productGroupId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Загружает маппинг категорий в память для быстрого доступа
     */
    private static function loadCategoryMapping()
    {
        // Пытаемся получить из кеша
        self::$categoryMapping = Cache::remember('mall_category_mapping', 60 * 60, function () {
            $mapping = [];
            
            // Получаем все категории Mall у которых заполнен код (внешний ID)
            $categories = MallCategory::whereNotNull('code')
                ->where('code', '!=', '')
                ->get(['id', 'code', 'name']);

            foreach ($categories as $category) {
                $mapping[$category->code] = $category->id;
            }

            Log::info('CategoryMappingService: Загружено маппингов категорий', [
                'count' => count($mapping),
                'categories' => $mapping
            ]);

            return $mapping;
        });
    }

    /**
     * Очищает кеш маппинга категорий
     */
    public static function clearCache()
    {
        Cache::forget('mall_category_mapping');
        self::$categoryMapping = null;
        Log::info('CategoryMappingService: Кеш маппинга категорий очищен');
    }

    /**
     * Обновляет код категории Mall для связи с 1С
     *
     * @param int $mallCategoryId ID категории Mall
     * @param string $websiteCategoryId Внешний ID из 1С
     * @return bool
     */
    public static function updateCategoryCode($mallCategoryId, $websiteCategoryId)
    {
        try {
            $category = MallCategory::find($mallCategoryId);
            if (!$category) {
                Log::warning('CategoryMappingService: Категория Mall не найдена', [
                    'mall_category_id' => $mallCategoryId
                ]);
                return false;
            }

            $category->code = $websiteCategoryId;
            $category->save();

            // Очищаем кеш чтобы перезагрузить маппинг
            self::clearCache();

            Log::info('CategoryMappingService: Код категории обновлен', [
                'mall_category_id' => $mallCategoryId,
                'website_category_id' => $websiteCategoryId,
                'category_name' => $category->name
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('CategoryMappingService: Ошибка обновления кода категории', [
                'mall_category_id' => $mallCategoryId,
                'website_category_id' => $websiteCategoryId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Валидирует и возвращает ID категории по умолчанию, создавая её при необходимости
     *
     * @param int $defaultCategoryId
     * @return int
     */
    private static function validateDefaultCategory($defaultCategoryId)
    {
        try {
            // Проверяем существование категории
            $category = MallCategory::find($defaultCategoryId);
            
            if ($category) {
                return $defaultCategoryId;
            }
            
            // Категория не найдена, пытаемся найти альтернативу
            Log::warning('CategoryMappingService: Категория по умолчанию не найдена', [
                'requested_id' => $defaultCategoryId
            ]);
            
            // Ищем существующую категорию "Без категории"
            $fallbackCategory = MallCategory::where('name', 'like', '%без категории%')
                ->orWhere('slug', 'uncategorized')
                ->first();
                
            if ($fallbackCategory) {
                Log::info('CategoryMappingService: Используется альтернативная категория', [
                    'fallback_id' => $fallbackCategory->id,
                    'fallback_name' => $fallbackCategory->name
                ]);
                return $fallbackCategory->id;
            }
            
            // Если ничего не найдено, используем первую доступную категорию
            $firstCategory = MallCategory::first();
            if ($firstCategory) {
                Log::warning('CategoryMappingService: Используется первая доступная категория', [
                    'fallback_id' => $firstCategory->id,
                    'fallback_name' => $firstCategory->name
                ]);
                return $firstCategory->id;
            }
            
            // Критическая ошибка - в системе нет ни одной категории
            throw new \Exception('В системе Mall не найдено ни одной категории');
            
        } catch (\Exception $e) {
            Log::error('CategoryMappingService: Критическая ошибка валидации категории', [
                'error' => $e->getMessage(),
                'requested_id' => $defaultCategoryId
            ]);
            
            // Возвращаем исходный ID как последнюю попытку
            return $defaultCategoryId;
        }
    }

    /**
     * Получает ID категории по умолчанию из конфига
     *
     * @return int
     */
    public static function getDefaultCategoryId()
    {
        return Config::get('dmdev.mallimport1c::config.default_category_id', 77);
    }

    /**
     * Получает статистику маппинга категорий
     *
     * @return array
     */
    public static function getMappingStats()
    {
        if (self::$categoryMapping === null) {
            self::loadCategoryMapping();
        }

        $totalMallCategories = MallCategory::count();
        $mappedCategories = count(self::$categoryMapping);

        return [
            'total_mall_categories' => $totalMallCategories,
            'mapped_categories' => $mappedCategories,
            'unmapped_categories' => $totalMallCategories - $mappedCategories,
            'mapping_coverage' => $totalMallCategories > 0 
                ? round(($mappedCategories / $totalMallCategories) * 100, 1) 
                : 0
        ];
    }
}
