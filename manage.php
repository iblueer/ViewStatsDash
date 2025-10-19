<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

$options = Helper::options();

include 'header.php';
include 'menu.php';

$db       = Typecho_Db::get();
$prefix   = $db->getPrefix();
$tblDaily = $prefix . \TypechoPlugin\ViewStatsDash\Plugin::TBL; // 例如 typecho_viewstats_daily
$tblPosts = $prefix . 'contents';

// 小工具：把 SQL 报错直接显示在后台，便于定位
function panic($msg) {
    echo '<div class="main"><div class="body container"><div style="border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px">';
    echo '<b>Database Query Error</b><br><pre style="white-space:pre-wrap">'.htmlspecialchars($msg).'</pre>';
    echo '</div></div></div>';
    include 'footer.php';
    exit;
}

try {
    // 0) 兜底：创建快照表（激活时若失败，这里再建一次）
    $createSql = "CREATE TABLE IF NOT EXISTS `{$tblDaily}` (
        `day` DATE NOT NULL PRIMARY KEY,
        `total_views` INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $db->query($createSql, Typecho_Db::WRITE);

    // 0.1) 检查 posts 表是否有 views 列
    $cols = $db->fetchAll($db->query("SHOW COLUMNS FROM `{$tblPosts}`"));
    $hasViews = false;
    foreach ($cols as $c) {
        if (isset($c['Field']) && $c['Field'] === 'views') { $hasViews = true; break; }
    }
    if (!$hasViews) {
        panic("在表 {$tblPosts} 中未找到列 `views`。如你的列名不同（如 viewsNum），请把代码里的 `views` 全部替换为实际列名。");
    }

    // 1) 总阅读量 & 文章数（仅统计 type='post'）
    // 关键修正：改成字符串形式的别名，不用 ['别名'=>'表达式']！
    $sumSelect = $db->select(
                    'SUM(views) AS total_views',
                    'COUNT(1)   AS post_count'
                 )
                 ->from($tblPosts)
                 ->where('type = ?', 'post');
    $sumRow = $db->fetchRow($sumSelect);
    $totalViews = (int)($sumRow['total_views'] ?? 0);
    $postCount  = (int)($sumRow['post_count'] ?? 0);

    // 2) 今日快照（仅当日未记录时插入）
    $today = date('Y-m-d');
    $existsSelect = $db->select('day')->from($tblDaily)->where('day = ?', $today);
    $exists = $db->fetchRow($existsSelect);
    if (!$exists) {
        $insert = $db->insert($tblDaily)->rows(['day' => $today, 'total_views' => $totalViews]);
        $db->query($insert);
    }

    // 3) 拉取全部快照（按天升序）
    $dailySelect = $db->select('day', 'total_views')
                      ->from($tblDaily)
                      ->order('day', Typecho_Db::SORT_ASC);
    $dailyRows = $db->fetchAll($dailySelect);

    // 4) 文章明细（默认按阅读量降序）
    $postSelect = $db->select('cid', 'title', 'views', 'created')
                     ->from($tblPosts)
                     ->where('type = ?', 'post')
                     ->order('views', Typecho_Db::SORT_DESC);
    $postRows = $db->fetchAll($postSelect);

} catch (Typecho_Db_Query_Exception $e) {
    panic($e->getMessage());
} catch (Exception $e) {
    panic($e->getMessage());
}

// === 下面保持原有 UI 渲染 ===
$days = [];
$totals = [];
foreach ($dailyRows as $r) {
    $days[]   = $r['day'];
    $totals[] = (int)$r['total_views'];
}

// 计算每日新增（相邻两天差分）
$increments = [];
$prev = null;
foreach ($totals as $t) {
    if ($prev === null) {
        $increments[] = 0;         // 第一日无前值，记 0（需要也可记 null）
    } else {
        $increments[] = max(0, $t - $prev); // 可保留负数用于发现回滚/重置；若不希望出现负数，改成 max(0, $t - $prev)
    }
    $prev = $t;
}

