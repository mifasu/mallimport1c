<?php
/**
 * DebugExchangeController
 * 
 * Контроллер для прослушки и отладки обмена с 1С.
 * 
 * Путь: plugins/dmdev/mallimport1c/http/controllers/DebugExchangeController.php
 * 
 * Функции:
 * - Принимает ЛЮБЫЕ HTTP-запросы от 1С (GET, POST, PUT, DELETE и т.д.)
 * - Проверяет Basic Auth (если настроен в конфиге)
 * - Логирует всю информацию о запросе в отдельный лог-файл
 * - Сохраняет копии всех загруженных файлов
 * - Возвращает "success" для продолжения обмена
 * 
 * Настройка:
 * 1. Добавить в .env (опционально):
 *    MALL_IMPORT_1C_DEBUG_USER=your_login
 *    MALL_IMPORT_1C_DEBUG_PASS=your_password
 * 
 * 2. Если логин/пароль не заданы - эндпоинт открыт без авторизации
 * 
 * 3. Логи пишутся в: storage/logs/1c_exchange.log
 * 4. Файлы сохраняются в: storage/app/1c_debug/
 */

namespace Dmdev\MallImport1c\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DebugExchangeController extends Controller
{
    /**
     * Максимальный размер raw body для логирования (байты)
     */
    const MAX_RAW_BODY_LOG_SIZE = 200000;

