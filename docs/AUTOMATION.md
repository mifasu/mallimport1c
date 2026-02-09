# 🤖 АВТОМАТИЗАЦИЯ ИМПОРТА 1С → OFFLINE.MALL

## 📋 Обзор

Начиная с версии **1.0.8**, плагин полностью готов к автоматизации:
- Атомарное обновление файлов через `current/` директорию
- Консольная команда для батч-обработки
- Защита от параллельных запусков
- Подходит для запуска из cron каждые 1-5 минут

---

## 🔄 Схема работы автоматизации

### 1. Приём файлов от 1С

**Эндпоинт:** `/1c-debug-exchange`

При получении файлов от 1С:
1. Файлы сохраняются в архив: `storage/app/1c_debug/{timestamp}__{random}__{filename}`
2. **Одновременно** создаются/обновляются "актуальные" файлы:
   - `import0_1.xml` → `storage/app/1c_debug/current/import.xml`
   - `offers0_1.xml` → `storage/app/1c_debug/current/offers.xml`

**Механизм атомарности:**
```php
// Запись через временный файл + rename (атомарная операция ОС)
copy($source, $target . '.tmp');
rename($target . '.tmp', $target);
```

### 2. Обработка через artisan-команду

**Команда:** `php artisan mallimport1c:run`

**Алгоритм:**
```
1. Попытка взять lock (TTL: 15 минут)
   ├─ Если lock занят → выход (другой процесс работает)
   └─ Если lock получен → продолжить

2. Проверка существования файлов current/import.xml и current/offers.xml
   └─ Если нет → выход (нечего импортировать)

3. Вычисление currentStamp = max(filemtime(import.xml), filemtime(offers.xml))

4. FileProcessor->processFiles()
   ├─ Сравнивает currentStamp с file_last_modified в БД
   ├─ Если совпадает → skip (данные актуальны)
   └─ Если не совпадает → парсинг XML + заполнение temp таблицы

5. Проверка pending записей для currentStamp
   └─ Если pending = 0 → выход (нечего обрабатывать)

6. MallSyncService->processBatch(N, currentStamp)
   └─ Обработка N записей со статусом pending для данного батча

7. Вывод статистики и освобождение lock
```

---

## ⚙️ Настройка автоматизации

### Шаг 1: Проверка конфигурации

Файл: `plugins/dmdev/mallimport1c/config/config.php`

```php
'import_path' => storage_path('app/1c_debug/current/'),
'import_file' => 'import.xml',
'offers_file' => 'offers.xml',
```

✅ **Эти настройки уже применены** в версии 1.0.8+

### Шаг 2: Настройка 1С

В настройках обмена 1С укажите:
- **URL:** `https://your-domain.com/1c-debug-exchange`
- **Логин/пароль:** из `.env` (см. секцию "Безопасность")

### Шаг 3: Настройка cron

Добавьте в crontab (для пользователя, под которым работает сайт):

```bash
# Запуск импорта каждые 3 минуты (250 товаров за раз)
*/3 * * * * cd /path/to/site && php artisan mallimport1c:run >> /dev/null 2>&1

# Или с логированием вывода:
*/3 * * * * cd /path/to/site && php artisan mallimport1c:run >> storage/logs/mallimport1c_cron.log 2>&1
```

**Рекомендации по частоте:**
- **Малый каталог (< 1000 товаров):** каждые 5 минут, batch=500
- **Средний каталог (1000-10000):** каждые 3 минуты, batch=250
- **Большой каталог (> 10000):** каждые 1 минуту, batch=100

### Шаг 4: Изменение размера батча (опционально)

```bash
# По умолчанию: 250 товаров
php artisan mallimport1c:run

# Кастомный размер батча:
php artisan mallimport1c:run --batch=500
```

---

## 🔐 Безопасность

### HTTP Basic Auth для эндпоинта

Добавьте в `.env`:

```env
MALL_IMPORT_1C_DEBUG_ENABLED=true
MALL_IMPORT_1C_DEBUG_USER=your_login
MALL_IMPORT_1C_DEBUG_PASS=your_password
```

⚠️ **Если логин/пароль не заданы** — эндпоинт открыт без авторизации!