function dt($ts) { return date('Y-m-d H:i', (int)$ts); }
?>
<style>
.viewstats-cards { display:grid; grid-template-columns: 1fr 2fr; gap:16px; margin-bottom:16px; }
.card { border:1px solid #e5e7eb; border-radius:12px; padding:16px; background:#fff; }
.card h3 { margin:0 0 8px; font-size:16px; color:#111; }
.card .value { font-size:24px; font-weight:700; }
.tools { display:flex; gap:8px; margin:8px 0 12px; }
.button { padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; background:#f9fafb; cursor:pointer; }
.button.active { background:#111827; color:#fff; border-color:#111827; }
.table-wrap { overflow:auto; border:1px solid #e5e7eb; border-radius:12px; }
table { width:100%; border-collapse:collapse; }
th, td { padding:10px 12px; border-bottom:1px solid #f3f4f6; text-align:left; }
th { background:#fafafa; cursor:pointer; user-select:none; position:sticky; top:0; }
.note { color:#6b7280; font-size:12px; }
</style>

<div class="main">
  <div class="body container">
    <div class="viewstats-cards">
      <div class="card">
        <h3>总阅读量</h3>
        <div class="value"><?php echo number_format($totalViews); ?></div>
        <div class="note">共 <?php echo $postCount; ?> 篇文章；今天（<?php echo $today; ?>）已记录快照。</div>
      </div>
      <div class="card">
        <h3>每日阅读量（总量）</h3>
        <div id="views-echart" style="height:260px;"></div>
        <div class="note">说明：为系统每日“总阅读量”快照（非当日新增）。用于观察整体增长趋势。</div>
      </div>
    </div>

    <div class="card">
      <div class="tools">
        <button class="button active" id="sortViews">按阅读量排序</button>
        <button class="button" id="sortCreated">按发布时间排序</button>
        <span class="note">（也可点击列头切换升/降序）</span>
      </div>
      <div class="table-wrap">
        <table id="postsTable">
          <thead>
            <tr>
              <th data-key="cid">CID</th>
              <th data-key="title">标题</th>
              <th data-key="views">阅读量</th>
              <th data-key="created">发布时间</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($postRows as $r): ?>
            <tr>
              <td><?php echo (int)$r['cid']; ?></td>
              <td>
                <a href="<?php echo Typecho_Common::url('/index.php/archives/'.$r['cid'].'.html', $options->siteUrl); ?>" target="_blank" rel="noopener">
                  <?php echo htmlspecialchars($r['title']); ?>
                </a>
              </td>
              <td><?php echo (int)$r['views']; ?></td>
              <td><?php echo dt($r['created']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="note" style="margin-top:8px;">
        想看“每日新增阅读量”可在前端做相邻两点差分，或在后端另建增量表；当前看板记录的是<strong>每日总量</strong>快照（从安装当天起累计）。
      </p>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
<script>
(function () {
  var chart = echarts.init(document.getElementById('views-echart'));
  var option = {
    tooltip: { trigger:'axis' },
    legend: { data: ['总阅读量', '每日新增'] },
    xAxis: { type:'category', data: <?php echo json_encode($days); ?> },
    yAxis: { type:'value' },
    series: [
      {
        name:'总阅读量',
        type:'line',
        smooth:true,
        data: <?php echo json_encode($totals, JSON_NUMERIC_CHECK); ?>
      },
      {
        name:'每日新增',
        type:'bar',
        smooth:true,
        data: <?php echo json_encode($increments, JSON_NUMERIC_CHECK); ?>
      }
    ],
    grid: { left:40, right:24, top:32, bottom:24 }
  };
  chart.setOption(option);

  const table = document.getElementById('postsTable');
  let currentSort = { key:'views', asc:false };

  function getCellText(tr, idx) { return tr.children[idx].innerText || tr.children[idx].textContent; }
  function sortBy(key, asc) {
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const keyIndex = {cid:0, title:1, views:2, created:3}[key];

    rows.sort((a,b)=>{
      let va = getCellText(a, keyIndex), vb = getCellText(b, keyIndex);
      if (key === 'cid' || key === 'views') { va = parseInt(va,10)||0; vb = parseInt(vb,10)||0; }
      if (key === 'created') { va = new Date(va.replace(/-/g,'/')).getTime()||0; vb = new Date(vb.replace(/-/g,'/')).getTime()||0; }
      if (va < vb) return asc ? -1 : 1;
      if (va > vb) return asc ? 1 : -1;
      return 0;
    });
    rows.forEach(r=>tbody.appendChild(r));
    currentSort = {key, asc};
    document.getElementById('sortViews').classList.toggle('active', key==='views');
    document.getElementById('sortCreated').classList.toggle('active', key==='created');
  }

  document.getElementById('sortViews').addEventListener('click', ()=>sortBy('views', false));
  document.getElementById('sortCreated').addEventListener('click', ()=>sortBy('created', false));
  table.tHead.addEventListener('click', function(e){
    const th = e.target.closest('th'); if (!th) return;
    const key = th.getAttribute('data-key'); if (!key) return;
    const asc = (currentSort.key === key) ? !currentSort.asc : false;
    sortBy(key, asc);
  });

  sortBy('views', false);
})();
</script>

<?php include 'footer.php'; ?>