    /**
     * Обработка всех входящих запросов от 1С
     */
    public function handle(Request $request)
    {
        // 1. Проверка Basic Auth
        if (!$this->checkBasicAuth($request)) {
            return response("Unauthorized\n", 401)
                ->header('WWW-Authenticate', 'Basic realm="1C Debug Exchange"')
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        // 2. Сбор информации о запросе
        $logData = $this->collectRequestData($request);

        // 3. Сохранение файлов (если есть)
        $savedFiles = [];
        
        // 3a. Сохранение файлов из multipart/form-data
        $uploadedFiles = $this->saveUploadedFiles($request);
        if (!empty($uploadedFiles)) {
            $savedFiles = array_merge($savedFiles, $uploadedFiles);
        }
        
        // 3b. Сохранение файла из raw body (mode=file)
        $rawBodyFile = $this->saveRawBodyFile($request);
        if ($rawBodyFile) {
            $savedFiles[] = $rawBodyFile;
        }
        
        if (!empty($savedFiles)) {
            $logData['saved_files'] = $savedFiles;
        }

        // 4. Логирование в отдельный канал
        try {
            Log::channel('1c_exchange')->info('1C DEBUG EXCHANGE', $logData);
        } catch (\Exception $e) {
            // Если канал не настроен, логируем в основной лог
            Log::error('Failed to write to 1c_exchange log channel', [
                'error' => $e->getMessage(),
                'data' => $logData
            ]);
        }

        // 5. Формирование ответа согласно протоколу CommerceML
        return $this->generate1CResponse($request);
    }

    /**
     * Генерирует ответ согласно протоколу обмена 1С (CommerceML)
     */
    protected function generate1CResponse(Request $request)
    {
        $mode = $request->input('mode', $request->query('mode'));
        $type = $request->input('type', $request->query('type'));

        // Ответ в зависимости от режима обмена
        switch ($mode) {
            case 'checkauth':
                // Проверка авторизации
                return response("success\nsessid\ntimestamp", 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');

            case 'init':
                // Инициализация обмена - возвращаем параметры
                $response = "zip=no\n";
                $response .= "file_limit=10485760\n"; // 10MB
                return response($response, 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');

            case 'file':
                // Получение файла - подтверждаем успех
                return response("success\n", 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');

            case 'import':
                // Импорт данных - подтверждаем успех
                return response("success\n", 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');

            case 'deactivate':
                // Деактивация товаров
                return response("success\n", 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');

            case 'complete':
                // Завершение обмена
                return response("success\n", 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');

            default:
                // По умолчанию - успех
                return response("success\n", 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /**
     * Проверка HTTP Basic Authentication
     */
    protected function checkBasicAuth(Request $request): bool
    {
        $configUser = config('dmdev.mallimport1c.debug_exchange_user');
        $configPass = config('dmdev.mallimport1c.debug_exchange_pass');

        // Если логин/пароль не заданы - авторизация отключена
        if (empty($configUser) && empty($configPass)) {
            return true;
        }

        // Проверяем учётные данные
        $user = $request->getUser();
        $pass = $request->getPassword();

        return $user === $configUser && $pass === $configPass;
    }

    /**
     * Сбор всей информации о запросе для логирования
     */
    protected function collectRequestData(Request $request): array
    {
        $data = [
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'uri' => $request->getRequestUri(),
            'query_params' => $request->query->all(),
            'headers' => $request->headers->all(),
            'post_params' => [],
            'files_info' => [],
            'raw_body' => null,
            'raw_body_truncated' => false,
            'raw_body_length' => 0,
            'files_detected_in_raw_body' => false,
        ];

        // POST параметры (без файлов)
        $fileKeys = array_keys($request->files->all());
        $data['post_params'] = $request->except($fileKeys);

        // Информация о файлах
        $data['files_info'] = $this->collectFilesInfo($request);

        // Определяем режим обмена
        $mode = $request->input('mode', $request->query('mode'));

        // Сырое тело запроса
        $rawBody = $request->getContent();
        $bodyLength = strlen($rawBody);
        $data['raw_body_length'] = $bodyLength;

        if ($bodyLength > 0) {
            // Если mode=file - это передача файла в теле запроса, не логируем
            if ($mode === 'file') {
                $data['raw_body'] = null;
                $data['files_detected_in_raw_body'] = true;
                $data['raw_body_truncated'] = true;
            }
            // Если файлы были загружены через multipart, не логируем тело
            elseif (!empty($data['files_info'])) {
                $data['raw_body'] = null;
                $data['files_detected_in_raw_body'] = true;
                $data['raw_body_truncated'] = true;
            } elseif ($bodyLength <= self::MAX_RAW_BODY_LOG_SIZE) {
                $data['raw_body'] = $rawBody;
            } else {
                $data['raw_body'] = substr($rawBody, 0, self::MAX_RAW_BODY_LOG_SIZE);
                $data['raw_body_truncated'] = true;
            }
        }

        return $data;
    }

    /**
     * Сбор информации о загруженных файлах
     */
    protected function collectFilesInfo(Request $request): array
    {
        $filesInfo = [];

        foreach ($request->files->all() as $fieldName => $files) {
            // Если это массив файлов
            if (is_array($files)) {
                foreach ($files as $index => $file) {
                    if ($file && $file->isValid()) {
                        $filesInfo[] = [
                            'field_name' => $fieldName . '[' . $index . ']',
                            'original_name' => $file->getClientOriginalName(),
                            'size' => $file->getSize(),
                            'mime_type' => $file->getMimeType(),
                        ];
                    }
                }
            } else {
                // Одиночный файл
                $file = $files;
                if ($file && $file->isValid()) {
                    $filesInfo[] = [
                        'field_name' => $fieldName,
                        'original_name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ];
                }
            }
        }

        return $filesInfo;
    }

    /**
     * Сохранение копий загруженных файлов
     */
    protected function saveUploadedFiles(Request $request): array
    {
        $savedFiles = [];
        $timestamp = now()->format('Ymd_His');

        foreach ($request->files->all() as $fieldName => $files) {
            // Обработка массива файлов
            if (is_array($files)) {
                foreach ($files as $index => $file) {
                    if ($file && $file->isValid()) {
                        $saved = $this->saveFile($file, $fieldName, $timestamp);
                        if ($saved) {
                            $savedFiles[] = $saved;
                        }
                    }
                }
            } else {
                // Одиночный файл
                $file = $files;
                if ($file && $file->isValid()) {
                    $saved = $this->saveFile($file, $fieldName, $timestamp);
                    if ($saved) {
                        $savedFiles[] = $saved;
                    }
                }
            }
        }

        return $savedFiles;
    }

    /**
     * Сохранение одного файла
     */
    protected function saveFile($file, string $fieldName, string $timestamp): ?array
    {
        try {
            $originalName = $file->getClientOriginalName();
            $random = Str::random(8);
            $fileName = "{$timestamp}__{$random}__{$originalName}";
            
            // Создаём директорию если не существует
            $debugDir = storage_path('app/1c_debug');
            if (!is_dir($debugDir)) {
                mkdir($debugDir, 0755, true);
            }
            
            // Сохраняем файл напрямую (архивная копия)
            $fullPath = $debugDir . '/' . $fileName;
            $file->move($debugDir, $fileName);

            // Атомарное обновление current-файла (если это import или offers)
            $this->updateCurrentFile($originalName, $fullPath);

            return [
                'field_name' => $fieldName,
                'original_name' => $originalName,
                'saved_path' => '1c_debug/' . $fileName,
                'saved_full_path' => $fullPath,
                'size' => filesize($fullPath),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to save uploaded file', [
                'field_name' => $fieldName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Сохранение файла из raw body (когда 1С передаёт файл напрямую в теле запроса)
     */
    protected function saveRawBodyFile(Request $request): ?array
    {
        $mode = $request->input('mode', $request->query('mode'));
        
        // Если mode != file, значит это не передача файла
        if ($mode !== 'file') {
            return null;
        }

        // Получаем имя файла из параметра filename
        $filename = $request->input('filename', $request->query('filename'));
        if (empty($filename)) {
            $filename = 'unknown.xml';
        }

        // Получаем содержимое
        $rawBody = $request->getContent();
        if (empty($rawBody)) {
            return null;
        }

        try {
            $timestamp = now()->format('Ymd_His');
            $random = Str::random(8);
            $fileName = "{$timestamp}__{$random}__{$filename}";
            
            // Создаём директорию если не существует
            $debugDir = storage_path('app/1c_debug');
            if (!is_dir($debugDir)) {
                mkdir($debugDir, 0755, true);
            }
            
            // Сохраняем файл (архивная копия)
            $fullPath = $debugDir . '/' . $fileName;
            file_put_contents($fullPath, $rawBody);

            // Атомарное обновление current-файла (если это import или offers)
            $this->updateCurrentFile($filename, $fullPath);

            return [
                'field_name' => 'raw_body',
                'original_name' => $filename,
                'saved_path' => '1c_debug/' . $fileName,
                'saved_full_path' => $fullPath,
                'size' => filesize($fullPath),
                'source' => 'raw_body',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to save raw body file', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Атомарное обновление current-файлов
     * Использует строгий whitelist для определения типа файла
     */
    protected function updateCurrentFile(string $originalName, string $sourcePath): void
    {
        // Строгий whitelist для определения типа файла
        $basename = strtolower(basename($originalName));
        
        $targetName = null;
        switch ($basename) {
            case 'import0_1.xml':
            case 'import.xml':
                $targetName = 'import.xml';
                break;
            case 'offers0_1.xml':
            case 'offers.xml':
                $targetName = 'offers.xml';
                break;
            default:
                // Файл не в whitelist - не обновляем current
                return;
        }

        try {
            // Создаём директорию current если не существует
            $currentDir = storage_path('app/1c_debug/current');
            if (!is_dir($currentDir)) {
                mkdir($currentDir, 0755, true);
            }

            $targetPath = $currentDir . DIRECTORY_SEPARATOR . $targetName;
            $tempPath = $targetPath . '.tmp';

            // Атомарная запись: сначала во временный файл, потом rename
            copy($sourcePath, $tempPath);
            rename($tempPath, $targetPath);

            Log::channel('1c_exchange')->info('Current file updated', [
                'original_name' => $originalName,
                'target_name' => $targetName,
                'target_path' => $targetPath,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update current file', [
                'original_name' => $originalName,
                'target_name' => $targetName,
                'error' => $e->getMessage()
            ]);
        }
    }
}
