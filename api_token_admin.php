<?php
require __DIR__.'/app/bootstrap.php';
Auth::require();
header('Location: api_tokens.php');
exit;
