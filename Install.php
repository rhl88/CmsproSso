<?php

namespace App\Apps\CmsproSso;

use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Install
{
    public function install(): void
    {
        $this->runMigrations();
        $this->seedDefaultConfigs();
    }

    public function uninstall(): void
    {
        $this->rollbackMigrations();
        $this->removeConfigs();

        Cache::flush();
    }

    public function upgrade(string $fromVersion, string $toVersion): void
    {
        $this->runMigrations();
        $this->seedDefaultConfigs();
    }

    /**
     * 迁移兜底：直接 require 迁移文件并调用 up()，确保表一定被创建
     */
    protected function runMigrations(): void
    {
        $migrationsPath = __DIR__ . '/Migrations';
        $migrationFiles = glob($migrationsPath . '/*.php');

        foreach ($migrationFiles as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);

            if (DB::table('migrations')->where('migration', $migrationName)->exists()) {
                continue;
            }

            $migration = require $file;
            if (method_exists($migration, 'up')) {
                try {
                    $migration->up();

                    DB::table('migrations')->insert([
                        'migration' => $migrationName,
                        'batch' => DB::table('migrations')->max('batch') + 1,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('应用迁移执行失败', [
                        'app' => 'cmspro.sso',
                        'file' => basename($file),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 卸载迁移回滚兜底：直接 require 迁移文件并调用 down()，确保表被清理
     */
    protected function rollbackMigrations(): void
    {
        $migrationsPath = __DIR__ . '/Migrations';
        $migrationFiles = glob($migrationsPath . '/*.php');

        foreach (array_reverse($migrationFiles) as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);

            if (!DB::table('migrations')->where('migration', $migrationName)->exists()) {
                continue;
            }

            $migration = require $file;
            if (method_exists($migration, 'down')) {
                try {
                    $migration->down();

                    DB::table('migrations')->where('migration', $migrationName)->delete();
                } catch (\Throwable $e) {
                    Log::error('应用迁移回滚失败', [
                        'app' => 'cmspro.sso',
                        'file' => basename($file),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 从 manifest.json 同步配置到数据库（三向同步）
     */
    protected function seedDefaultConfigs(): void
    {
        if (!class_exists(ConfigGroup::class) || !class_exists(ConfigItem::class)) {
            return;
        }

        $manifestPath = __DIR__ . '/manifest.json';
        if (!file_exists($manifestPath)) {
            return;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $configGroups = $manifest['config_groups'] ?? [];
        $appId = 'cmspro.sso';

        // 1. 收集 manifest 中定义的所有配置项 code（用于清理残留）
        $manifestCodes = [];

        foreach ($configGroups as $groupDef) {
            $group = ConfigGroup::updateOrCreate(
                ['code' => 'app_' . $appId . '_' . $groupDef['name']],
                [
                    'name' => $groupDef['title'],
                    'app_id' => $appId,
                    'sort' => 0,
                    'status' => 1,
                ]
            );

            foreach ($groupDef['items'] as $index => $item) {
                $fullCode = 'app_' . $appId . '_' . $item['name'];
                $manifestCodes[] = $fullCode;

                $existing = ConfigItem::where('code', $fullCode)->first();

                if ($existing) {
                    // 已有记录 → 仅更新结构信息，不覆盖用户设置的 value
                    $existing->update([
                        'group_id' => $group->id,
                        'name'     => $item['title'],
                        'type'     => $item['type'] ?? 'text',
                        'tips'     => $item['tips'] ?? '',
                        'sort'     => $index,
                        'status'   => 1,
                    ]);
                } else {
                    // 新增配置 → 写入默认值
                    ConfigItem::create([
                        'code'     => $fullCode,
                        'group_id' => $group->id,
                        'name'     => $item['title'],
                        'type'     => $item['type'] ?? 'text',
                        'value'    => $item['value'] ?? '',
                        'tips'     => $item['tips'] ?? '',
                        'sort'     => $index,
                        'status'   => 1,
                    ]);
                }
            }
        }

        // 2. 清理 manifest 中已删除的配置项（防残留）
        ConfigItem::where('code', 'like', 'app_' . $appId . '_%')
            ->whereNotIn('code', $manifestCodes)
            ->delete();

        // 3. 清理 manifest 中已删除的配置组（无子项的组）
        ConfigGroup::where('app_id', $appId)
            ->whereDoesntHave('items')
            ->delete();
    }

    /**
     * 卸载时全量清理配置
     */
    protected function removeConfigs(): void
    {
        if (!class_exists(ConfigGroup::class) || !class_exists(ConfigItem::class)) {
            return;
        }

        $appId = 'cmspro.sso';

        ConfigItem::where('code', 'like', 'app_' . $appId . '_%')->delete();
        ConfigGroup::where('app_id', $appId)->delete();
    }
}
