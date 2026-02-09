# 🔄 АЛГОРИТМЫ РАБОТЫ ПЛАГИНА Mall Import 1C v1.0.5

## 📋 **ОБЩАЯ СХЕМА РАБОТЫ**

### 🎯 **Основные этапы импорта:**

1. **Проверка файлов** → **Парсинг XML** → **Промежуточная таблица** → **Синхронизация с Mall** → **Очистка**

---

## 🔍 **ДЕТАЛЬНЫЙ АЛГОРИТМ**

### **Этап 1: Проверка и обработка файлов** (`FileProcessor::processFiles()`)

#### 📁 **Проверка актуальности файлов:**
```php
// 1. Получаем дату последнего изменения файлов import.xml + offers.xml
$fileLastModified = date('Y-m-d H:i:s', max(filemtime($importFile), filemtime($offersFile)));

// 2. Сравниваем с датой в первой записи промежуточной таблицы
$firstRecord = $this->tempImportService->getFirstRecord();

// 3. Если даты совпадают - ВЫХОД (данные актуальны)
if ($firstRecord && $firstRecord->file_last_modified == $fileLastModified) {
    return; // ничего не делаем
}
```

#### 🗑️ **Очистка промежуточной таблицы:**
```php
// 4. Если файлы обновились - ПОЛНАЯ ОЧИСТКА промежуточной таблицы
$this->tempImportService->clearTable(); // TRUNCATE dmdev_mallimport1c_temp_import
```

#### 📊 **Парсинг и объединение данных:**
```php
// 5. Парсим XML файлы
$importData = $this->parseXml($importFile);   // товары, группы, свойства
$offersData = $this->parseXml($offersFile);   // предложения, цены, остатки

// 6. Объединяем данные import + offers по ID товара
$mergedData = $this->mergeData($importData, $offersData);

// 7. Синхронизируем бренды из справочника с таблицей маппинга
$this->syncBrandsFromDirectory($importData);
```

#### 💾 **Заполнение промежуточной таблицы:**
```php
// 8. Сохраняем объединенные данные в dmdev_mallimport1c_temp_import
$this->tempImportService->saveToTemporaryTable($mergedData, $fileLastModified);
```

---

### **Этап 2: Синхронизация с Mall** (`MallSyncService::processBatch()`)

#### 📦 **Пакетная обработка:**
```php
// 1. Получаем N записей со статусом 'pending' из промежуточной таблицы
$pendingItems = $this->tempImportService->getPendingItems($batchSize); // по умолчанию 100

// 2. Для каждой записи:
foreach ($pendingItems as $item) {
    try {
        // Преобразуем данные в формат Mall
        $product = ProductDataTransformer::transform($item->data);
        
        // Синхронизируем с Mall
        $productMall = $this->syncProduct($product);
        
        // Помечаем как обработанную
        $this->tempImportService->updateStatus($item, 'processed');
        
    } catch (\Exception $e) {
        // При ошибке помечаем как 'error'
        $this->tempImportService->updateStatus($item, 'error', $e->getMessage());
    }
}
```

#### 🔄 **Логика создания/обновления товаров** (`syncProduct()`):

```php
// 1. Проверяем существование товара по user_defined_id (ID из 1С)
$existingProduct = Product::where('user_defined_id', $data['id'])->first();
$isNewProduct = !$existingProduct;

// 2. Формируем данные для обновления (только НЕ-null значения)
$productData = array_filter([
    'name' => $data['name'],
    'slug' => str_slug($data['name']),
    'description' => $data['description'],
    'sku' => $data['sku'],
    'stock' => $data['stock'],
    'published' => $data['published'],
    'inventory_management_method' => $data['variant_method'],
]);

// 3. Обрабатываем бренд через систему маппинга (УМНАЯ ЛОГИКА!)
if (!empty($data['brand_id'])) {
    $shouldUpdateBrand = true;
    
    // Если товар уже существует, проверяем, есть ли у него бренд
    if (!$isNewProduct && $existingProduct && $existingProduct->brand_id) {
        $shouldUpdateBrand = false; // НЕ ТРОГАЕМ существующий бренд!
    }
    
    if ($shouldUpdateBrand) {
        $mallBrandId = $this->brandMappingService->getMallBrandId(
            $data['brand_id'], 
            $data['brand_name']
        );
        if ($mallBrandId) {
            $productData['brand_id'] = $mallBrandId;
        }
    }
}
```

