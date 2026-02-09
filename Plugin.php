<?php namespace Dmdev\MallImport1c;

use Backend;
use System\Classes\PluginBase;
use Illuminate\Support\Facades\Route;

class Plugin extends PluginBase
{
    /**
     * Регистрация публичных роутов для обмена с 1С
     */
    public function boot()
    {
        // Регистрируем публичный эндпоинт для отладки обмена с 1С
        // URL: /1c-debug-exchange
        // Принимает любые HTTP-методы (GET, POST, PUT, DELETE и т.д.)
        Route::any('/1c-debug-exchange', function (\Illuminate\Http\Request $request) {
            $controller = new \Dmdev\MallImport1c\Http\Controllers\DebugExchangeController();
            return $controller->handle($request);
        })->middleware('web');
    }

    public function pluginDetails()
    {
        return [
            'name'        => 'Mall Import 1C',
            'description' => 'Плагин для импорта товаров из 1С в Mall с поддержкой автоматизации, иерархического маппинга категорий и брендов.',
            'author'      => 'Dmdev',
            'icon'        => 'icon-upload',
            'version'     => '1.0.8.2'
        ];
    }

    public function registerNavigation()
    {
        return [
            'mallimport1c' => [
                'label'       => 'Импорт 1С',
                'url'         => Backend::url('dmdev/mallimport1c/test'),
                'icon'        => 'icon-upload',
                'permissions' => ['dmdev.mallimport1c.*'],
                'order'       => 500,
                'sideMenu' => [
                    'test' => [
                        'label'       => 'Тестовый импорт',
                        'icon'        => 'icon-play',
                        'url'         => Backend::url('dmdev/mallimport1c/test'),
                    ],
                    'brandmappings' => [
                        'label'       => 'Маппинг брендов',
                        'icon'        => 'icon-tags',
                        'url'         => Backend::url('dmdev/mallimport1c/brandmappings'),
                    ],
                ],
            ],
        ];
    }

    public function registerPermissions()
    {
        return [
            'dmdev.mallimport1c.access_test' => [
                'tab' => 'Импорт 1С',
                'label' => 'Доступ к тестовому импорту',
                'comment' => 'Позволяет запускать тестовый импорт товаров',
            ],
            'dmdev.mallimport1c.access_brand_mappings' => [
                'tab' => 'Импорт 1С',
                'label' => 'Управление маппингом брендов',
                'comment' => 'Позволяет редактировать сопоставление брендов 1С с брендами Mall',
            ],
        ];
    }

    /**
     * Регистрация консольных команд
     */
    public function register()
    {
        $this->registerConsoleCommand('mallimport1c.run', \Dmdev\MallImport1c\Console\ImportRunCommand::class);
    }
}