<?php
// JSON-lines logger for application events and PHP/runtime errors.
// App events go to /logs/app.log, runtime problems additionally to /logs/error.log.

function app_log_dir(): string {
  try{
    $cfgFile = __DIR__.'/../config.php';
    if(is_file($cfgFile)){
      $cfg = require $cfgFile;
      $dir = trim((string)($cfg['log_dir'] ?? ''));
      if($dir !== '') return $dir;
    }
  }catch(Throwable $e){
    // Fall back to the legacy in-app log directory.
  }
  return __DIR__.'/../logs';
}

function app_log_file(string $channel = 'app'): string {
  return app_log_dir().'/'.($channel === 'error' ? 'error.log' : 'app.log');
}

function app_log_request_context(): array {
  $ctx = [];
  if (!empty($_SERVER['REQUEST_METHOD'])) $ctx['method'] = (string)$_SERVER['REQUEST_METHOD'];
  if (!empty($_SERVER['REQUEST_URI'])) $ctx['uri'] = (string)$_SERVER['REQUEST_URI'];
  if (!empty($_SERVER['REMOTE_ADDR'])) $ctx['ip'] = (string)$_SERVER['REMOTE_ADDR'];
  if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['uid'])) $ctx['uid'] = (int)$_SESSION['uid'];
  return $ctx;
}

function app_log_error_name(int $severity): string {
  static $map = [
    E_ERROR => 'E_ERROR',
    E_WARNING => 'E_WARNING',
    E_PARSE => 'E_PARSE',
    E_NOTICE => 'E_NOTICE',
    E_CORE_ERROR => 'E_CORE_ERROR',
    E_CORE_WARNING => 'E_CORE_WARNING',
    E_COMPILE_ERROR => 'E_COMPILE_ERROR',
    E_COMPILE_WARNING => 'E_COMPILE_WARNING',
    E_USER_ERROR => 'E_USER_ERROR',
    E_USER_WARNING => 'E_USER_WARNING',
    E_USER_NOTICE => 'E_USER_NOTICE',
    E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
    E_DEPRECATED => 'E_DEPRECATED',
    E_USER_DEPRECATED => 'E_USER_DEPRECATED',
  ];
  return $map[$severity] ?? ('E_'.$severity);
}

function app_log_write(string $channel, array $row): void {
  static $writing = false;
  if ($writing) return;
  $writing = true;
  try{
    $dir = app_log_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
      error_log('COOL-Grades logger: cannot create log directory '.$dir);
      return;
    }
    $htaccess = $dir.'/.htaccess';
    if(!is_file($htaccess)){
      @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    $file = app_log_file($channel);
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
      $line = '{"ts":"'.date('c').'","level":"error","msg":"logger serialization failed"}';
    }
    $line .= "\n";

    $fh = @fopen($file, 'ab');
    if (!$fh) {
      error_log('COOL-Grades logger: cannot open '.$file.' for writing');
      return;
    }
    @flock($fh, LOCK_EX);
    @fwrite($fh, $line);
    @flock($fh, LOCK_UN);
    @fclose($fh);
  }catch(Throwable $e){
    error_log('COOL-Grades logger failure: '.$e->getMessage());
  }finally{
    $writing = false;
  }
}

function app_log(string $level, string $message, array $context = []): void {
  $row = [
    'ts' => date('c'),
    'level' => $level,
    'msg' => $message,
  ];
  if ($context) $row['ctx'] = $context;
  app_log_write('app', $row);
  if (in_array($level, ['error', 'critical'], true)) app_log_write('error', $row);
}

function app_log_runtime_error(string $message, array $context = []): void {
  $row = [
    'ts' => date('c'),
    'level' => 'error',
    'msg' => $message,
  ];
  if ($context) $row['ctx'] = $context;
  app_log_write('error', $row);
  app_log_write('app', $row);
}

function app_logger_bootstrap(): void {
  static $booted = false;
  if ($booted) return;
  $booted = true;

  set_error_handler(function(int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) return false;
    app_log_runtime_error('PHP error', [
      'severity' => app_log_error_name($severity),
      'message' => $message,
      'file' => $file,
      'line' => $line,
      'request' => app_log_request_context(),
    ]);
    return false;
  });

  set_exception_handler(function(Throwable $ex): void {
    app_log_runtime_error('Uncaught exception', [
      'type' => get_class($ex),
      'message' => $ex->getMessage(),
      'file' => $ex->getFile(),
      'line' => $ex->getLine(),
      'trace' => $ex->getTraceAsString(),
      'request' => app_log_request_context(),
    ]);

    if (PHP_SAPI === 'cli') {
      @fwrite(STDERR, "Uncaught exception. Details written to ".app_log_file('error')."\n");
      return;
    }

    if (!headers_sent()) http_response_code(500);
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Interner Fehler</title></head><body>';
    echo '<p>Es ist ein interner Fehler aufgetreten. Details wurden protokolliert.</p>';
    echo '</body></html>';
  });

  register_shutdown_function(function(): void {
    $error = error_get_last();
    if (!$error) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) return;

    app_log_runtime_error('Fatal error', [
      'severity' => app_log_error_name((int)$error['type']),
      'message' => (string)($error['message'] ?? ''),
      'file' => (string)($error['file'] ?? ''),
      'line' => (int)($error['line'] ?? 0),
      'request' => app_log_request_context(),
    ]);

    if (PHP_SAPI === 'cli' || headers_sent()) return;
    http_response_code(500);
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Interner Fehler</title></head><body>';
    echo '<p>Es ist ein interner Fehler aufgetreten. Details wurden protokolliert.</p>';
    echo '</body></html>';
  });
}

app_logger_bootstrap();