#### 🏷️ **Логика категорий (КРИТИЧНО!):**

```php
// 4. Категории назначаются ТОЛЬКО для НОВЫХ товаров
if ($isNewProduct) {
    // Используем двухуровневую логику категорий:
    // website_category_id (приоритет) → group_id (fallback)
    $mallCategoryId = CategoryMappingService::getMallCategoryId(
        $data['website_category_id'] ?? null,
        $data['group_id'] ?? null
    );
    
    $category = MallCategory::find($mallCategoryId);
    if ($category) {
        $categories[] = $category;
    }
}

// 5. Для СУЩЕСТВУЮЩИХ товаров категории НЕ ИЗМЕНЯЮТСЯ!
if (!$isNewProduct) {
    // Категории остаются как были
}
```

#### 💰 **Обновление цен и остатков (ВСЕГДА!):**

```php
// В транзакции:
DB::transaction(function () {
    // 6. Создаем/обновляем основной товар
    $productMall = Product::updateOrCreate(
        ['user_defined_id' => $data['id']],
        $productData
    );
    
    // 7. Назначаем категории только новым товарам
    if ($isNewProduct && !empty($categories)) {
        $productMall->categories()->sync($categoryIds);
    }
    
    // 8. ВСЕГДА обновляем цену основного товара
    if (!empty($data['price'])) {
        $productMall->prices()->updateOrCreate(
            ['currency_id' => 1],
            ['price' => $data['price']]
        );
    }
    
    // 9. ВСЕГДА обновляем варианты (цены + остатки)
    if (!empty($data['variants'])) {
        foreach ($data['variants'] as $variantData) {
            // Создаем/обновляем вариант
            $variant = Variant::updateOrCreate(
                ['user_defined_id' => $variantData['id']], 
                [
                    'product_id' => $productMall->id,
                    'name' => $variantData['name'],
                    'stock' => $variantData['quantity'], // ОСТАТКИ ОБНОВЛЯЮТСЯ!
                    'published' => 1,
                ]
            );
            
            // Обновляем цену варианта
            $variant->prices()->updateOrCreate(
                ['currency_id' => 1],
                [
                    'product_id' => $productMall->id,
                    'price' => $variantData['price'] // ЦЕНЫ ОБНОВЛЯЮТСЯ!
                ]
            );
        }
    }
});
```

---

## �️ **ДЕТАЛЬНАЯ ЛОГИКА РАБОТЫ С БРЕНДАМИ**

### **Алгоритм принятия решений:**

```php
if (!empty($data['brand_id'])) { // Есть бренд в 1С
    $shouldUpdateBrand = true;
    
    // Проверяем существующий товар
    if (!$isNewProduct && $existingProduct && $existingProduct->brand_id) {
        $shouldUpdateBrand = false; // У товара УЖЕ ЕСТЬ БРЕНД - НЕ ТРОГАЕМ!
    }
    
    if ($shouldUpdateBrand) {
        // Ищем соответствие в таблице маппинга
        $mallBrandId = $this->brandMappingService->getMallBrandId(
            $data['brand_id'], 
            $data['brand_name']
        );
        
        if ($mallBrandId) {
            $productData['brand_id'] = $mallBrandId; // ПРОСТАВЛЯЕМ БРЕНД
        }
    }
}
```

### **Сценарии работы:**

