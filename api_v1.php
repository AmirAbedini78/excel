<?php
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/Core/ApiV1.php';
ApiV1Auth::cors();
$token=ApiV1Auth::authenticate();
(new ApiV1($token))->run();
