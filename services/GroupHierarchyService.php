<?php

namespace Dmdev\MallImport1c\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GroupHierarchyService
{
    /**
     * Кеш иерархии групп
     */
    private static $groupHierarchy = null;

    /**
     * Кеш маппинга группы -> корневая группа
     */
    private static $rootGroupMapping = null;

    /**
     * Получает корневую группу для указанной группы товара
     *
     * @param string $groupId ID группы товара
     * @return string|null ID корневой группы
     */
    public static function getRootGroupId($groupId)
    {
        if (empty($groupId)) {
            return null;
        }

        // Инициализируем кеш если нужно
        if (self::$rootGroupMapping === null) {
            self::loadGroupHierarchy();
        }

        return self::$rootGroupMapping[$groupId] ?? null;
    }

    /**
     * Загружает иерархию групп из XML файла
     */
    private static function loadGroupHierarchy()
    {
        Log::info('GroupHierarchyService: Загрузка иерархии групп');

        // Пытаемся получить из кеша
        $cached = Cache::get('group_hierarchy');
        if ($cached) {
            self::$groupHierarchy = $cached['hierarchy'];
            self::$rootGroupMapping = $cached['root_mapping'];
            Log::info('GroupHierarchyService: Иерархия загружена из кеша');
            return;
        }

        // Загружаем из XML
        $xmlFile = storage_path('app/resources/import/import0_1.xml');
        if (!file_exists($xmlFile)) {
            Log::error('GroupHierarchyService: XML файл не найден', ['file' => $xmlFile]);
            self::$groupHierarchy = [];
            self::$rootGroupMapping = [];
            return;
        }

        $xml = simplexml_load_file($xmlFile);
        if (!$xml) {
            Log::error('GroupHierarchyService: Ошибка загрузки XML файла');
            self::$groupHierarchy = [];
            self::$rootGroupMapping = [];
            return;
        }

        // Парсим иерархию
        self::$groupHierarchy = [];
        self::$rootGroupMapping = [];

        if (isset($xml->Классификатор->Группы->Группа)) {
            self::parseGroupHierarchy($xml->Классификатор->Группы->Группа);
        }

        // Строим маппинг группа -> корневая группа
        foreach (self::$groupHierarchy as $groupId => $groupData) {
            $rootId = self::findRootGroupRecursive($groupId);
            self::$rootGroupMapping[$groupId] = $rootId;
        }

        // Кешируем на 1 час
        Cache::put('group_hierarchy', [
            'hierarchy' => self::$groupHierarchy,
            'root_mapping' => self::$rootGroupMapping
        ], 60 * 60);

        Log::info('GroupHierarchyService: Иерархия загружена из XML', [
            'total_groups' => count(self::$groupHierarchy),
            'root_mappings' => count(self::$rootGroupMapping)
        ]);
    }

    /**
     * Рекурсивно парсит группы из XML
     */
    private static function parseGroupHierarchy($groups, $parentId = null, $level = 0)
    {
        foreach ($groups as $group) {
            $groupId = (string)$group->Ид;
            $groupName = (string)$group->Наименование;

            self::$groupHierarchy[$groupId] = [
                'name' => $groupName,
                'parent_id' => $parentId,
                'level' => $level
            ];

            // Рекурсивно обрабатываем подгруппы
            if (isset($group->Группы->Группа)) {
                self::parseGroupHierarchy($group->Группы->Группа, $groupId, $level + 1);
            }
        }
    }

    /**
     * Рекурсивно находит корневую группу
     */
    private static function findRootGroupRecursive($groupId)
    {
        if (!isset(self::$groupHierarchy[$groupId])) {
            return null;
        }

        $group = self::$groupHierarchy[$groupId];

        // Если нет родителя, это корневая группа
        if (!$group['parent_id']) {
            return $groupId;
        }

        // Рекурсивно ищем корень
        return self::findRootGroupRecursive($group['parent_id']);
    }

    /**
     * Получает информацию о группе
     *
     * @param string $groupId
     * @return array|null
     */
    public static function getGroupInfo($groupId)
    {
        if (self::$groupHierarchy === null) {
            self::loadGroupHierarchy();
        }

        return self::$groupHierarchy[$groupId] ?? null;
    }

    /**
     * Очищает кеш иерархии групп
     */
    public static function clearCache()
    {
        Cache::forget('group_hierarchy');
        self::$groupHierarchy = null;
        self::$rootGroupMapping = null;
        Log::info('GroupHierarchyService: Кеш иерархии групп очищен');
    }

    /**
     * Получает статистику иерархии
     *
     * @return array
     */
    public static function getHierarchyStats()
    {
        if (self::$groupHierarchy === null) {
            self::loadGroupHierarchy();
        }

        $levelStats = [];
        foreach (self::$groupHierarchy as $groupData) {
            $level = $groupData['level'];
            $levelStats[$level] = ($levelStats[$level] ?? 0) + 1;
        }

        return [
            'total_groups' => count(self::$groupHierarchy),
            'level_distribution' => $levelStats,
            'root_mappings' => count(self::$rootGroupMapping)
        ];
    }
}

?>
