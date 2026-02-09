<?php 

namespace Dmdev\MallImport1c\Controllers;

use Backend\Classes\Controller;
use Dmdev\MallImport1c\Services\FileProcessor;
use Dmdev\MallImport1c\Services\MallSyncService;
use Dmdev\MallImport1c\Models\TempImport;

class Test extends Controller
{
    public function index()
    {


        set_time_limit(300);

        $fileProcessor = new FileProcessor();
        $mallSyncService = new MallSyncService();

        try {
            // Обработка файлов, если есть свежие - переопределяем временную таблицу
            $testdata = $fileProcessor->processFiles();

            // Обрабатываем 50 записей
            $testdata = $results = $mallSyncService->processBatch(250);

            //return $this->makePartial('test', ['results' => $results]);

            // Синхронизация с Mall
            //$testdataarray = $mallSyncService->sync($data);
            //$testdata = json_decode(json_encode($testdataarray), true);

        } catch (\Exception $e) {
            return $this->makePartial('error', ['message' => $e->getMessage()]);
        }

        return $this->makePartial('test', ['data' => $testdata]);
        //return $this->makePartial('test', ['data' => $data]);
    }
}