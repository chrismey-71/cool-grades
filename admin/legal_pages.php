<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/legal_pages.php';

$u = require_role('admin');
$bp = cfg()['base_path'] ?? '';

$msg = '';
$err = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  try{
    legal_page_save_html('impressum', (string)($_POST['impressum_html'] ?? ''));
    legal_page_save_html('datenschutz', (string)($_POST['datenschutz_html'] ?? ''));
    $msg = 'Impressum und Datenschutz wurden gespeichert.';
  }catch(Throwable $e){
    $err = 'Die Inhalte konnten nicht gespeichert werden.';
  }
}

$impressumHtml = legal_page_get_html('impressum');
$datenschutzHtml = legal_page_get_html('datenschutz');

render_header('Impressum und Datenschutz', $u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Impressum und Datenschutz</h1>
      <p class="muted">
        Die hier gepflegten Texte erscheinen über die Links in der Fußzeile. Bitte tragen Sie die rechtlich geprüften Inhalte der Schule bzw. des Betreibers ein.
      </p>

      <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <form method="post" <?php echo dirty_form_attrs(); ?> data-legal-editor-form="1">
        <?php echo csrf_input(); ?>

        <div class="settings-grid">
          <div class="settings-panel col-12">
            <div class="settings-panel-title">Impressum</div>
            <div class="small muted settings-panel-note">
              Zulässig sind einfache HTML-Elemente wie Überschriften, Absätze, Listen, Hervorhebungen und Links.
            </div>
            <div class="legal-editor-toolbar" data-target="impressumEditor">
              <button type="button" data-command="formatBlock" data-value="h2">Überschrift</button>
              <button type="button" data-command="bold">Fett</button>
              <button type="button" data-command="insertUnorderedList">Liste</button>
              <button type="button" data-command="createLink">Link</button>
            </div>
            <div class="legal-html-editor" id="impressumEditor" contenteditable="true" aria-label="Impressum bearbeiten"><?php echo $impressumHtml; ?></div>
            <textarea name="impressum_html" hidden><?php echo h($impressumHtml); ?></textarea>
          </div>

          <div class="settings-panel col-12">
            <div class="settings-panel-title">Datenschutzbestimmung</div>
            <div class="small muted settings-panel-note">
              Verwenden Sie hier die für die konkrete Installation geprüfte Datenschutzinformation. Der Repository-Datenschutzhinweis ersetzt diese Betriebserklärung nicht.
            </div>
            <div class="legal-editor-toolbar" data-target="datenschutzEditor">
              <button type="button" data-command="formatBlock" data-value="h2">Überschrift</button>
              <button type="button" data-command="bold">Fett</button>
              <button type="button" data-command="insertUnorderedList">Liste</button>
              <button type="button" data-command="createLink">Link</button>
            </div>
            <div class="legal-html-editor" id="datenschutzEditor" contenteditable="true" aria-label="Datenschutzbestimmung bearbeiten"><?php echo $datenschutzHtml; ?></div>
            <textarea name="datenschutz_html" hidden><?php echo h($datenschutzHtml); ?></textarea>
          </div>
        </div>

        <div class="row" style="margin-top:14px;align-items:center">
          <button class="btn">Inhalte speichern</button>
          <a class="btn secondary" href="<?php echo h($bp); ?>/legal.php?page=impressum" target="_blank" rel="noopener">Impressum ansehen</a>
          <a class="btn secondary" href="<?php echo h($bp); ?>/legal.php?page=datenschutz" target="_blank" rel="noopener">Datenschutz ansehen</a>
          <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  var form = document.querySelector('form[data-legal-editor-form="1"]');
  if(!form) return;

  function syncEditors(){
    var imprint = document.getElementById('impressumEditor');
    var privacy = document.getElementById('datenschutzEditor');
    var imprintField = form.elements['impressum_html'];
    var privacyField = form.elements['datenschutz_html'];
    if(imprint && imprintField) imprintField.value = imprint.innerHTML;
    if(privacy && privacyField) privacyField.value = privacy.innerHTML;
  }

  Array.prototype.slice.call(document.querySelectorAll('.legal-editor-toolbar button')).forEach(function(btn){
    btn.addEventListener('click', function(){
      var toolbar = btn.closest('.legal-editor-toolbar');
      var editor = toolbar ? document.getElementById(toolbar.getAttribute('data-target') || '') : null;
      if(!editor) return;
      editor.focus();
      var command = btn.getAttribute('data-command') || '';
      var value = btn.getAttribute('data-value') || null;
      if(command === 'createLink'){
        value = window.prompt('Link-Adresse eingeben:', 'https://');
        if(!value) return;
      }
      document.execCommand(command, false, value);
      syncEditors();
    });
  });

  form.addEventListener('input', syncEditors);
  form.addEventListener('submit', syncEditors);
})();
</script>
<?php render_footer(); ?>
