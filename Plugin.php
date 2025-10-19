<?php
namespace TypechoPlugin\ViewStatsDash;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

/**
 * 阅读量看板：总览卡片 + 每日总量折线图 + 明细表（可按阅读量/发布时间排序）
 *
 * @package ViewStatsDash
 * @author Maemo
 * @version 1.0.0
 * @link https://www.maemo.cc/
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Plugin implements PluginInterface
{
    const TBL = 'viewstats_daily'; // 实际表名会加站点前缀

    public static function activate()
    {
        // 注册后台面板（管理员可见）
        \Helper::addPanel(1, 'ViewStatsDash/manage.php', '阅读量看板', '查看阅读量明细与趋势', 'administrator');

        // 创建每日快照表（day -> total_views）
        $db     = \Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table  = $prefix . self::TBL;

        $adapter = $db->getAdapterName();
        if ($adapter === 'Pdo_Mysql' || $adapter === 'Mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
                        `day` DATE NOT NULL PRIMARY KEY,
                        `total_views` INT UNSIGNED NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $db->query($sql, \Typecho_Db::WRITE);
        } else {
            throw new \Typecho_Plugin_Exception('当前数据库适配器不支持自动建表，请手动创建：' .
                "CREATE TABLE {$table} (`day` DATE PRIMARY KEY, `total_views` INT UNSIGNED NOT NULL DEFAULT 0)");
        }

        return _t('ViewStatsDash 已启用，并尝试创建每日快照表。');
    }

    public static function deactivate()
    {
        \Helper::removePanel(1, 'ViewStatsDash/manage.php');
    }

    public static function config(Form $form) {}
    public static function personalConfig(Form $form) {}
}
