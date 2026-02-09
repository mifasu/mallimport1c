<?php

namespace Dmdev\MallImport1c\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Dmdev\MallImport1c\Services\FileProcessor;
use Dmdev\MallImport1c\Services\MallSyncService;
use Dmdev\MallImport1c\Services\TempImportService;

/**
 * Команда для автоматического импорта товаров из 1С в Mall
 * 
 * Использование:
 *   php artisan mallimport1c:run
 *   php artisan mallimport1c:run --batch=500
 * 
 * Особенности:
 * - Защита от параллельных запусков через Cache lock
 * - Обработка только актуального батча (по file_last_modified)
 * - Автоматический выход при отсутствии pending записей
 * - Подходит для запуска из cron каждые N минут
 */
class ImportRunCommand extends Command
{
    /**
     * Имя и сигнатура команды
     *
     * @var string
     */
    protected $signature = 'mallimport1c:run 
                            {--batch=250 : Количество записей для обработки за один запуск}';

    /**
     * Описание команды
     *
     * @var string
     */
    protected $description = 'Запуск импорта товаров из 1С в Mall (батчами)';

    /**
     * Ключ для lock в кеше
     */
    const LOCK_KEY = 'mallimport1c:run:lock';

    /**
     * Время жизни lock (в секундах) - 15 минут
     */
    const LOCK_TTL = 900;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $batchSize = (int) $this->option('batch');

        // Валидация batch size
        if ($batchSize < 1 || $batchSize > 10000) {
            $this->error('Параметр --batch должен быть в диапазоне 1-10000');
            return 1;
        }

        // Шаг 1: Попытка взять lock
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        if (!$lock->get()) {
            $this->warn('⏸️  Импорт уже выполняется в другом процессе. Выход.');
            return 0;
        }

        try {
            $this->info('🔒 Lock получен. Начало обработки...');

            // Шаг 2: Проверка существования файлов
            $importPath = rtrim(config('dmdev.mallimport1c::config.import_path'), '\/') . DIRECTORY_SEPARATOR;
            $importFile = $importPath . config('dmdev.mallimport1c::config.import_file');
            $offersFile = $importPath . config('dmdev.mallimport1c::config.offers_file');

            if (!file_exists($importFile) || !file_exists($offersFile)) {
                $this->warn('⚠️  Файлы импорта не найдены:');
                $this->line("   Import: {$importFile}");
                $this->line("   Offers: {$offersFile}");
                $this->info('Выход (нечего импортировать).');
                return 0;
            }

            // Шаг 3: Вычисление timestamp текущих файлов через сервис
            $tempImportService = new TempImportService();
            $currentStampFormatted = $tempImportService->calculateCurrentFileStamp($importFile, $offersFile);

            $importMtime = filemtime($importFile);
            $offersMtime = filemtime($offersFile);

            $this->info("📁 Файлы найдены:");
            $this->line("   Import: " . Carbon::createFromTimestamp($importMtime, 'UTC')->format('Y-m-d H:i:s'));
            $this->line("   Offers: " . Carbon::createFromTimestamp($offersMtime, 'UTC')->format('Y-m-d H:i:s'));
            $this->line("   Current stamp: {$currentStampFormatted}");

            // Шаг 4: Обработка XML файлов (FileProcessor сам решит - загружать или нет)
            $this->info('🔄 Проверка актуальности данных...');
            
            $fileProcessor = new FileProcessor();
            try {
                $fileProcessor->processFiles();
                $this->info('✅ Данные актуальны (или обновлены).');
            } catch (\Exception $e) {
                $this->error('❌ Ошибка при обработке файлов: ' . $e->getMessage());
                Log::error('ImportRunCommand: FileProcessor failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return 1;
            }

            // Шаг 5: Проверка наличия pending записей для текущего батча
            $pendingCount = $tempImportService->countPending($currentStampFormatted);

            if ($pendingCount === 0) {
                $this->info('✅ Нет записей для обработки (pending = 0). Выход.');
                return 0;
            }

            $this->info("📊 Найдено записей для обработки: {$pendingCount}");
            $this->info("🔧 Размер батча: {$batchSize}");

            // Шаг 6: Обработка батча
            $mallSync = new MallSyncService();
            
            $this->info('⚙️  Обработка батча...');
            $startTime = microtime(true);

            try {
                $results = $mallSync->processBatch($batchSize, $currentStampFormatted);
                
                $duration = round(microtime(true) - $startTime, 2);
                $processedCount = count(array_filter($results, fn($r) => $r['status'] === 'processed'));
                $errorCount = count(array_filter($results, fn($r) => $r['status'] === 'error'));

                $this->info("✅ Батч обработан за {$duration} сек.");
                $this->line("   Обработано успешно: {$processedCount}");
                
                if ($errorCount > 0) {
                    $this->warn("   ⚠️  С ошибками: {$errorCount}");
                }

                // Шаг 7: Финальная статистика
                $pendingRemaining = $tempImportService->countPending($currentStampFormatted);
                $totalProcessed = $tempImportService->countProcessed($currentStampFormatted);
                $totalErrors = $tempImportService->countErrors($currentStampFormatted);

                $this->info('');
                $this->info('📈 Статистика текущего батча (stamp: ' . $currentStampFormatted . '):');
                $this->line("   Всего обработано: {$totalProcessed}");
                $this->line("   Всего ошибок: {$totalErrors}");
                $this->line("   Осталось pending: {$pendingRemaining}");

                if ($pendingRemaining > 0) {
                    $this->info('');
                    $this->comment('💡 Есть ещё записи для обработки. Запустите команду снова или дождитесь следующего запуска cron.');
                }

            } catch (\Exception $e) {
                $this->error('❌ Ошибка при обработке батча: ' . $e->getMessage());
                Log::error('ImportRunCommand: MallSyncService failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return 1;
            }

            return 0;

        } finally {
            // Освобождаем lock в любом случае
            $lock->release();
            $this->info('🔓 Lock освобождён.');
        }
    }
}
