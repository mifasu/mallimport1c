<?php
namespace Dmdev\MallImport1c\Services;

use SimpleXMLElement;
use Dmdev\MallImport1c\Services\TempImportService;
use Dmdev\MallImport1c\Services\BrandMappingService;


class FileProcessor
{
    protected $importPath;
    protected $importFile;
    protected $offersFile;
    protected $tempImportService;
    protected $brandMappingService;

    public function __construct()
    {
        // Нормализация пути с использованием DIRECTORY_SEPARATOR
        $importPath = config('dmdev.mallimport1c::config.import_path');
        $this->importPath = rtrim($importPath, '\\/') . DIRECTORY_SEPARATOR;
        $this->importFile = config('dmdev.mallimport1c::config.import_file');
        $this->offersFile = config('dmdev.mallimport1c::config.offers_file');
        $this->tempImportService = new TempImportService();
        $this->brandMappingService = new BrandMappingService();
    }

    /**
     * Загружает и объединяет данные из XML-файлов.
     *
     * 
     */
    public function processFiles()
    {
        $importFile = $this->importPath . $this->importFile;
        $offersFile = $this->importPath . $this->offersFile;

        if (!file_exists($importFile) || !file_exists($offersFile)) {
            throw new \Exception('processFiles: Файлы импорта не найдены. Путь: ' . $this->importPath);
        }

        // Получаем дату последнего изменения файлов через централизованный метод
        $fileLastModified = $this->tempImportService->calculateCurrentFileStamp($importFile, $offersFile);

        // Проверяем первую запись в таблице
        $firstRecord = $this->tempImportService->getFirstRecord();

        //throw new \Exception('test: ' . $fileLastModified . ' -'. $firstRecord->file_last_modified );

        // Сравниваем timestamp в UTC для избежания проблем с timezone
        if ($firstRecord) {
            $firstRecordStamp = \Carbon\Carbon::parse($firstRecord->file_last_modified)
                ->timezone('UTC')
                ->format('Y-m-d H:i:s');
            
            if ($firstRecordStamp == $fileLastModified) {
                // Если дата совпадает, данные уже актуальны, ничего не делаем
                return;
            }
        }

        // Если дата не совпадает, очищаем таблицу
        $this->tempImportService->clearTable();

        // Парсим XML-файлы
        $importData = $this->parseXml($importFile);
        $offersData = $this->parseXml($offersFile);

        // Объединяем данные
        $mergedData = $this->mergeData($importData, $offersData);

        // Синхронизируем бренды с таблицей маппинга
        $this->syncBrandsFromDirectory($importData);

        // Сохраняем данные в таблицу
        $this->tempImportService->saveToTemporaryTable($mergedData, $fileLastModified);

        //return $this->mergeData($importData, $offersData);
    }

    /**
     * Парсит XML-файл в массив с учетом пространств имен.
     * Использует DOMDocument с восстановлением для обработки битых XML из 1С.
     *
     * @param string $filePath
     * @return array
     */
    protected function parseXml(string $filePath): array
    {
        libxml_use_internal_errors(true);
        
        $dom = new \DOMDocument('1.0', 'UTF-8');
        
        // LIBXML_PARSEHUGE - для больших файлов
        // LIBXML_RECOVER - восстанавливает битую структуру XML
        // LIBXML_NOERROR - подавляет ошибки
        // LIBXML_NOWARNING - подавляет предупреждения
        $options = LIBXML_PARSEHUGE | LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING;
        
        $loaded = $dom->load($filePath, $options);
        
        if (!$loaded) {
            $errors = array_map(function ($error) {
                return sprintf("[%d:%d] %s", $error->line, $error->column, trim($error->message));
            }, libxml_get_errors());
            libxml_clear_errors();
            throw new \Exception('parseXml: Не удалось загрузить XML: ' . implode('; ', $errors));
        }
        
        libxml_clear_errors();
        
        // Конвертируем DOMDocument в SimpleXMLElement для удобства
        $xml = simplexml_import_dom($dom);
        
        if ($xml === false) {
            throw new \Exception('parseXml: Не удалось преобразовать DOM в SimpleXML');
        }

        return json_decode(json_encode($xml), true);
    }
    
