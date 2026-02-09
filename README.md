# Mall Import 1C v1.0.8

Плагин для OctoberCMS, который импортирует товары из 1С в OFFLINE Mall.

## Новое в версии 1.0.8: АВТОМАТИЗАЦИЯ

- ✅ **Атомарное обновление файлов** через `current/` директорию
- ✅ **Artisan-команда** для автоматического импорта батчами
- ✅ **Защита от параллельных запусков** (Cache lock)
- ✅ **Готово для cron:** запуск каждые 1-5 минут
- 📚 [Полное руководство по автоматизации](docs/AUTOMATION.md)

## Возможности

- Импорт товаров и предложений из XML (import.xml, offers.xml)
- **🆕 Автоматизация:** Консольная команда + эндпоинт для 1С
- Поддержка справочников брендов и категорий
- Иерархический маппинг категорий
- Маппинг брендов с возможностью ручной корректировки
- Импорт поля «Расширенное описание» с форматированием абзацев
- Робастный парсинг XML (DOMDocument + LIBXML_RECOVER) с логированием восстановленных файлов
- Временная таблица для поэтапной обработки
- Пакетная обработка больших каталогов
- Автоматическое создание и обновление товаров и вариантов
- Управление ценами и остатками


## Системные требования

- October CMS 3.x, October CMS 4.x
- PHP 8.1+
- Плагин OFFLINE.Mall
- MySQL/MariaDB

## Установка

1. Клонируйте репозиторий в папку плагинов:
```bash
cd plugins/dmdev/
git clone https://github.com/mifasu/mallimport1c.git mallimport1c
```

2. Выполните миграции:
```bash
php artisan october:migrate
```

3. Настройте конфигурацию в `config/config.php`

## Структура плагина

### Основные компоненты

- **FileProcessor** - парсинг XML файлов и объединение данных
- **MallSyncService** - синхронизация с базой Mall
- **TempImportService** - управление временной таблицей
- **ProductDataTransformer** - трансформация данных в формат Mall
- **BrandMapping** - модель для сопоставления брендов 1С и Mall
- **BrandMappings Controller** - административная панель для управления брендами

### Директории

```
plugins/dmdev/mallimport1c/
├── config/
│   └── config.php          # Конфигурация плагина
├── console/                # 🆕 Консольные команды
│   └── ImportRunCommand.php
├── controllers/
│   ├── Test.php            # Контроллер для тестирования
│   └── BrandMappings.php   # Административная панель брендов
├── http/
│   └── controllers/
│       └── DebugExchangeController.php  # Эндпоинт для 1С
├── models/
│   ├── TempImport.php      # Модель временной таблицы
│   └── BrandMapping.php    # Модель маппинга брендов
├── services/
│   ├── FileProcessor.php
│   ├── MallSyncService.php
│   ├── TempImportService.php
│   └── ProductDataTransformer.php
├── updates/
│   ├── version.yaml        # Версии и миграции
│   ├── create_temp_import_table.php
│   ├── add_brand_category_fields_to_temp_import.php
│   └── create_brand_mapping_table.php
└── Plugin.php              # Регистрация плагина
```

## Конфигурация

Основные настройки в `config/config.php`:

```php
return [
    // 🆕 Версия 1.0.8: Используются актуальные файлы из current/
    'import_path' => storage_path('app/1c_debug/current/'),
    'import_file' => 'import.xml',
    'offers_file' => 'offers.xml',
    
    'brand_property_id' => '77612c6b-7165-11f0-85e3-04421a2fb3ab',
    'website_category_property_id' => '84936ea1-5e95-11f0-85e0-04421a2fb3ab',
];
```

> **Примечание:** Файлы автоматически обновляются при получении от 1С через эндпоинт `/1c-debug-exchange`

## Использование

### 🤖 Автоматический импорт (рекомендуется)

**1. Настройте 1С для отправки на эндпоинт:**
```
URL: https://your-domain.com/1c-debug-exchange
```

