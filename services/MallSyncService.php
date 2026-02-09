<?php

namespace Dmdev\MallImport1c\Services;

use OFFLINE\Mall\Models\Product;
use OFFLINE\Mall\Models\Variant;
use OFFLINE\Mall\Models\Category as MallCategory;
use Dmdev\MallImport1c\Services\TempImportService;
use Dmdev\MallImport1c\Services\CategoryMappingService;
use Dmdev\MallImport1c\Services\ProductDataTransformer;
use Dmdev\MallImport1c\Services\BrandMappingService;
use Illuminate\Support\Facades\Log;
use DB;

class MallSyncService
{
    protected $tempImportService;
    protected $brandMappingService;

    public function __construct()
    {
        $this->tempImportService = new TempImportService();
        $this->brandMappingService = new BrandMappingService();
    }

    /**
     * Обрабатывает n записей из временной таблицы.
     *
     * @param int $batchSize Количество записей для обработки
     * @param string|null $fileLastModified Фильтр по timestamp файлов (опционально)
     * @return array Результаты обработки
     */
    public function processBatch(int $batchSize = 100, ?string $fileLastModified = null): array
    {
        $results = [];

        // Получаем записи со статусом "pending" (опционально фильтруем по timestamp)
        $pendingItems = $this->tempImportService->getPendingItems($batchSize, $fileLastModified);

        foreach ($pendingItems as $item) {
            try {

                // Преобразуем данные продукта
                $product = ProductDataTransformer::transform($item->data);

                // Выполняем синхронизацию данных с Mall
                $productMall = $this->syncProduct($product);

                // Обновляем статус записи как "processed"
                $this->tempImportService->updateStatus($item, 'processed');

                $results[] = [
                    'product_id' => $item->product_id,
                    'status' => 'processed',
                    'message' => 'Успешно обработано',
                ];
            } catch (\Exception $e) {
                // Обновляем статус записи как "error"
                $this->tempImportService->updateStatus($item, 'error', $e->getMessage());

                $results[] = [
                    'product_id' => $item->product_id,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }


    /**
     * Синхронизирует продукт с Mall.
     *
     * @param array $data Данные продукта
     * @return Product
     */
    protected function syncProduct(array $data)
    {
        if (empty($data['price'])) {
            throw new \Exception('syncProduct: Нет обязательных данных price');
        }

        // Проверяем, существует ли уже продукт
        $existingProduct = Product::where('user_defined_id', $data['id'])->first();
        $isNewProduct = !$existingProduct;

        // формируем данные для создания/обновления продукта
        $productData = array_filter([
            'name' => $data['name'],
            'slug' => str_slug($data['name']),
            'description' => $data['description'] ?? '', // Пустая строка вместо null
            'sku' => $data['sku'] ?? null,
            'stock' => $data['stock'] ?? null,
            'published' => $data['published'] ?? null,
            'inventory_management_method' => $data['variant_method'] ?? null,
        ], function ($value) {
            return $value !== null; // Убираем все значения, равные null
        });

        // Обрабатываем бренд если есть и товар существует
        if (!empty($data['brand_id'])) {
            $shouldUpdateBrand = true;
            
            // Если товар уже существует, проверяем, есть ли у него бренд
            if (!$isNewProduct && $existingProduct && $existingProduct->brand_id) {
                $shouldUpdateBrand = false;
                Log::info('syncProduct: У товара уже есть бренд, пропускаем обновление', [
                    'product_id' => $data['id'],
                    'existing_brand_id' => $existingProduct->brand_id,
                    'external_brand_id' => $data['brand_id']
                ]);
            }
            
            if ($shouldUpdateBrand) {
                $mallBrandId = $this->brandMappingService->getMallBrandId(
                    $data['brand_id'], 
                    $data['brand_name'] ?? null
                );
                
                if ($mallBrandId) {
                    $productData['brand_id'] = $mallBrandId;
                    Log::info('syncProduct: Бренд назначен товару', [
                        'product_id' => $data['id'],
                        'external_brand_id' => $data['brand_id'],
                        'external_brand_name' => $data['brand_name'] ?? 'не указано',
                        'mall_brand_id' => $mallBrandId,
                        'is_new_product' => $isNewProduct
                    ]);
                } else {
                    Log::warning('syncProduct: Не удалось найти маппинг бренда', [
                        'product_id' => $data['id'],
                        'external_brand_id' => $data['brand_id'],
                        'external_brand_name' => $data['brand_name'] ?? 'не указано'
                    ]);
                }
            }
        }

        // Определяем категории для продукта
        $categories = [];
        
        // Для новых товаров пытаемся определить категорию с новой двухуровневой логикой
        if ($isNewProduct) {
            $mallCategoryId = CategoryMappingService::getMallCategoryId(
                $data['website_category_id'] ?? null,
                $data['group_id'] ?? null
            );
            
            // Получаем объект категории по ID
            $category = MallCategory::find($mallCategoryId);
            if ($category) {
                $categories[] = $category;
            }
            
            Log::info('syncProduct: Для нового товара определена категория', [
                'product_id' => $data['id'],
                'website_category_id' => $data['website_category_id'] ?? 'не задан',
                'group_id' => $data['group_id'] ?? 'не задан',
                'mall_category_id' => $mallCategoryId,
                'category_found' => $category ? true : false
            ]);
        }
        
        // Для существующих товаров оставляем категории без изменений
        if (!$isNewProduct) {
            Log::info('syncProduct: Обновление существующего товара, категории не изменяются', [
                'product_id' => $data['id']
            ]);
        }

        
        // создаем транзакцию для создания/обновления продукта и его категорий
        DB::transaction(function () use ($productData, $data, $categories, $isNewProduct) {

            // Создаем или обновляем продукт в Mall
            $productMall = Product::updateOrCreate(
                ['user_defined_id' => $data['id']],
                $productData
            );

            // Привязываем категории только для новых товаров
            if ($isNewProduct && !empty($categories)) {
                // Извлекаем ID из объектов категорий для sync()
                $categoryIds = array_map(function($category) {
                    return $category->id;
                }, $categories);
                
                $productMall->categories()->sync($categoryIds);
                Log::info('syncProduct: Категории назначены новому товару', [
                    'product_id' => $productMall->id,
                    'category_ids' => $categoryIds
                ]);
            }

            // закидываем базовый ценник (валюты по умолчанию с id 1)
            if (!empty($data['price']) || $data['price'] > 0) {
                $productMall->prices()->updateOrCreate(
                    ['currency_id' => 1],
                    ['price' => $data['price']]
                );
            }

            // закидываем варианты (если они есть)
            if (!empty($data['variants']))
            {
                foreach ($data['variants'] as $variantData) 
                {                    
                    $variant = Variant::updateOrCreate(
                        ['user_defined_id' => $variantData['id']], 
                        [
                            'product_id' => $productMall->id,
                            'name' => $variantData['name'],
                            'stock' => $variantData['quantity'],
                            'published' => 1,
                        ]
                    );                        
                    
                    if (!empty($variantData['characteristics']))
                    {
                        $variant->property_values()->updateOrCreate(
                            ['property_id' => 2], // 
                            [
                                'product_id' => $productMall->id,
                                'value' => array_shift($variantData['characteristics'])
                            ]
                        );
                    }
                    
                    $variant->prices()->updateOrCreate(
                        ['currency_id' => 1], // Укажите ID валюты (например, 1 для RUB)
                        [
                            'product_id' => $productMall->id,
                            'price' => $variantData['price']
                        ]
                    );                    
                }                
            }

        });
        
        return $data;
    }



    /**
     * Финализация синхронизации данных.
     */
    public function finalizeSync()
    {
        if ($this->isProcessingComplete()) {
            $this->deactivateOldProducts();
            TempImport::clearTable();
        } else {
            throw new \Exception('Обработка данных еще не завершена.');
        }
    }
    /**
     * Деактивация устаревших записей в основной таблице Mall.
     */
    public function deactivateOldProducts()
    {
        // Получаем список всех product_id из временной таблицы
        $tempProductIds = TempImport::getAllProductIds();

        // Деактивируем продукты, которых нет в списке временной таблицы
        Product::whereNotIn('user_defined_id', $tempProductIds)
            ->update(['published' => 0]); // Устанавливаем published в 0
    }
    
}