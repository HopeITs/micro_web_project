<?php require __DIR__.'/lib.php'; $T=TGT; $db=SRC['LAT']; $cid=cities()['LAT'];
$tg=[]; foreach(q("select * from $T.project_applicants where city_id=$cid") as $r) $tg[$r['legacy_id']]=$r;
$pa=[]; foreach(q("select * from $db.person_microproject") as $r) $pa[$r['Person_ID']][]=$r;
$S=[];
foreach(['P_Goal'=>'goal','P_GoalHow'=>'goal_how','P_OtherNotes'=>'other_notes','P_WorkYears'=>'work_years'] as $sc=>$tc){
 foreach(q("select P_ID, `$sc` v from $db.person where `$sc` is not null and trim(`$sc`)<>''") as $p){
  $v=trim($p['v']); $rows=$pa[$p['P_ID']]??[];
  if(!$rows){ @$S["$sc: person without applicant row"][]=$p['P_ID']; continue; }
  $found=false; foreach($rows as $r){ $t=$tg[$r['PMP_ID']]??null; if($t && $t[$tc]!==null && str_contains(trim($t[$tc]),$v)) $found=true; }
  if($found){ $own=false; foreach($rows as $r) if(trim((string)$r[$sc])===$v) $own=true; @$S["$sc: present in target (".($own?'same as applicant value':'moved').")"][]=$p['P_ID']; continue; }
  $conf=false; foreach($rows as $r) if(trim((string)$r[$sc])!=='') $conf=true;
  $e=q("select count(*) c from $T.migration_exceptions e join $T.migration_runs m on m.id=e.migration_run_id where m.city_id=$cid and e.source_table='person' and e.source_key=? and e.column_name=?",[(string)$p['P_ID'],$sc])[0]['c'];
  @$S["$sc: LOST (".($conf?'conflicts with non-empty applicant value':'applicant empty').") ".($e?'recorded':'NO exception')][]=$p['P_ID'];
 }
}
foreach($S as $k=>$v) echo "$k: ",count($v)," (",implode(',',array_slice($v,0,8)),")\n";
if(!$S) echo "no non-empty Latakia person extras\n";
// excluded Damascus copies
$d=SRC['DAM'];
echo "dept differs: ",json_encode(q("select count(*) n, sum(not (d.P_HasDebts <=> m.P_HasDebts)) diff, sum(m.PMP_ID is null) orphan from $d.dept d left join $d.person_microproject m on m.PMP_ID=d.PMP_ID")[0]),"\n";
echo "work_exp differs: ",json_encode(q("select count(*) n, sum(not (cast(w.P_WorkYears as char) <=> trim(m.P_WorkYears))) diff, sum(m.PMP_ID is null) orphan, sum(w.P_WorkYears is not null and (m.P_WorkYears is null or trim(m.P_WorkYears)='')) onlyold from $d.work_exp w left join $d.person_microproject m on m.PMP_ID=w.PMP_ID")[0]),"\n";
foreach(SRC as $c=>$db){ $has=tname($db,'kobo_visit_schedule'); $o=q("select count(*) n, sum(v.ID is null) noVisit from $db.office_monitoring o left join $db.visit v on v.ID=o.Visit_ID")[0]; $r=q("select count(*) n, sum(v.ID is null) noVisit from $db.rehabvisit o left join $db.visit v on v.ID=o.Visit_ID")[0]; echo "$c office orphan=",json_encode($o)," rehab=",json_encode($r),"\n"; }
