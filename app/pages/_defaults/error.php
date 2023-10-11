<?php

$use_code = $code ?? 500;
$use_home = $home ?? '/';
$use_message = match ($use_code) {
  404 => 'The page not exists or has been moved.',
  default => $message ?? null,
};
$status = vc_data('http_status', $use_code) ?? 'Unknown http code';
$title = $use_code . ' - ' . $status;

if (vc_wants('json')) {
  vc_json(array(
    'error' => $use_code,
    'message' => $use_message,
  ), $use_code);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php vc_out($title) ?></title>
  <style>
    body {
      padding: 0;
      margin: 0;
      font-family: Helvetica, "Trebuchet MS", Verdana, sans-serif;
    }
    h1 {
      color: red;
    }
    .container {
      padding: 2rem;
      margin: 3rem auto;
      max-width: 90vw;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1><?php vc_out($title) ?></h1>

    <?php if ($use_message): ?>
      <p><?php vc_out($use_message) ?></p>
    <?php endif ?>

    <p>Please contact administrator for further information.</p>

    <?php if ($use_home): ?>
      <hr>
      <a href="javascript:;" onclick="history.back()">Back</a> | <a href="<?php vc_out($home) ?>">Home</a>
    <?php endif ?>
  </div>
</body>
</html>