#### ✅ **Бренд БУДЕТ ПРОСТАВЛЕН:**
1. **Новый товар** + есть соответствие в таблице маппинга
2. **Существующий товар БЕЗ бренда** + есть соответствие в таблице маппинга

#### ❌ **Бренд НЕ БУДЕТ ИЗМЕНЕН:**
1. **Существующий товар С брендом** (сохраняется ручная настройка админа)
2. **Нет соответствия** в таблице маппинга
3. **Нет бренда** в данных 1С

### **Практические примеры:**

#### **Пример 1: Новый товар**
- 1С: `brand_id="ABC123"`, `brand_name="Nike"`
- Mall: товара нет
- Маппинг: `ABC123 → Mall Brand ID 5`
- **Результат**: товар создается с `brand_id=5`

#### **Пример 2: Существующий товар без бренда**
- 1С: `brand_id="ABC123"`, `brand_name="Nike"`
- Mall: товар есть, но `brand_id=null`
- Маппинг: `ABC123 → Mall Brand ID 5`
- **Результат**: товару проставляется `brand_id=5`

#### **Пример 3: Существующий товар с брендом**
- 1С: `brand_id="ABC123"`, `brand_name="Nike"`
- Mall: товар есть с `brand_id=7` (админ вручную назначил)
- Маппинг: `ABC123 → Mall Brand ID 5`
- **Результат**: бренд НЕ изменяется, остается `brand_id=7`

---

## �🎯 **ОТВЕТЫ НА ВАШИ ВОПРОСЫ**

### ❓ **"При обновлении файла удаляем промежуточную таблицу?"**
✅ **ДА!** При изменении даты файлов выполняется `TempImport::truncate()` - **ПОЛНАЯ ОЧИСТКА** промежуточной таблицы.

### ❓ **"При повторном прогоне изменяем только наличие и цену?"**
✅ **ЧАСТИЧНО ВЕРНО!** 

**Что ВСЕГДА обновляется:**
- ✅ Цены основного товара
- ✅ Цены всех вариантов  
- ✅ Остатки (stock) всех вариантов
- ✅ Описание, SKU, статус публикации

**Что НЕ изменяется для существующих товаров:**
- ❌ Категории (остаются как были назначены)
- ❌ Бренды (если уже заполнен, то не изменяется)
- ❌ Название товара (обновляется только если изменилось в 1С)

**Что изменяется для НОВЫХ товаров:**
- ✅ Все данные включая категории и бренды

**Что изменяется для товаров БЕЗ бренда:**
- ✅ Бренд проставляется если найдено соответствие в таблице маппинга

---

## 🔄 **ПРАКТИЧЕСКИЙ СЦЕНАРИЙ**

### **Первый импорт:**
1. Файлы новые → очистка промежуточной таблицы
2. Парсинг XML → заполнение промежуточной таблицы  
3. Создание товаров с категориями, ценами, остатками

### **Повторный импорт (изменились только остатки):**
1. Файлы обновились → очистка промежуточной таблицы
2. Парсинг XML → заполнение промежуточной таблицы
3. **Существующие товары**: обновляются цены + остатки, категории НЕ трогаются
4. **Новые товары**: создаются с категориями

### **Третий импорт (добавились новые товары):**
1. Повторяется алгоритм 
2. Старые товары → обновление цен/остатков
3. Новые товары → создание с категориями

---

## ⚠️ **ВАЖНЫЕ ОСОБЕННОСТИ**

1. **Промежуточная таблица** - ВСЕГДА очищается при изменении файлов
2. **Категории** - назначаются ТОЛЬКО новым товарам  
3. **Цены/остатки** - обновляются ВСЕГДА для всех товаров
4. **Бренды** - проставляются ТОЛЬКО если у товара НЕТ бренда (v1.0.5)
5. **Пакетная обработка** - по 100 записей за раз для производительности

Это обеспечивает **корректное поведение**: новые товары получают категории и бренды, существующие сохраняют ручные настройки категорий и брендов администратора, но цены/остатки всегда актуальны из 1С!
