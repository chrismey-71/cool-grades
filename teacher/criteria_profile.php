<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/../lib/participation_presets.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? 0);
$date_from=(string)($_GET['from'] ?? '');
$date_to=(string)($_GET['to'] ?? '');

if($date_from==='' || $date_to===''){
  // Ohne mitgegebenen Zeitraum (z. B. Direktaufruf) auf das laufende Semester
  // zurueckfallen, statt eine leere/fehlerhafte Datumsspanne zu zeigen -
  // analog zu teacher/competence_profile.php.
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
$studentsById=[];
foreach($students as $s){ $studentsById[(int)$s['id']]=$s; }

// Komplette Kriterien-Liste (dieselbe Quelle wie die Kriterien-Chips in
// teacher/participation_new.php), damit auch 0x genannte Kriterien im
// Balkendiagramm sichtbar sind ("kam im Zeitraum einfach nicht vor" statt
// stillschweigend zu fehlen).
$criteriaAll=load_participation_criteria($pdo,(int)$u['id'],$subject_id);

$aggregate=criteria_profile_aggregate($pdo,$class_id,$subject_id,$date_from,$date_to);

// Pro Schueler:in: Tabellenzeile (Einträge, Top-3) + volle, absteigend
// sortierte Balken-Liste fürs Profil - nur wer mindestens einen
// Kriterien-Eintrag im Zeitraum hat.
$profileRows=[];
$profilesForJs=[];
foreach($students as $s){
  $sid=(int)$s['id'];
  $entry=$aggregate[$sid] ?? null;
  if(!$entry || $entry['total_entries']<=0) continue;

  $bars=[];
  foreach($criteriaAll as $c){
    $cid=(int)$c['id'];
    $bars[]=[
      'id'=>$cid,
      'label'=>(string)$c['label'],
      'category'=>(string)$c['category'],
      'count'=>(int)($entry['by_criteria'][$cid] ?? 0),
    ];
  }
  usort($bars, function($a,$b){
    if($a['count']===$b['count']) return strcasecmp($a['label'],$b['label']);
    return $b['count']<=>$a['count'];
  });

  $topThree=array_slice(array_filter($bars, fn($b)=>$b['count']>0),0,3);

  $profileRows[]=[
    'student_id'=>$sid,
    'name'=>$s['last_name'].', '.$s['first_name'],
    'total_entries'=>$entry['total_entries'],
    'top_three'=>$topThree,
  ];
  $profilesForJs[$sid]=[
    'name'=>$s['last_name'].', '.$s['first_name'],
    'bars'=>$bars,
  ];
}
usort($profileRows, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
$firstStudentId=$profileRows ? $profileRows[0]['student_id'] : 0;

$backParams=['class_id'=>$class_id,'subject_id'=>$subject_id,'from'=>$date_from,'to'=>$date_to];

render_header('Kriterien-Profil',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Kriterien-Profil</h1>
  <div class="muted">Klasse: <b><?php echo h($class['name']); ?></b> · Fach: <b><?php echo h($subject['code']); ?></b> · Zeitraum: <b><?php echo h($date_from); ?></b> bis <b><?php echo h($date_to); ?></b></div>
  <p class="muted" style="margin-top:8px">Aggregierte Übersicht der über die „Kriterien“-Chips bei der Mitarbeit erfassten, fachspezifischen Kriterien. Rein deskriptiv und ergänzend – keine Note, kein automatischer Vorschlag, genau wie beim <a href="<?php echo h($bp); ?>/teacher/competence_profile.php?<?php echo h(http_build_query($backParams)); ?>">Kompetenzprofil</a>.</p>

  <div style="margin-top:10px">
    <a class="btn secondary small" href="<?php echo h($bp); ?>/reports.php?<?php echo h(http_build_query($backParams+['period'=>'custom'])); ?>">← Zurück zu Berichte &amp; Auswertungen</a>
    <a class="btn secondary small" href="<?php echo h($bp); ?>/teacher/participation_new.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id])); ?>">Mitarbeit erfassen</a>
  </div>

  <?php if(!$profileRows): ?>
    <div class="card" style="margin-top:14px">
      <div class="muted">Keine Kriterien-Einträge im gewählten Zeitraum.</div>
    </div>
  <?php else: ?>
    <table class="table" style="margin-top:14px">
      <thead><tr>
        <th>Schüler:in</th>
        <th style="text-align:center">Einträge</th>
        <th>Häufigste Kriterien</th>
        <th></th>
      </tr></thead>
      <tbody id="cpStudentTable">
        <?php foreach($profileRows as $row): ?>
          <tr class="cp-row-selectable<?php echo $row['student_id']===$firstStudentId?' cp-row-active':''; ?>" data-student-id="<?php echo (int)$row['student_id']; ?>">
            <td><?php echo h($row['name']); ?></td>
            <td style="text-align:center"><b><?php echo (int)$row['total_entries']; ?></b></td>
            <td class="muted" style="font-size:12.5px">
              <?php
                $parts=[];
                foreach($row['top_three'] as $t){ $parts[]=h($t['label']).' ('.(int)$t['count'].')'; }
                echo $parts ? implode(' · ', $parts) : '–';
              ?>
            </td>
            <td><button type="button" class="btn secondary small cp-profile-btn" data-student-id="<?php echo (int)$row['student_id']; ?>">Profil ansehen</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="muted" style="margin-top:10px;font-size:12px">Schüler:innen ohne Kriterien-Einträge im Zeitraum werden hier nicht aufgeführt.</div>
  <?php endif; ?>
</div></div></div>

<?php if($profileRows): ?>
<div class="grid"><div class="col-12"><div class="card">
  <h2>Profil: <span id="cpProfileName"></span></h2>
  <div class="muted">Verteilung aller im Zeitraum erfassten Kriterien, absteigend sortiert – Gesprächsgrundlage für ein Feedbackgespräch.</div>

  <div class="cp-student-picker" id="cpStudentPicker">
    <?php foreach($profileRows as $row): ?>
      <div class="cp-student-pick<?php echo $row['student_id']===$firstStudentId?' active':''; ?>" data-student-id="<?php echo (int)$row['student_id']; ?>"><?php echo h($row['name']); ?></div>
    <?php endforeach; ?>
  </div>

  <div id="cpBarChart" style="margin-top:16px"></div>

  <div class="muted" style="margin-top:14px;padding-top:10px;border-top:1px solid var(--border);font-size:12px">Balkenlänge = Häufigkeit der Beobachtung im gewählten Zeitraum, nicht die Qualität der Leistung (ob eine Beobachtung positiv/neutral/negativ war, steht weiterhin im Eindruck/Relevanz-Feld des jeweiligen Eintrags). Kriterien mit 0 Nennungen sind ausgegraut und keine automatische „Schwäche“ – eventuell kam das Thema im Zeitraum einfach nicht vor.</div>
</div></div></div>
<?php endif; ?>

<?php if($profileRows): ?>
<script>
(function(){
  var profiles = <?php echo json_encode($profilesForJs, JSON_UNESCAPED_UNICODE); ?>;
  var currentId = <?php echo (int)$firstStudentId; ?>;

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function renderProfile(sid){
    var p = profiles[sid];
    if(!p) return;
    currentId = sid;
    document.getElementById('cpProfileName').textContent = p.name;

    var max = 1;
    p.bars.forEach(function(b){ if(b.count > max) max = b.count; });

    var html = '';
    p.bars.forEach(function(b){
      var pct = Math.round((b.count / max) * 100);
      html += '<div class="cp-bar-row' + (b.count === 0 ? ' zero' : '') + '">' +
        '<div class="cp-bar-label">' + escapeHtml(b.label) + '<span class="cat">' + escapeHtml(b.category || '') + '</span></div>' +
        '<div class="cp-bar-track"><div class="cp-bar-fill" style="width:' + (b.count === 0 ? 3 : pct) + '%"></div></div>' +
        '<div class="cp-bar-count">' + b.count + 'x</div>' +
      '</div>';
    });
    document.getElementById('cpBarChart').innerHTML = html;

    document.querySelectorAll('.cp-student-pick').forEach(function(el){
      el.classList.toggle('active', parseInt(el.getAttribute('data-student-id'),10) === sid);
    });
    document.querySelectorAll('#cpStudentTable tr').forEach(function(el){
      el.classList.toggle('cp-row-active', parseInt(el.getAttribute('data-student-id'),10) === sid);
    });
  }

  document.querySelectorAll('.cp-student-pick, #cpStudentTable tr.cp-row-selectable, .cp-profile-btn').forEach(function(el){
    el.addEventListener('click', function(ev){
      ev.preventDefault();
      var sid = parseInt(el.getAttribute('data-student-id'),10);
      if(Number.isInteger(sid) && sid > 0) renderProfile(sid);
    });
  });

  renderProfile(currentId);
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
