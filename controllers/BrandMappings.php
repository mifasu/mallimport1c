<?php namespace Dmdev\MallImport1c\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Dmdev\MallImport1c\Models\BrandMapping;
use OFFLINE\Mall\Models\Brand;
use Flash;

class BrandMappings extends Controller
{
    public $implement = [
        \Backend\Behaviors\ListController::class,
        \Backend\Behaviors\FormController::class
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = [
        'dmdev.mallimport1c.access_brand_mappings'
    ];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Dmdev.MallImport1c', 'mallimport1c', 'brandmappings');
    }

    /**
     * Определяет URL для создания новой записи
     */
    public function listInjectRowClass($record, $definition = null)
    {
        return '';
    }

    /**
     * Действие для автоматического поиска брендов Mall по названию
     */
    public function onAutoMap()
    {
        $selectedIds = post('checked');
        
        if (!$selectedIds) {
            Flash::error('Выберите записи для автопоиска');
            return $this->listRefresh();
        }

        $mappings = BrandMapping::whereIn('id', $selectedIds)->get();
        $updated = 0;

        foreach ($mappings as $mapping) {
            if ($mapping->mall_brand_id) {
                continue; // Пропускаем уже сопоставленные
            }

            // Ищем бренд Mall по названию
            $mallBrand = Brand::where('name', 'LIKE', '%' . $mapping->external_name . '%')
                ->first();

            if ($mallBrand) {
                $mapping->mall_brand_id = $mallBrand->id;
                $mapping->auto_mapped = true;
                $mapping->notes = 'Автоматически сопоставлен ' . date('Y-m-d H:i:s');
                $mapping->save();
                $updated++;
            }
        }

        Flash::success("Автоматически сопоставлено: {$updated} брендов");
        return $this->listRefresh();
    }

    /**
     * Действие для сброса маппинга
     */
    public function onResetMapping()
    {
        $selectedIds = post('checked');
        
        if (!$selectedIds) {
            Flash::error('Выберите записи для сброса');
            return $this->listRefresh();
        }

        BrandMapping::whereIn('id', $selectedIds)->update([
            'mall_brand_id' => null,
            'auto_mapped' => false,
            'notes' => 'Сброшено ' . date('Y-m-d H:i:s')
        ]);

        Flash::success('Маппинг сброшен для выбранных записей');
        return $this->listRefresh();
    }
}