    /**
     * Очищает XML-файл от неэкранированных спецсимволов потоково.
     *
     * @param string $filePath
     * @return string Путь к очищенному файлу
     */
    protected function sanitizeXmlFile(string $filePath): string
    {
        $tempFile = sys_get_temp_dir() . '/import_clean_' . basename($filePath);
        
        $input = fopen($filePath, 'r');
        $output = fopen($tempFile, 'w');
        
        if (!$input || !$output) {
            throw new \Exception('Не удалось открыть файлы для очистки XML');
        }
        
        $buffer = '';
        $inTag = false;
        
        while (($chunk = fread($input, 8192)) !== false) {
            if ($chunk === '') break;
            
            $buffer .= $chunk;
            
            // Обрабатываем буфер посимвольно
            $processed = '';
            $len = strlen($buffer);
            
            for ($i = 0; $i < $len; $i++) {
                $char = $buffer[$i];
                
                // Определяем, внутри тега или нет
                if ($char === '<') {
                    // Проверяем, что это реально тег (следующий символ - буква, /, ? или !)
                    if ($i + 1 < $len && preg_match('/[A-Za-zА-Яа-яЁё\/\?!]/', $buffer[$i + 1])) {
                        $inTag = true;
                        $processed .= $char;
                    } else {
                        // Это не тег, экранируем
                        $processed .= '&lt;';
                    }
                } elseif ($char === '>') {
                    $processed .= $char;
                    $inTag = false;
                } elseif ($char === '&' && !$inTag) {
                    // Проверяем, не экранированная ли уже сущность
                    $remaining = substr($buffer, $i);
                    if (preg_match('/^&(amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);/', $remaining)) {
                        $processed .= $char;
                    } else {
                        $processed .= '&amp;';
                    }
                } else {
                    $processed .= $char;
                }
            }
            
            fwrite($output, $processed);
            $buffer = '';
        }
        
        fclose($input);
        fclose($output);
        
        return $tempFile;
    }

    /**
     * Извлекает справочники свойств из классификатора.
     *
     * @param array $properties
     * @return array
     */
    protected function extractPropertiesDirectory(array $properties): array
    {
        $directories = [];
        
        // Получаем ID справочников из конфига
        $brandPropertyId = config('dmdev.mallimport1c::config.brand_property_id');
        $categoryPropertyId = config('dmdev.mallimport1c::config.website_category_property_id');
        
        if (isset($properties['Свойство'])) {
            $props = $properties['Свойство'];
            
            // Если свойство одно, приводим к массиву
            if (isset($props['Ид'])) {
                $props = [$props];
            }
            
            foreach ($props as $property) {
                $propertyId = $property['Ид'] ?? '';
                
                if ($propertyId === $brandPropertyId) {
                    $directories['brands'] = $this->extractDirectoryValues($property['ВариантыЗначений']['Справочник'] ?? []);
                } elseif ($propertyId === $categoryPropertyId) {
                    $directories['categories'] = $this->extractDirectoryValues($property['ВариантыЗначений']['Справочник'] ?? []);
                }
            }
        }
        
        return $directories;
    }

