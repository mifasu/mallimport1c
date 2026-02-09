<?php namespace Dmdev\MallImport1c\Services;

class ProductDataTransformer
{
    /**
     * Преобразует данные продукта в нужный формат.
     *
     * @param array $data Данные продукта
     * @return array Преобразованные данные
     */
    public static function transform(array $data): array
    {
        
        if (empty($data['name'])) {
            throw new \Exception('ProductDataTransformer / transform: Нет name');
        }
        if (empty($data['category_id'])) {
            throw new \Exception('ProductDataTransformer / transform: Нет category_id');
        }
        if (empty($data['offers'])) {
            throw new \Exception('ProductDataTransformer / transform: Нет обязательных данных offers');
        }

        $product = [
            'id' => $data['id'],
            'name' => $data['name'],
            'slug' => str_slug($data['name']),
            'category_id' => $data['category_id'],
            'published' => 1,
            'price' => $data['offers'][0]['price'] ?? 0,
            'stock' => $data['offers'][0]['quantity'] ?? 0,
            'count_variants' => count($data['offers']),
        ];

        if (!empty($data['description']))  {
            $product['description'] = $data['description'];
        } else {
            $product['description'] = ''; // Устанавливаем пустую строку вместо null
        }

        if (!empty($data['sku']))  {
            $product['sku'] = $data['sku'];
        }

        // Добавляем бренд если есть
        if (!empty($data['brand_id'])) {
            $product['brand_id'] = $data['brand_id'];
            $product['brand_name'] = $data['brand_name'] ?? '';
        }

        // Добавляем категорию для сайта если есть
        if (!empty($data['website_category_id'])) {
            $product['website_category_id'] = $data['website_category_id'];
            $product['website_category_name'] = $data['website_category_name'] ?? '';
        }

        // Добавляем group_id (ID группы товара из 1С)
        if (!empty($data['category_id'])) {
            $product['group_id'] = $data['category_id'];
        }

        if (count($data['offers']) > 1) {
            $product['variants'] = $data['offers'];
            $product['variant_method'] = 'variant';
        }

        return $product;
    }
}