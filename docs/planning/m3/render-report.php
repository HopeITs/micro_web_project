<?php

/* Renders m3-transfer-report.html (Arabic) from the five reconcile-<CITY>.json reports. Counts only, no personal data. */

$cities = ['ALP' => 'حلب', 'DAM' => 'دمشق', 'HMS' => 'حمص', 'LAT' => 'اللاذقية', 'WAD' => 'الوادي'];
$kinds = [
    'required_text_empty' => 'نص مطلوب فارغ في المصدر (حُفظ فارغاً)', 'required_missing' => 'سجل يشير لشيء غير موجود في المصدر (لم يُنقل)',
    'project_chosen' => 'شخص له أكثر من مشروع: اختير المشروع الأقرب تاريخاً (للمراجعة)', 'reference_missing' => 'رابط لقيمة غير موجودة (تُرك فارغاً)',
    'truncated' => 'نص أطول من الحقل (قُص)', 'account_unmapped' => 'مستخدم غير مربوط بحساب', 'name_empty' => 'اسم فارغ في قائمة',
    'date_out_of_range' => 'تاريخ غير صالح (حُفظ فارغاً)', 'sex_unrecognised' => 'قيمة جنس غير مفهومة', 'money_rounded' => 'مبلغ تقرّب لخانتين',
    'duplicate_answer_set' => 'إجابات مكررة لنفس الزيارة (حُفظت الاثنتان)',
];
$logo = base64_encode(file_get_contents(__DIR__.'/../../../public/assets/brand/micro-projects.svg'));
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$reports = [];
$totals = ['rows' => 0, 'equal' => 0, 'explained' => 0, 'unexplained' => 0, 'exceptions' => 0];
foreach ($cities as $code => $name) {
    $r = json_decode(file_get_contents(__DIR__."/reconcile-{$code}.json"), true);
    $rows = array_sum($r['run_counts']['written'] ?? []);
    $reports[$code] = $r + ['rows' => $rows];
    $totals['rows'] += $rows;
    foreach (['equal', 'explained', 'unexplained'] as $k) {
        $totals[$k] += $r['summary'][$k];
    }
    $totals['exceptions'] += array_sum($r['exceptions']);
}
$before = json_decode(file_get_contents(__DIR__.'/before-v12-exceptions.json'), true);
$pick = function (array $r, string $group, string $check) {
    foreach ($r['checks'] as $c) {
        if ($c['group'] === $group && $c['check'] === $check) {
            return $c;
        }
    }

    return null;
};
ob_start(); ?>
<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تقرير نقل البيانات M3</title>
<style>
:root{--brand:#BE1F24;--brand-soft:#fbeaea;--ink:#262628;--muted:#6d6e71;--line:#e4e4e7;--bg:#f2f2f4;--card:#fff;--ok:#1d7a43;--ok-soft:#e5f4ea;--warn:#8f5b00;--warn-soft:#fff3d6}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--ink:#eeeef0;--muted:#a2a3a8;--line:#33343a;--bg:#151619;--card:#1d1e22;--brand-soft:#3b1a1c;--ok-soft:#17301f;--warn-soft:#33270f;--ok:#6cc98f;--warn:#e5b45a}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.7 "Somar Sans",system-ui,sans-serif}
header{background:var(--card);border-bottom:3px solid var(--brand);padding:18px 16px}header .wrap{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
header img{height:40px}h1{margin:0;font-size:22px}header p{margin:2px 0 0;color:var(--muted)}
.wrap{max-width:1100px;margin:0 auto;padding:0 16px}main .wrap{padding-block:24px}
section{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:20px}h2{margin:0 0 12px;font-size:19px;color:var(--brand)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}.kpi{border:1px solid var(--line);border-radius:10px;padding:12px;text-align:center}
.kpi b{display:block;font-size:26px;color:var(--brand);font-variant-numeric:tabular-nums}.kpi span{color:var(--muted);font-size:14px}.kpi.ok b{color:var(--ok)}
.table{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:14px}th,td{border-bottom:1px solid var(--line);padding:8px;text-align:start;white-space:nowrap}
th{background:var(--bg);font-weight:600}td.n{font-variant-numeric:tabular-nums}.ok{color:var(--ok);font-weight:700}.down{color:var(--ok)}
.note{background:var(--warn-soft);border-inline-start:4px solid var(--warn);padding:10px 14px;border-radius:8px;margin:12px 0}
code{direction:ltr;unicode-bidi:embed;font-size:13px}
</style></head><body>
<header><div class="wrap"><img src="data:image/svg+xml;base64,<?= $logo ?>" alt="Micro Projects"><div><h1>M3 — تقرير نقل البيانات الحقيقية</h1><p>المخطط المعتمد v1.2 · <?= date('Y-m-d') ?> · قاعدة معزولة محلية <code>micro_m3_sample</code></p></div></div></header>
<main><div class="wrap">
<section><h2>الخلاصة</h2>
<div class="kpis">
<div class="kpi"><b><?= number_format($totals['rows']) ?></b><span>صف منقول</span></div>
<div class="kpi"><b>10,519</b><span>مشروع في 5 مدن</span></div>
<div class="kpi"><b><?= $totals['equal'] + $totals['explained'] ?></b><span>فحص مطابقة</span></div>
<div class="kpi ok"><b><?= $totals['unexplained'] ?></b><span>فرق غير مفسّر</span></div>
<div class="kpi"><b><?= number_format($totals['exceptions']) ?></b><span>قيمة مسجلة للمراجعة</span></div>
</div>
<p>أداة المطابقة تعيد قراءة قاعدة المدينة الأصلية وتقارن: عدد الصفوف لكل جدول، القوائم، مجموعة أرقام المشاريع، المبالغ لكل عملة، الرقم التالي، وكل رابط بين الجداول. كل فرق إما مطابق تماماً أو مفسَّر بقيمة مسجلة في سجل الاستثناءات.</p>
</section>

<section><h2>حسب المدينة</h2><div class="table"><table>
<tr><th>المدينة</th><th>النسخة</th><th>صفوف</th><th>مشاريع</th><th>الرقم التالي</th><th>مطابق</th><th>مفسَّر</th><th>غير مفسَّر</th><th>استثناءات</th></tr>
<?php foreach ($reports as $code => $r): $p = $pick($r, 'numbers', 'project numbers (set equality)'); $n = $pick($r, 'numbers', 'next project number = highest source number + 1'); ?>
<tr><td><b><?= $e($cities[$code]) ?></b> <code><?= $code ?></code></td><td><code><?= $e($r['source']) ?></code></td><td class="n"><?= number_format($r['rows']) ?></td>
<td class="n"><?= $e($p['target'] ?? '') ?></td><td class="n"><?= $e($n['target'] ?? '') ?></td><td class="n"><?= $r['summary']['equal'] ?></td><td class="n"><?= $r['summary']['explained'] ?></td>
<td class="n ok"><?= $r['summary']['unexplained'] ?></td><td class="n"><?= number_format(array_sum($r['exceptions'])) ?></td></tr>
<?php endforeach; ?></table></div>
<div class="note">حلب منقولة من نسخة 22 تشرين الأول لأن نسخة 19 تشرين الثاني ناقصة (95 جدولاً). يُعاد نقلها عند توفر نسخة كاملة.</div>
</section>

<section><h2>أثر ملحق v1.2</h2><p>نفس النقل قبل وبعد التصحيحات المعتمدة:</p><div class="table"><table>
<tr><th>النوع</th><?php foreach ($cities as $code => $name): ?><th><?= $e($name) ?> قبل ← بعد</th><?php endforeach; ?></tr>
<?php foreach (['reference_missing', 'truncated', 'account_unmapped'] as $k): ?><tr><td><?= $e($kinds[$k]) ?></td>
<?php foreach ($cities as $code => $name): ?><td class="n"><?= number_format($before[$code][$k] ?? 0) ?> ← <span class="down"><?= number_format($reports[$code]['exceptions'][$k] ?? 0) ?></span></td><?php endforeach; ?></tr>
<?php endforeach; ?></table></div></section>

<section><h2>القيم المسجلة للمراجعة</h2><p>ليست أخطاء نقل: هي حالات في بيانات المصدر نفسها، حُفظت كما هي أو تُركت فارغة، وكل واحدة مسجلة مع قيمتها الأصلية.</p><div class="table"><table>
<tr><th>النوع</th><?php foreach ($cities as $name): ?><th><?= $e($name) ?></th><?php endforeach; ?></tr>
<?php $all = []; foreach ($reports as $r) { $all += $r['exceptions']; } foreach (array_keys($all) as $k): ?><tr><td><?= $e($kinds[$k] ?? $k) ?></td>
<?php foreach ($cities as $code => $name): ?><td class="n"><?= number_format($reports[$code]['exceptions'][$k] ?? 0) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?></table></div>
<p>أكبرها: 602 شخصاً في حلب بدون كنية في المصدر، و52 سطر زائر فارغاً في حلب (بدون مستخدم أصلاً). كل هذه الزيارات لها زوار آخرون مسجلون، فلم يضع أي زائر.</p></section>
</div></main></body></html>
<?php
file_put_contents(__DIR__.'/m3-transfer-report.html', ob_get_clean());
echo "written m3-transfer-report.html\n";