**2. Запустите команду импорта:**
```bash
# Разовый запуск
php artisan mallimport1c:run

# С кастомным размером батча
php artisan mallimport1c:run --batch=500
```

**3. Настройте cron для автоматизации:**
```bash
# Запуск каждые 3 минуты
*/3 * * * * cd /path/to/site && php artisan mallimport1c:run
```

📚 **Подробное руководство:** [docs/AUTOMATION.md](docs/AUTOMATION.md)

---

### Ручной импорт

Для тестирования импорта перейдите в админ-панель:
`/backend/dmdev/mallimport1c/test`

### Программный вызов

```php
use Dmdev\MallImport1c\Services\FileProcessor;
use Dmdev\MallImport1c\Services\MallSyncService;

$fileProcessor = new FileProcessor();
$mallSyncService = new MallSyncService();

// Обработка файлов
$fileProcessor->processFiles();

// Синхронизация порциями
$results = $mallSyncService->processBatch(100);
```

## Структура данных

### Поддерживаемые поля товаров

- ID товара
- Название
- Артикул
- Описание
- Категория/группа
- Бренд (из справочника)
- Категория для сайта (из справочника)
- Предложения с ценами и остатками
- Варианты с характеристиками

### Справочники

Плагин автоматически извлекает и использует справочники:

1. **Бренды** - "Бренд Номенклатуры для сайта"
2. **Категории для сайта** - "Группа Номенклатуры для сайта"

## Техническая информация

### Временная таблица

Структура таблицы `dmdev_mallimport1c_temp_import`:

- `id` - первичный ключ
- `product_id` - ID товара из 1С
- `name` - название товара
- `sku` - артикул
- `category_id` - ID основной категории
- `brand_id`, `brand_name` - бренд
- `website_category_id`, `website_category_name` - категория для сайта
- `description` - описание
- `data` - полные данные в JSON
- `status` - статус обработки
- `error_message` - сообщение об ошибке
- `file_last_modified` - дата изменения файлов

### Пакетная обработка

Плагин поддерживает обработку больших каталогов порциями для предотвращения превышения лимитов времени выполнения и памяти.

## Разработка

### История версий

- **v1.0.1** - Базовая версия плагина
- **v1.0.2** - Добавлена временная таблица
- **v1.0.3** - Поддержка справочников брендов и категорий
- **v1.0.4** - Иерархический маппинг категорий
- **v1.0.5** - Система маппинга брендов и административная панель
- **v1.0.6** - Добавлен импорт поля «Расширенное описание» из XML, Описание теперь форматируется с абзацами и переносами строк
- **v1.0.7** - Робастный DOM-парсинг XML с восстановлением, записываются предупреждения о файлах с кривыми тегами
- **v1.0.8** - Поддержка artisan

### Автор

- **Denis Mishin** mifasu@gmail.com
- GitHub: https://github.com/mifasu/mallimport1c

## Административная панель брендов

### Доступ
- **URL**: `/adm/dmdev/mallimport1c/brandmappings`
- **Меню**: `Импорт 1С → Маппинг брендов`

### Функции
- Просмотр всех сопоставлений брендов
- Создание и редактирование маппингов
- Dropdown выбор брендов Mall с поиском
- Массовые операции: автопоиск и сброс
- Поиск и фильтрация записей

## Лицензия

MIT License

## 📚 Документация

Полная документация плагина находится в папке [`docs/`](docs/):

- **[Алгоритмы работы](docs/PLUGIN_ALGORITHMS.md)** - детальное описание логики импорта
- **[Административная панель брендов](docs/BRAND_ADMIN_PANEL.md)** - руководство по веб-интерфейсу
- **[Сводка версии 1.0.5](docs/VERSION_1.0.5_SUMMARY.md)** - обзор возможностей
- **[Навигация по документации](docs/README.md)** - полный список документов
