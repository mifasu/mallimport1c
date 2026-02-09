<?php
/**
 * Конфигурация плагина Mall Import 1C
 * 
 * Файл: plugins/dmdev/mallimport1c/config/config.php
 * 
 * Настройки:
 * - Пути к файлам импорта
 * - ID справочников в 1С
 * - Настройки отладочного эндпоинта для обмена с 1С
 * 
 * ENV-переменные для отладочного обмена:
 * - MALL_IMPORT_1C_DEBUG_USER - логин для Basic Auth (опционально)
 * - MALL_IMPORT_1C_DEBUG_PASS - пароль для Basic Auth (опционально)
 * 
 * Если логин/пароль не заданы - эндпоинт /1c-debug-exchange открыт без авторизации
 */

return [
    // Пути к файлам импорта
    // ВАЖНО: Используется директория current/ для атомарных обновлений из 1С
    // DebugExchangeController автоматически обновляет эти файлы при получении от 1С
    'import_path' => storage_path('app/1c_debug/current/'), // Путь к актуальным файлам импорта
    'import_file' => 'import.xml', // Актуальный файл каталога
    'offers_file' => 'offers.xml', // Актуальный файл предложений
    
    // Основные настройки
    'default_currency' => 'RUB', // Валюта по умолчанию
    'log_imports' => true, // Включить логирование импорта
    
    // ID справочников свойств в 1С
    'brand_property_id' => '77612c6b-7165-11f0-85e3-04421a2fb3ab', // Бренд Номенклатуры для сайта
    'website_category_property_id' => '84936ea1-5e95-11f0-85e0-04421a2fb3ab', // Группа Номенклатуры для сайта
    
    // Настройки категоризации
    'default_category_id' => 77, // ID категории "Без категории" по умолчанию
    
    /*
    |--------------------------------------------------------------------------
    | Настройки отладочного обмена с 1С
    |--------------------------------------------------------------------------
    |
    | debug_exchange_enabled - включить/выключить эндпоинт /1c-debug-exchange
    | debug_exchange_user - логин для HTTP Basic Auth (null = без авторизации)
    | debug_exchange_pass - пароль для HTTP Basic Auth (null = без авторизации)
    |
    | Логи сохраняются в: storage/logs/1c_exchange.log
    | Файлы сохраняются в: storage/app/1c_debug/
    |
    */
    'debug_exchange_enabled' => env('MALL_IMPORT_1C_DEBUG_ENABLED', null),
    'debug_exchange_user' => env('MALL_IMPORT_1C_DEBUG_USER', null),
    'debug_exchange_pass' => env('MALL_IMPORT_1C_DEBUG_PASS', null),
];