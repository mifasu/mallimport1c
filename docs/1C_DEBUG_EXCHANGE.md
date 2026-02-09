# Отладочный эндпоинт для обмена с 1С

## 📋 Описание

Реализован эндпоинт `/1c-debug-exchange` для прослушивания и отладки обмена с 1С.

## 🎯 Возможности

- ✅ Принимает **любые** HTTP-методы (GET, POST, PUT, DELETE и т.д.)
- ✅ HTTP Basic Authentication (опционально)
- ✅ Максимально подробное логирование всех запросов
- ✅ Автоматическое сохранение всех загруженных файлов
- ✅ Отдельный лог-файл для отладки
- ✅ Всегда возвращает "success" для продолжения обмена

## 📁 Добавленные файлы

### 1. DebugExchangeController.php
**Путь:** `plugins/dmdev/mallimport1c/http/controllers/DebugExchangeController.php`

Контроллер, который:
- Проверяет Basic Auth (если настроен)
- Собирает всю информацию о запросе
- Сохраняет копии файлов
- Логирует в отдельный канал
- Возвращает ответ "success"

### 2. Plugin.php (обновлен)
Добавлен метод `boot()` с регистрацией роута:
```php
Route::any('/1c-debug-exchange', ...)->middleware('web');
```

### 3. config/config.php (обновлен)
Добавлены настройки:
```php
'debug_exchange_enabled' => env('MALL_IMPORT_1C_DEBUG_ENABLED', true),
'debug_exchange_user' => env('MALL_IMPORT_1C_DEBUG_USER', null),
'debug_exchange_pass' => env('MALL_IMPORT_1C_DEBUG_PASS', null),
```

### 4. config/logging.php (обновлен)
Добавлен канал `1c_exchange`:
```php
'1c_exchange' => [
    'driver' => 'single',
    'path' => storage_path('logs/1c_exchange.log'),
    'level' => 'debug',
],
```

## ⚙️ Настройка

### 1. ENV-переменные (опционально)

Добавьте в `.env` для включения Basic Auth:

```env
# Отладка обмена с 1С
MALL_IMPORT_1C_DEBUG_ENABLED=true
MALL_IMPORT_1C_DEBUG_USER=your_login
MALL_IMPORT_1C_DEBUG_PASS=your_password
```

**Важно:** Если `DEBUG_USER` и `DEBUG_PASS` не заданы (или равны `null`) — эндпоинт открыт **без авторизации**.

### 2. Настройка 1С

В настройках обмена 1С укажите:

**URL:** `https://your-domain.com/1c-debug-exchange`

**Логин/пароль:** указанные в `.env` (или оставьте пустыми, если авторизация отключена)

## 📊 Что логируется

Каждый запрос логируется **одной записью** в `storage/logs/1c_exchange.log` со всеми деталями:

```json
{
    "timestamp": "2025-12-07 10:30:45",
    "ip": "192.168.1.100",
    "method": "POST",
    "url": "https://site.ru/1c-debug-exchange?type=catalog&mode=init",
    "uri": "/1c-debug-exchange?type=catalog&mode=init",
    "query_params": {
        "type": "catalog",
        "mode": "init"
    },
    "headers": {
        "host": ["site.ru"],
        "user-agent": ["1C+Enterprise/8.3"],
        "content-type": ["multipart/form-data"],
        "authorization": ["Basic ***"],
        ...
    },
    "post_params": {
        "filename": "import.xml"
    },
    "files_info": [
        {
            "field_name": "file",
            "original_name": "import.xml",
            "size": 1048576,
            "mime_type": "text/xml"
        }
    ],
    "raw_body": "...",
    "raw_body_length": 1048576,
    "raw_body_truncated": false,
    "saved_files": [
        {
            "field_name": "file",
            "original_name": "import.xml",
            "saved_path": "1c_debug/20251207_103045__a8k3j9d2__import.xml",
            "saved_full_path": "D:/openserver/.../storage/app/1c_debug/...",
            "size": 1048576
        }
    ]
}
```

### Особенности логирования:

- **Raw body:** логируется полностью, если размер ≤ 200 КБ
- **Большие body:** первые 200 КБ + флаг `raw_body_truncated: true`
- **Все заголовки** включая Authorization (но значение не расшифровывается)
- **Все query/post параметры**
- **Подробная информация о файлах**

## 💾 Сохранение файлов

Все загруженные файлы **автоматически сохраняются** в:

**Папка:** `storage/app/1c_debug/`

**Формат имени:** `{timestamp}__{random}__{original_name}`

**Пример:** `20251207_103045__a8k3j9d2__import.xml`

- `20251207_103045` — дата и время (Ymd_His)
- `a8k3j9d2` — случайная строка для уникальности
- `import.xml` — оригинальное имя файла

## 🔒 Безопасность

### Basic Authentication

- Если логин/пароль заданы в `.env` — запрос должен содержать Basic Auth заголовок
- При неверных данных возвращается **HTTP 401 Unauthorized**
- Заголовок ответа: `WWW-Authenticate: Basic realm="1C Debug Exchange"`

### Отключение авторизации

Для отключения Basic Auth:
1. Удалите/закомментируйте переменные в `.env`
2. Или установите `null` в `config/config.php`

## 🧪 Тестирование

### Простой GET-запрос
```bash
curl http://your-domain.com/1c-debug-exchange
```

### POST с Basic Auth
```bash
curl -X POST \
  -u your_login:your_password \
  -d "param1=value1" \
  http://your-domain.com/1c-debug-exchange
```

### Загрузка файла
```bash
curl -X POST \
  -u your_login:your_password \
  -F "file=@/path/to/import.xml" \
  http://your-domain.com/1c-debug-exchange
```

Все запросы **всегда** возвращают:
```
success
```

## 📝 Просмотр логов

```bash
# Последние записи
tail -f storage/logs/1c_exchange.log

# Весь лог
cat storage/logs/1c_exchange.log

# Поиск конкретного IP
grep "192.168.1.100" storage/logs/1c_exchange.log
```

## 🛠️ Возможные проблемы

### Ошибка "Failed to write to 1c_exchange log channel"

**Причина:** Канал `1c_exchange` не настроен в `config/logging.php`

**Решение:** Проверьте, что в `config/logging.php` добавлен канал (см. выше)

### Файлы не сохраняются

**Причина:** Нет прав на запись в `storage/app/`

**Решение:**
```bash
chmod -R 775 storage/app/
```

### 401 Unauthorized при правильном пароле

**Причина:** Несоответствие логина/пароля или проблемы с кодировкой

**Решение:**
1. Проверьте `.env` (нет лишних пробелов)
2. Очистите кеш конфига: `php artisan config:clear`
3. Попробуйте временно отключить авторизацию

## 📌 Дополнительно

- Логи ротируются автоматически при использовании `daily` драйвера (опционально)
- Максимальный размер raw body для логирования: **200 КБ** (константа `MAX_RAW_BODY_LOG_SIZE`)
- Поддерживаются **массивы файлов** в одном запросе
- Сохраняются **все типы файлов** без ограничений

## 🎯 Следующие шаги

После получения и анализа логов/файлов от 1С можно:

1. Изучить структуру запросов от 1С
2. Определить параметры и типы файлов
3. Реализовать полноценный обмен на основе полученных данных
4. Добавить обработку типов запросов (catalog, file, import и т.д.)

---

**Готово к использованию!** 🚀

Просто направьте 1С на `/1c-debug-exchange` и смотрите логи.
