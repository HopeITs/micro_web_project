<?php

/* Renders m1-schema-review.html (Arabic review page) from unified-schema-v1.json. */

$s = json_decode(file_get_contents(__DIR__.'/unified-schema-v1.json'), true);
$logo = base64_encode(file_get_contents(__DIR__.'/../../../public/assets/brand/hope-center.png'));
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$scopeLabel = ['city' => 'بيانات مدينة', 'lookup' => 'قائمة موحدة', 'city_lookup' => 'قائمة لكل مدينة', 'global' => 'نظام', 'existing' => 'موجود حالياً'];
$txLabel = ['trim' => 'تنظيف', 'bool01' => 'نعم/لا', 'date' => 'تاريخ', 'lookup' => 'قائمة', 'project' => 'مشروع', 'person' => 'شخص', 'account' => 'حساب', 'money' => 'مبلغ', 'sex' => 'جنس', 'stage' => 'مرحلة', 'file' => 'ملف', 'currency' => 'عملة'];

ob_start();
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>مخطط القاعدة الموحدة</title>
<style>
:root{--brand:#BE1F24;--brand-soft:#fbeaea;--ink:#2b2b2d;--muted:#6d6e71;--line:#e3e3e6;--bg:#f5f5f6;--card:#fff;--ok:#1f7a3f;--ok-soft:#e6f4ea;--warn:#9a6200;--warn-soft:#fff4dd;--new:#1d4f91;--new-soft:#e7eefb}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--ink:#ececee;--muted:#a3a4a8;--line:#3a3a3e;--bg:#161618;--card:#202023;--brand-soft:#3a1d1e;--ok-soft:#173222;--warn-soft:#3a2d12;--new-soft:#1a2740}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.7 "Somar Sans",system-ui,sans-serif}
header{background:var(--brand);color:#fff;padding:24px 16px}
header .wrap{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
header img{height:52px;background:#fff;border-radius:10px;padding:6px}
h1{margin:0;font-size:24px}header p{margin:4px 0 0;opacity:.9}
.wrap{max-width:1150px;margin:0 auto;padding:0 16px}
main .wrap{padding-block:24px}
section{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:20px}
h2{margin:0 0 12px;font-size:20px;color:var(--brand)}h3{font-size:16px;margin:16px 0 8px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.kpi{border:1px solid var(--line);border-radius:10px;padding:12px;text-align:center}
.kpi b{display:block;font-size:26px;color:var(--brand)}.kpi span{color:var(--muted);font-size:14px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
.card{border:1px solid var(--line);border-radius:10px;padding:14px}
.card h3{margin-top:0}
.example{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
.pill{border-radius:8px;padding:4px 10px;background:var(--bg);border:1px solid var(--line);font-family:ui-monospace,Consolas,monospace;direction:ltr}
.pill.brand{background:var(--brand);color:#fff;border-color:var(--brand)}
code{font-family:ui-monospace,Consolas,monospace;font-size:13px;direction:ltr;unicode-bidi:embed}
.table{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th,td{border-bottom:1px solid var(--line);padding:6px 8px;text-align:start;vertical-align:top}
th{background:var(--bg);font-weight:500;white-space:nowrap}
.tag{display:inline-block;border-radius:999px;padding:0 9px;font-size:12.5px;white-space:nowrap}
.t-city{background:var(--new-soft);color:var(--new)}.t-lookup,.t-city_lookup{background:var(--ok-soft);color:var(--ok)}.t-global{background:var(--warn-soft);color:var(--warn)}.t-existing{background:var(--brand-soft);color:var(--brand)}
details{border:1px solid var(--line);border-radius:10px;margin:8px 0;background:var(--card)}
summary{cursor:pointer;padding:10px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;list-style:none}
summary::-webkit-details-marker{display:none}
summary::before{content:"◂";color:var(--muted);transition:transform .15s}
details[open] summary::before{transform:rotate(-90deg)}
summary .name{font-weight:700;font-family:ui-monospace,Consolas,monospace;direction:ltr}
summary .src{color:var(--muted);font-size:13px}
details .body{padding:0 14px 12px}
.note{background:var(--warn-soft);border-inline-start:4px solid var(--warn);padding:8px 12px;border-radius:8px;margin:8px 0;font-size:14px}
.std{color:var(--muted)}
.filter{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.filter input{flex:1;min-width:200px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font:inherit;background:var(--card);color:var(--ink)}
.filter button{padding:10px 14px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);font:inherit;cursor:pointer;min-height:44px}
.approve{border:2px solid var(--brand);background:var(--brand-soft)}
ul{margin:6px 0;padding-inline-start:22px}
.sha{font-family:ui-monospace,Consolas,monospace;direction:ltr;word-break:break-all;font-size:13px}
</style>
</head>
<body>
<header><div class="wrap"><img src="data:image/png;base64,<?= $logo ?>" alt="مركز الرجاء"><div><h1>M1 — مخطط القاعدة الموحدة (للاعتماد)</h1><p>قاعدة واحدة لكل المدن · <?= $e($s['meta']['version']) ?> · 19 تشرين الثاني 2026</p></div></div></header>
<main><div class="wrap">

<section>
<h2>الخلاصة</h2>
<div class="kpis">
<div class="kpi"><b><?= $s['counts']['tables'] ?></b><span>جدولاً في القاعدة الجديدة</span></div>
<div class="kpi"><b><?= ($s['counts']['by_scope']['lookup'] ?? 0) + ($s['counts']['by_scope']['city_lookup'] ?? 0) ?></b><span>قائمة (Lookup)</span></div>
<div class="kpi"><b><?= $s['counts']['by_scope']['city'] ?? 0 ?></b><span>جدول بيانات</span></div>
<div class="kpi"><b><?= $s['counts']['by_scope']['existing'] ?? 0 ?></b><span>جدول حسابات موجود يُعاد استخدامه</span></div>
<div class="kpi"><b><?= $s['counts']['source_tables'] ?></b><span>جدولاً مصدرياً مغطى</span></div>
<div class="kpi"><b>818 ← <?= $s['counts']['tables'] ?></b><span>عدد الجداول الحالي ← الجديد</span></div>
</div>
<p>كل جدول مصدري من الـ<?= $s['counts']['source_tables'] ?> إما يُنقل إلى جدول هنا أو مذكور في قائمة "لا يُنقل" مع السبب. فُحص <b>532 عموداً مصدرياً</b> آلياً أنها موجودة فعلاً في نسخ المدن.</p>
</section>

<section>
<h2>القرارات الأساسية</h2>
<div class="cards">
<div class="card"><h3>قاعدة واحدة بجداول موحدة</h3>
<ul><li>كل جدول بيانات فيه <code>id</code> جديد و<code>city_id</code> و<code>legacy_id</code> (الرقم الأصلي في برنامج المدينة).</li>
<li>لا جداول منفصلة لكل مدينة. الصلاحيات تحدد المدن التي يراها كل موظف.</li>
<li>الحذف: إخفاء مع سجل (<code>deleted_at</code>)، والحذف النهائي للمدير فقط.</li></ul></div>
<div class="card"><h3>رقم المشروع</h3>
<div class="example"><span class="pill brand">DAM-2354</span><span class="pill">ALP-2354</span><span class="pill">HMS-2354</span></div>
<ul><li>المشاريع القديمة تحتفظ برقمها كما هو. الرقم فريد داخل المدينة فقط.</li>
<li>النموذج يقترح الرقم التالي للمدينة، والموظف يقدر يغيّره.</li>
<li>عند الحفظ: قفل تسلسل المدينة، فحص التكرار، ثم الحجز. إذا كان مكرراً تظهر رسالة مع أول رقم متاح.</li>
<li>البحث يقبل <code>DAM-2354</code> أو <code>2354</code>.</li></ul></div>
<div class="card"><h3>القوائم</h3>
<ul><li>58 قائمة موحدة لكل المدن، تُدمج بالاسم بعد توحيد الإملاء (ي/ى، ة/ه، الألف).</li>
<li>3 قوائم لكل مدينة: الشوارع، الكهنة، أعضاء لجنة checklist.</li>
<li>جدول <code>lookup_legacy_map</code> يربط رقم كل قيمة في كل مدينة بالقيمة الجديدة، لأن نفس الرقم يعني أشياء مختلفة بين المدن (مثلاً 87 من 100 رقم شارع).</li></ul></div>
<div class="card"><h3>الحسابات</h3>
<ul><li>جداول الحسابات والأدوار والإشعارات الحالية في الموقع تبقى كما هي.</li>
<li>مستخدمو البرنامج يصبحون حسابات بعد مراجعة التكرار، وكلمة المرور الحالية تعمل في أول دخول ثم تُحدّث لتشفير أقوى.</li>
<li>كل إشارة لمستخدم في الجداول القديمة تصبح ربطاً بالحساب.</li></ul></div>
</div>
</section>

<section>
<h2>قواعد النقل (M1.6)</h2>
<div class="table"><table><tr><th>القاعدة</th><th>ماذا تفعل</th></tr>
<?php foreach ($s['meta']['transforms'] as $k => $v): ?><tr><td><code><?= $e($k) ?></code></td><td><?= $e($v) ?></td></tr>
<?php endforeach; ?></table></div>
<h3>ما وجدناه في الداتا الحقيقية</h3>
<ul><?php foreach ($s['meta']['source_findings'] as $k => $v): ?><li><b><?= $e($k) ?>:</b> <?= $e($v) ?></li><?php endforeach; ?></ul>
</section>

<section>
<h2>الجداول</h2>
<div class="filter"><input id="q" type="search" placeholder="ابحث عن جدول أو عمود أو جدول مصدري (مثلاً microproject أو national)"><button type="button" id="openAll">فتح الكل</button><button type="button" id="closeAll">إغلاق الكل</button></div>
<?php foreach ($s['groups'] as $gk => $gname):
    $tables = array_filter($s['tables'], fn ($t) => $t['group'] === $gk); ?>
<h3><?= $e($gname) ?> <span class="std">(<?= count($tables) ?>)</span></h3>
<?php foreach ($tables as $name => $t): ?>
<details data-search="<?= $e(strtolower($name.' '.($t['source'] ?? '').' '.implode(' ', array_map(fn ($c) => $c['name'].' '.($c['source'] ?? ''), $t['columns'] ?? [])))) ?>">
<summary><span class="name"><?= $e($name) ?></span><span class="tag t-<?= $e($t['scope']) ?>"><?= $e($scopeLabel[$t['scope']]) ?></span><?php if (!empty($t['source'])): ?><span class="src">من <code><?= $e($t['source']) ?></code></span><?php endif; ?><?php if (!empty($t['columns'])): ?><span class="std"><?= count($t['columns']) ?> عمود</span><?php endif; ?></summary>
<div class="body">
<?php if (!empty($t['note'])): ?><div class="note"><?= $e($t['note']) ?></div><?php endif; ?>
<?php if (!empty($t['unique'])): ?><p class="std">فريد: <?php foreach ($t['unique'] as $u) echo '<code>('.$e(implode(', ', $u)).')</code> '; ?></p><?php endif; ?>
<?php if (!empty($t['columns'])): ?><div class="table"><table><tr><th>العمود</th><th>النوع</th><th>فارغ؟</th><th>يرتبط بـ</th><th>من العمود</th><th>التحويل</th></tr>
<?php foreach ($t['columns'] as $c): $std = in_array($c['name'], ['id', 'city_id', 'legacy_id', 'created_at', 'updated_at', 'deleted_at', 'name_ar', 'name_en', 'sort_order', 'is_active'], true) && empty($c['source']); ?>
<tr<?= $std ? ' class="std"' : '' ?>><td><code><?= $e($c['name']) ?></code></td><td><code><?= $e($c['type']) ?></code></td><td><?= $c['nullable'] ? 'نعم' : '' ?></td><td><?= isset($c['references']) ? '<code>'.$e($c['references']).'</code>' : '' ?></td><td><?= isset($c['source']) ? '<code>'.$e($c['source']).'</code>' : ($c['name'] === 'legacy_id' && !empty($t['legacy_key']) ? '<code>'.$e($t['legacy_key']).'</code>' : '') ?></td><td><?= isset($c['transform']) ? $e($txLabel[$c['transform']] ?? $c['transform']) : '' ?></td></tr>
<?php endforeach; ?></table></div><?php endif; ?>
</div></details>
<?php endforeach; endforeach; ?>
</section>

<section>
<h2>جداول مصدرية لا تُنقل (<?= count($s['not_imported']) ?>)</h2>
<p>تبقى محفوظة في أرشيف النسخ الأصلية مع بصمتها، ولا تدخل قاعدة التشغيل.</p>
<div class="table"><table><tr><th>الجدول</th><th>السبب</th></tr>
<?php foreach ($s['not_imported'] as $k => $v): ?><tr><td><code><?= $e($k) ?></code></td><td><?= $e($v) ?></td></tr><?php endforeach; ?></table></div>
</section>

<section class="approve">
<h2>الاعتماد</h2>
<p>بعد اعتمادك تُكتب migrations المرحلة M2 من هذا المخطط حرفياً، وأي تعديل لاحق يحتاج اعتماداً جديداً. بصمة هذه النسخة:</p>
<p class="sha"><?= $e($s['sha256']) ?></p>
</section>

</div></main>
<script>
(function(){
  var q=document.getElementById('q'), all=[].slice.call(document.querySelectorAll('details'));
  q.addEventListener('input',function(){var v=q.value.trim().toLowerCase();all.forEach(function(d){var hit=!v||d.dataset.search.indexOf(v)>-1;d.style.display=hit?'':'none';if(v&&hit)d.open=true;});});
  document.getElementById('openAll').onclick=function(){all.forEach(function(d){d.open=true;});};
  document.getElementById('closeAll').onclick=function(){all.forEach(function(d){d.open=false;});};
})();
</script>
</body>
</html>
<?php
file_put_contents(__DIR__.'/m1-schema-review.html', ob_get_clean());
echo "written m1-schema-review.html\n";
