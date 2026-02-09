<?php namespace Dmdev\MallImport1c\Services;

use Dmdev\MallImport1c\Models\TempImport;

class TempImportService
{
    /**
     * Сохранение данных в временную таблицу.
     *
     * @param array $mergedData
     * @param string $fileLastModified
     */
    public function saveToTemporaryTable(array $mergedData, string $fileLastModified)
    {
        foreach ($mergedData as $item) {
            TempImport::updateOrCreate(
                ['product_id' => $item['id']], // Уникальный идентификатор
                [
                    'name' => $item['name'] ?? '',
                    'sku' => $item['sku'] ?? '',
                    'category_id' => $item['category_id'] ?? null,
                    'brand_id' => $item['brand_id'] ?? null,
                    'brand_name' => $item['brand_name'] ?? null,
                    'website_category_id' => $item['website_category_id'] ?? null,
                    'website_category_name' => $item['website_category_name'] ?? null,
                    'description' => $item['description'] ?? '',
                    'data' => $item,
                    'status' => 'pending',
                    'file_last_modified' => $fileLastModified,
                ]
            );
        }
    }

    /**
     * Получение записей со статусом "pending".
     *
     * @param int $limit
     * @param string|null $fileLastModified Фильтр по дате файла (опционально)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPendingItems(int $limit = 100, ?string $fileLastModified = null)
    {
        $query = TempImport::where('status', 'pending');
        
        // Если указан timestamp файла - фильтруем только записи этого батча
        if ($fileLastModified !== null) {
            $query->where('file_last_modified', $fileLastModified);
        }
        
        return $query->limit($limit)->get();
    }

    /**
     * Подсчёт количества pending записей
     *
     * @param string|null $fileLastModified Фильтр по дате файла (опционально)
     * @return int
     */
    public function countPending(?string $fileLastModified = null): int
    {
        $query = TempImport::where('status', 'pending');
        
        if ($fileLastModified !== null) {
            $query->where('file_last_modified', $fileLastModified);
        }
        
        return $query->count();
    }

    /**
     * Подсчёт количества processed записей для конкретного батча
     *
     * @param string $fileLastModified
     * @return int
     */
    public function countProcessed(string $fileLastModified): int
    {
        return TempImport::where('status', 'processed')
            ->where('file_last_modified', $fileLastModified)
            ->count();
    }

    /**
     * Подсчёт количества error записей для конкретного батча
     *
     * @param string $fileLastModified
     * @return int
     */
    public function countErrors(string $fileLastModified): int
    {
        return TempImport::where('status', 'error')
            ->where('file_last_modified', $fileLastModified)
            ->count();
    }

    /**
     * Обновление статуса записи.
     *
     * @param TempImport $item
     * @param string $status
     * @param string|null $errorMessage
     */
    public function updateStatus(TempImport $item, string $status, ?string $errorMessage = null)
    {
        $item->update([
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Проверка, есть ли записи со статусом "pending".
     *
     * @return bool
     */
    public function hasPendingItems(): bool
    {
        return TempImport::hasPending();
    }

    // Получение первой записи в таблице.
    public function getFirstRecord()
    {
        return TempImport::orderBy('id', 'asc')->first();
    }

    //очищаем таблицу
    public function clearTable()
    {
        TempImport::truncate();
    }

    /**
     * Вычисление timestamp файлов для консистентного сравнения с БД
     * Использует UTC для избежания проблем с timezone
     *
     * @param string $importFile Путь к import.xml
     * @param string $offersFile Путь к offers.xml
     * @return string Отформатированный timestamp в формате 'Y-m-d H:i:s'
     */
    public function calculateCurrentFileStamp(string $importFile, string $offersFile): string
    {
        if (!file_exists($importFile) || !file_exists($offersFile)) {
            throw new \Exception('calculateCurrentFileStamp: Файлы не найдены');
        }

        $importMtime = filemtime($importFile);
        $offersMtime = filemtime($offersFile);
        $maxTimestamp = max($importMtime, $offersMtime);

        // Используем Carbon с UTC для избежания расхождений timezone
        return \Carbon\Carbon::createFromTimestamp($maxTimestamp, 'UTC')->format('Y-m-d H:i:s');
    }





}