<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/../lib/competence_observations.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? 0);
$date_from=(string)($_GET['from'] ?? '');
$date_to=(string)($_GET['to'] ?? '');

if($date_from==='' || $date_to===''){
  // Ohne mitgegebenen Zeitraum (z. B. Direktaufruf) auf das laufende Semester
  // zurückfallen, statt eine leere/fehlerhafte Datumsspanne zu zeigen.
  $selectedSchoolId=teacher_school_context_id($pdo,(int)$u['id']);
  $resolvedPeriod=app_school_period_resolve('current','','',$selectedSchoolId,true);
  $date_from=(string)$resolvedPeriod['from'];
  $date_to=(string)$resolvedPeriod['to'];
}

$st=$pdo->prepare("SELECT * FROM classes WHERE id=?");
$st->execute([$class_id]);
$class=$st->fetch();
$st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");
$st->execute([$subject_id]);
$subject=$st->fetch();
if(!$class || !$subject){
  http_response_code(400);
  exit('Klasse/Fach ungültig.');
}
require_teacher_assignment($u,$class_id,$subject_id);

$students=load_class_students($pdo,$class_id,false);
$aggregate=competence_profile_aggregate($pdo,$class_id,$subject_id,$date_from,$date_to);
$daysWithData=competence_profile_days_with_data($pdo,$class_id,$subject_id,$date_from,$date_to);
$categoryLabels=competence_category_labels();

$backParams=['class_id'=>$class_id,'subject_id'=>$subject_id,'from'=>$date_from,'to'=>$date_to];

render_header('Kompetenzprofil',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Kompetenzprofil</h1>
  <div class="muted">Klasse: <b><?php echo h($class['name']); ?></b> · Fach: <b><?php echo h($subject['code']); ?></b> · Zeitraum: <b><?php echo h($date_from); ?></b> bis <b><?php echo h($date_to); ?></b></div>
  <p class="muted" style="margin-top:8px">Aggregierte Übersicht der über „Kompetenz-Beobachtung“ erfassten Methoden-, Sozial- und Selbst-/Personalkompetenz-Notizen. Rein deskriptiv und ergänzend - keine Note, kein automatischer Vorschlag wie bei der Mitarbeit-Kurzauswertung.</p>

  <div style="margin-top:10px">
    <a class="btn secondary small" href="<?php echo h($bp); ?>/reports.php?<?php echo h(http_build_query($backParams+['period'=>'custom'])); ?>">← Zurück zu Berichte &amp; Auswertungen</a>
    <a class="btn secondary small" href="<?php echo h($bp); ?>/teacher/competence_quick.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id])); ?>">Kompetenz-Beobachtung erfassen</a>
  </div>

  <div class="muted" style="margin-top:14px">Datenbasis: <b><?php echo (int)$daysWithData; ?></b> Tag(e) mit mindestens einer Beobachtung im Zeitraum.</div>

  <?php if(!$aggregate): ?>
    <div class="card" style="margin-top:14px">
      <div class="muted">Keine Kompetenz-Beobachtungen im gewählten Zeitraum.</div>
    </div>
  <?php else: ?>
    <table class="table" style="margin-top:14px">
      <thead><tr>
        <th>Schüler:in</th>
        <?php foreach($categoryLabels as $label): ?><th style="text-align:center"><?php echo h($label); ?></th><?php endforeach; ?>
        <th style="text-align:center">Gesamt</th>
        <th>Häufigste Tags</th>
      </tr></thead>
      <tbody>
        <?php foreach($students as $stu):
          $sid=(int)$stu['id'];
          $entry=$aggregate[$sid] ?? null;
          if(!$entry) continue;
          $tagsSorted=$entry['tags'];
          usort($tagsSorted, function($a,$b){ return $b['count'] <=> $a['count']; });
          $topTags=array_slice($tagsSorted,0,3);
        ?>
          <tr>
            <td><?php echo h($stu['last_name'].', '.$stu['first_name']); ?></td>
            <?php foreach(array_keys($categoryLabels) as $cat): ?>
              <td style="text-align:center"><?php echo (int)($entry['by_category'][$cat] ?? 0); ?></td>
            <?php endforeach; ?>
            <td style="text-align:center"><b><?php echo (int)$entry['total']; ?></b></td>
            <td class="muted" style="font-size:12.5px">
              <?php
                $parts=[];
                foreach($topTags as $t){ $parts[]=h($t['label']).' ('.(int)$t['count'].')'; }
                echo implode(' · ', $parts);
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="muted" style="margin-top:10px;font-size:12px">Schüler:innen ohne Kompetenz-Beobachtung im Zeitraum werden hier nicht aufgeführt.</div>
  <?php endif; ?>
</div></div></div>
<?php render_footer(); ?>
