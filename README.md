# ViewStatsDash

Typecho 阅读量看板插件：展示总阅读量、每日总量折线图以及阅读量明细表，支持按阅读量或发布时间排序。

## 安装

1. 将本项目放入 Typecho 的 `usr/plugins/ViewStatsDash` 目录中。
2. 确保 `contents` 表存在 `views` 字段（用于存储文章阅读量）。如果字段名不同，请在插件代码中替换相应列名。
3. 在后台「插件管理」中启用 ViewStatsDash，激活时会自动创建 `viewstats_daily` 快照表并注册管理面板。

## 使用

- 启用后在后台左侧菜单的「阅读量看板」中查看：
  - 总阅读量与文章总数概览
  - 每日总阅读量折线图与每日新增柱状图
  - 文章阅读量明细，支持点击列头或使用排序按钮调整顺序
- 插件会在首次加载后台看板时尝试补建 `viewstats_daily` 表并写入当日快照。

## 定时记录（可选）

如需自动记录每日快照，可把项目中的 `cron.php` 放到服务器可执行位置，并在容器或主机的计划任务中执行：

```bash
php /path/to/usr/plugins/ViewStatsDash/cron.php
```

该脚本会校验 `views` 字段、写入/更新当天的总阅读量快照，并限制仅本机访问 HTTP 接口。