    /**
     * Извлекает значения справочника.
     *
     * @param array $directoryItems
     * @return array
     */
    protected function extractDirectoryValues(array $directoryItems): array
    {
        $result = [];
        
        // Если элемент один, приводим к массиву
        if (isset($directoryItems['ИдЗначения'])) {
            $directoryItems = [$directoryItems];
        }
        
        foreach ($directoryItems as $item) {
            $id = $item['ИдЗначения'] ?? '';
            $value = $item['Значение'] ?? '';
            
            if ($id && $value) {
                $result[$id] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Извлекает значения свойств товара (бренд, категория для сайта, расширенное описание).
     *
     * @param array $productProperties
     * @return array
     */
    protected function extractProductProperties(array $productProperties): array
    {
        $result = [];
        
        // Получаем ID справочников из конфига
        $brandPropertyId = config('dmdev.mallimport1c::config.brand_property_id');
        $categoryPropertyId = config('dmdev.mallimport1c::config.website_category_property_id');
        $extendedDescriptionPropertyId = '806e49fb-a475-11f0-85ec-04421a2fb3ab'; // ID "Расширенное Описание"
        
        if (isset($productProperties['ЗначенияСвойства'])) {
            $properties = $productProperties['ЗначенияСвойства'];
            
            // Если свойство одно, приводим к массиву
            if (isset($properties['Ид'])) {
                $properties = [$properties];
            }
            
            foreach ($properties as $property) {
                $propertyId = $property['Ид'] ?? '';
                $valueId = $property['Значение'] ?? '';
                
                if ($propertyId === $brandPropertyId) {
                    $result['brand_id'] = $valueId;
                } elseif ($propertyId === $categoryPropertyId) {
                    $result['website_category_id'] = $valueId;
                } elseif ($propertyId === $extendedDescriptionPropertyId) {
                    $result['extended_description'] = $valueId;
                }
            }
        }
        
        return $result;
    }



    /**
     * Извлекает характеристики товара из массива.
     *
     * @param array $characteristics
     * @return array
     */
    protected function extractCharacteristics(array $characteristics): array
    {
        $result = [];
    
        // Если характеристика одна, она может быть не массивом
        if (isset($characteristics['Ид'])) {
            $characteristics = [$characteristics];
        }
    
        foreach ($characteristics as $characteristic) {
            $name = $characteristic['Наименование'] ?? null;
            $value = $characteristic['Значение'] ?? null;
    
            if ($name && $value) {
                $result[$name] = $value;
            }
        }
    
        return $result;
    }



    /**
     * Объединяет данные из import.xml и offers.xml.
     *
     * @param array $importData
     * @param array $offersData
     * @return array
     */
    protected function mergeData(array $importData, array $offersData): array
    {
        $merged = [];

        // Проверяем наличие ключей в массиве
        $products = $importData['Каталог']['Товары']['Товар'] ?? [];
        $offers = $offersData['ПакетПредложений']['Предложения']['Предложение'] ?? [];
        
        // Извлекаем справочники из классификатора
        $directories = $this->extractPropertiesDirectory($importData['Классификатор']['Свойства'] ?? []);

        // Группируем предложения по ID товара (до символа #)
        $groupedOffers = [];
        foreach ($offers as $offer) {
            $productId = explode('#', $offer['Ид'])[0]; // Извлекаем ID товара до символа #
            if (!isset($groupedOffers[$productId])) {
                $groupedOffers[$productId] = [];
            }

            // Извлекаем характеристики
            $characteristics = $this->extractCharacteristics($offer['ХарактеристикиТовара']['ХарактеристикаТовара'] ?? []);

            // Формируем предложение
            $offerData = [
                'id' => $offer['Ид'],
                'name' => $this->sanitizeValue($offer['Наименование'] ?? ''),
                'price' => $offer['Цены']['Цена']['ЦенаЗаЕдиницу'] ?? 0,
                'quantity' => $offer['Количество'] ?? 0,
            ];

            // Добавляем характеристики только если они есть
            if (!empty($characteristics)) {
                $offerData['characteristics'] = $characteristics;
            }

            $groupedOffers[$productId][] = $offerData;
        }

        // Объединяем товары и их предложения
        foreach ($products as $product) {
            $id = $product['Ид'];
            
            // Извлекаем дополнительные свойства товара (бренд, категория для сайта)
            $productProperties = $this->extractProductProperties($product['ЗначенияСвойств'] ?? []);

            // Определяем описание: приоритет у "Расширенное Описание", если его нет - берем стандартное "Описание"
            $description = '';
            if (isset($productProperties['extended_description']) && !empty($productProperties['extended_description'])) {
                $description = $this->sanitizeValue($productProperties['extended_description']);
            } else {
                $description = $this->sanitizeValue($product['Описание'] ?? '');
            }
            
            // Форматируем описание (переносы строк → параграфы)
            $description = $this->formatDescription($description);
            
            $productData = [
                'id' => $id,
                'name' => $this->sanitizeValue($product['Наименование'] ?? ''),
                'sku' => $this->sanitizeValue($product['Артикул'] ?? ''),
                'category_id' => $this->sanitizeValue($product['Группы']['Ид'] ?? null),
                'description' => $description,
                'offers' => isset($groupedOffers[$id]) && !empty($groupedOffers[$id]) ? $groupedOffers[$id] : null,
            ];
            
            // Добавляем бренд если есть
            if (isset($productProperties['brand_id'])) {
                $productData['brand_id'] = $productProperties['brand_id'];
                $productData['brand_name'] = $directories['brands'][$productProperties['brand_id']] ?? null;
            }
            
            // Добавляем категорию для сайта если есть
            if (isset($productProperties['website_category_id'])) {
                $productData['website_category_id'] = $productProperties['website_category_id'];
                $productData['website_category_name'] = $directories['categories'][$productProperties['website_category_id']] ?? null;
            }

            $merged[] = $productData;
        }

        return $merged;
    }
    /**
     * Преобразует пустые массивы в пустую строку или null.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function sanitizeValue($value)
    {
        if (is_array($value) && empty($value)) {
            return null; // Или null, если нужно
        }

        return $value;
    }

    /**
     * Форматирует описание товара: преобразует переносы строк в параграфы.
     *
     * @param string $description
     * @return string
     */
    protected function formatDescription($description)
    {
        if (empty($description)) {
            return '';
        }

        // Удаляем лишние пробелы по краям
        $description = trim($description);

        // Разбиваем текст на абзацы (по двойному переносу строки или одинарному)
        $paragraphs = preg_split('/\n\s*\n/', $description);

        // Оборачиваем каждый непустой абзац в <p>
        $formatted = array_map(function($paragraph) {
            $paragraph = trim($paragraph);
            if (!empty($paragraph)) {
                // Заменяем одинарные переносы внутри абзаца на <br>
                $paragraph = nl2br($paragraph);
                return '<p>' . $paragraph . '</p>';
            }
            return '';
        }, $paragraphs);

        return implode("\n", array_filter($formatted));
    }

    /**
     * Ищет предложение по ID.
     *
     * @param array $offers
     * @param string $id
     * @return array|null
     */
    protected function findOfferById(array $offers, string $id): ?array
    {
        foreach ($offers as $offer) {
            if ($offer['Ид'] === $id) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * Синхронизирует бренды из справочника с таблицей маппинга
     *
     * @param array $importData
     */
    protected function syncBrandsFromDirectory(array $importData)
    {
        try {
            // Извлекаем справочники из классификатора
            $directories = $this->extractPropertiesDirectory($importData['Классификатор']['Свойства'] ?? []);
            
            if (!empty($directories['brands'])) {
                // Синхронизируем бренды через сервис
                $this->brandMappingService->syncBrandsFromDirectory($directories['brands']);
                
                \Illuminate\Support\Facades\Log::info('FileProcessor: Синхронизация брендов завершена', [
                    'brands_count' => count($directories['brands'])
                ]);
            } else {
                \Illuminate\Support\Facades\Log::warning('FileProcessor: Справочник брендов не найден в XML');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('FileProcessor: Ошибка синхронизации брендов', [
                'error' => $e->getMessage()
            ]);
        }
    }



}