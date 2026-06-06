<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';

$u = require_role('admin');
$pdo = db();
$bp = cfg()['base_path'] ?? '';

function _backup_ident(string $name): string {
  return '`' . str_replace('`', '``', $name) . '`';
}

function _backup_sql_value(PDO $pdo, $value): string {
  if ($value === null) return 'NULL';
  if (is_bool($value)) return $value ? '1' : '0';
  if (is_int($value) || is_float($value)) return (string)$value;
  return $pdo->quote((string)$value);
}

function _backup_stream(PDO $pdo): void {
  @set_time_limit(0);
  echo "-- COOL-Grades SQL-Backup\n";
  echo "-- Erstellt am " . date('Y-m-d H:i:s') . "\n";
  echo "-- Zeichensatz utf8mb4\n\n";
  echo "SET NAMES utf8mb4;\n";
  echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

  $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
  foreach($tables as $row){
    $table = (string)($row[0] ?? '');
    if($table === '') continue;

    $quotedTable = _backup_ident($table);
    $createRow = $pdo->query("SHOW CREATE TABLE " . $quotedTable)->fetch(PDO::FETCH_NUM);
    $createSql = (string)($createRow[1] ?? '');

    echo "--\n-- Tabellenstruktur für " . $table . "\n--\n\n";
    echo "DROP TABLE IF EXISTS " . $quotedTable . ";\n";
    echo $createSql . ";\n\n";

    $stmt = $pdo->query("SELECT * FROM " . $quotedTable);
    $rowsWritten = 0;
    while($data = $stmt->fetch(PDO::FETCH_ASSOC)){
      $columns = [];
      $values = [];
      foreach($data as $column => $value){
        $columns[] = _backup_ident((string)$column);
        $values[] = _backup_sql_value($pdo, $value);
      }
      echo "INSERT INTO " . $quotedTable . " (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
      $rowsWritten++;
    }

    if($rowsWritten > 0) echo "\n";
  }

  echo "SET FOREIGN_KEY_CHECKS=1;\n";
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $filename = 'cool-grades-backup-' . date('Y-m-d_H-i-s') . '.sql';
  emit_event('admin_database_backup_downloaded', ['filename' => $filename]);

  header('Content-Type: application/sql; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  _backup_stream($pdo);
  exit;
}

render_header('Datenbanksicherung', $u);
?>
<div class="grid">
  <div class="col-12 col-8">
    <div class="card">
      <h1>Datenbanksicherung</h1>
      <p class="muted">Hier kannst du eine vollständige SQL-Sicherung der aktuellen COOL-Grades-Datenbank herunterladen.</p>

      <div class="card" style="padding:14px;background:rgba(71,142,79,.06);border-style:dashed">
        <div><b>Inhalt der Sicherung</b></div>
        <div class="muted" style="margin-top:6px">Die Sicherung enthält Tabellenstruktur und Daten der Anwendung im SQL-Format.</div>
      </div>

      <form method="post" style="margin-top:14px" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <button class="btn">SQL-Sicherung herunterladen</button>
        <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück</a>
      </form>
    </div>
  </div>
</div>
<?php render_footer(); ?>