### Защита от параллельных запусков

Команда использует **Cache lock** с TTL 15 минут:
- Если команда уже выполняется → новый запуск выйдет без ошибки
- Lock автоматически освобождается после завершения или по истечению TTL

---

## 📊 Мониторинг

### Логи обмена с 1С

Файл: `storage/logs/1c_exchange.log`

Содержит детальную информацию о каждом запросе от 1С:
- Timestamp, IP, метод, URL
- Заголовки и параметры
- Информация о загруженных файлах
- Статус обновления current-файлов

### Логи команды

При запуске вручную:
```bash
php artisan mallimport1c:run
```

Вывод:
```
🔒 Lock получен. Начало обработки...
📁 Файлы найдены:
   Import: 2026-01-10 10:30:00
   Offers: 2026-01-10 10:30:05
   Current stamp: 2026-01-10 10:30:05
🔄 Проверка актуальности данных...
✅ Данные актуальны (или обновлены).
📊 Найдено записей для обработки: 1250
🔧 Размер батча: 250
⚙️  Обработка батча...
✅ Батч обработан за 12.34 сек.
   Обработано успешно: 248
   ⚠️  С ошибками: 2

📈 Статистика текущего батча (stamp: 2026-01-10 10:30:05):
   Всего обработано: 248
   Всего ошибок: 2
   Осталось pending: 1000

💡 Есть ещё записи для обработки. Запустите команду снова или дождитесь следующего запуска cron.
🔓 Lock освобождён.
```

### Проверка статуса через БД

```sql
-- Количество записей по статусам
SELECT status, COUNT(*) as count 
FROM dmdev_mallimport1c_temp_import 
GROUP BY status;

-- Статистика для конкретного батча
SELECT status, COUNT(*) as count 
FROM dmdev_mallimport1c_temp_import 
WHERE file_last_modified = '2026-01-10 10:30:05'
GROUP BY status;

-- Последние ошибки
SELECT product_id, name, error_message, updated_at
FROM dmdev_mallimport1c_temp_import
WHERE status = 'error'
ORDER BY updated_at DESC
LIMIT 10;
```

---

## 🚀 Типичные сценарии

### Сценарий 1: Первый запуск

```bash
# 1. Проверяем наличие файлов
ls -lh storage/app/1c_debug/current/

# 2. Запускаем импорт вручную
php artisan mallimport1c:run

# 3. Если всё ОК - настраиваем cron
crontab -e
# Добавляем строку: */3 * * * * cd /path/to/site && php artisan mallimport1c:run
```

### Сценарий 2: Массовый импорт большого каталога

```bash
# Запускаем несколько раз подряд (с увеличенным батчем)
php artisan mallimport1c:run --batch=500
php artisan mallimport1c:run --batch=500
php artisan mallimport1c:run --batch=500

# Или запускаем в цикле до полной обработки:
while true; do
    php artisan mallimport1c:run --batch=500 || break
    sleep 5
done
```

### Сценарий 3: Диагностика проблем

```bash
# Проверяем логи 1С
tail -f storage/logs/1c_exchange.log

# Запускаем команду с выводом
php artisan mallimport1c:run

# Проверяем ошибки в БД
php artisan tinker
>>> DB::table('dmdev_mallimport1c_temp_import')->where('status', 'error')->count()
>>> DB::table('dmdev_mallimport1c_temp_import')->where('status', 'error')->first()
```

---

## 🔧 Тонкая настройка

### Изменение TTL lock

Файл: `plugins/dmdev/mallimport1c/console/ImportRunCommand.php`

```php
const LOCK_TTL = 900; // 15 минут (в секундах)
```

Увеличьте, если обработка одного батча занимает > 15 минут.

### Изменение лимита batch

```php
// Валидация batch size (по умолчанию: 1-10000)
if ($batchSize < 1 || $batchSize > 10000) {
    // ...
}
```

### Логирование ошибок

Команда автоматически логирует ошибки в основной лог Laravel:
- Ошибки FileProcessor
- Ошибки MallSyncService

Файл: `storage/logs/laravel.log`

---

## ⚠️ Известные ограничения

