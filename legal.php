<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/legal_pages.php';

$slug = trim((string)($_GET['page'] ?? ''));
$config = legal_page_config($slug);
if(!$config){
  http_response_code(404);
  render_header('Seite nicht gefunden');
  ?>
  <div class="grid">
    <div class="col-12">
      <div class="card legal-page-card">
        <h1>Seite nicht gefunden</h1>
        <p class="muted">Die angeforderte Rechtsseite ist nicht vorhanden.</p>
        <a class="btn secondary" href="<?php echo h(cfg()['base_path'] ?? ''); ?>/login.php">Zum Login</a>
      </div>
    </div>
  </div>
  <?php
  render_footer();
  exit;
}

$title = (string)($config['title'] ?? 'Rechtliche Hinweise');
$html = legal_page_get_html($slug);

render_header($title);
?>
<div class="grid">
  <div class="col-12">
    <div class="card legal-page-card">
      <h1><?php echo h($title); ?></h1>
      <div class="legal-content">
        <?php echo $html; ?>
      </div>
      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h(cfg()['base_path'] ?? ''); ?>/login.php">Zum Login</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