1. **Lock через Cache:**
   - Требует корректно настроенный драйвер кеша (file/redis/memcached)
   - При проблемах с кешем lock может не работать

2. **Консистентность батчей:**
   - Записи обрабатываются только для одного `file_last_modified`
   - Если 1С отправит новые файлы во время обработки старых — они попадут в отдельный батч

3. **Размер файлов:**
   - Для ОЧЕНЬ больших XML (> 100MB) может потребоваться:
     - Увеличение `memory_limit` в PHP
     - Увеличение `max_execution_time`

---

## 📈 Производительность

### Типичные показатели

| Размер каталога | Batch size | Время обработки батча | Частота cron |
|----------------|------------|----------------------|--------------|
| 500 товаров    | 250        | 5-10 сек             | 5 минут      |
| 2000 товаров   | 250        | 10-20 сек            | 3 минуты     |
| 10000 товаров  | 500        | 30-60 сек            | 1-2 минуты   |

**Рекомендация:** Выберите batch size так, чтобы обработка занимала **не более 1-2 минут**.

### Оптимизация

1. **Кеширование:** Убедитесь, что в `config/cache.php` используется `redis` или `memcached`
2. **Индексы БД:** Проверьте наличие индексов на `status` и `file_last_modified`
3. **PHP opcache:** Включите opcache для ускорения парсинга PHP

---

## 🆘 Troubleshooting

### Проблема: Команда не запускается

**Решение:**
```bash
# Проверяем регистрацию команды
php artisan list | grep mallimport1c

# Если не найдена - очищаем кеш
php artisan cache:clear
php artisan config:clear
php artisan optimize:clear
```

### Проблема: Lock не освобождается

**Решение:**
```bash
# Ручное освобождение lock
php artisan tinker
>>> Cache::forget('mallimport1c:run:lock')
```

### Проблема: Файлы current не обновляются

**Проверка:**
```bash
# Права на директорию
ls -ld storage/app/1c_debug/current/
# Должно быть: drwxr-xr-x (755) с владельцем веб-сервера

# Логи обмена
tail -20 storage/logs/1c_exchange.log | grep "Current file updated"
```

**Решение:**
```bash
# Создаём директорию вручную
mkdir -p storage/app/1c_debug/current
chmod 755 storage/app/1c_debug/current
chown www-data:www-data storage/app/1c_debug/current
```

### Проблема: Pending записи не обрабатываются

**Диагностика:**
```bash
php artisan tinker
>>> DB::table('dmdev_mallimport1c_temp_import')->where('status', 'pending')->count()
>>> DB::table('dmdev_mallimport1c_temp_import')->where('status', 'pending')->first()->file_last_modified
```

**Возможные причины:**
- Timestamp не совпадает с текущими файлами
- Записи относятся к старому батчу

**Решение:** Запустите команду вручную и проверьте вывод.

---

## 📚 Дополнительные материалы

- [README.md](../README.md) - Основная документация плагина
- [PLUGIN_ALGORITHMS.md](PLUGIN_ALGORITHMS.md) - Детальные алгоритмы работы
- [1C_DEBUG_EXCHANGE.md](1C_DEBUG_EXCHANGE.md) - Документация по отладочному эндпоинту

---

## 🎯 Итоговый чеклист

### Для запуска автоматизации:

- [ ] Настроен эндпоинт `/1c-debug-exchange` в 1С
- [ ] Добавлены credentials в `.env` (или открыт без авторизации)
- [ ] Проверена работа команды `php artisan mallimport1c:run` вручную
- [ ] Настроен cron для автоматического запуска
- [ ] Настроен мониторинг логов `1c_exchange.log`
- [ ] Проверена корректность обработки батчей

### Для мониторинга:

- [ ] Настроен мониторинг `storage/logs/1c_exchange.log`
- [ ] Настроен мониторинг pending записей в БД
- [ ] Настроены алерты на ошибки (опционально)

---

**📅 Дата последнего обновления:** 10 января 2026 г.  
**🏆 Версия плагина:** 1.0.8+

---

_Документ подготовлен для полностью автоматизированного импорта 1С → OFFLINE.Mall_
