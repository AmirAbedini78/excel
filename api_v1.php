<?php
require __DIR__.'/app/bootstrap.php';
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'=>false,
    'error'=>[
        'code'=>'api_v1_deprecated',
        'message'=>'API V1 بعد از مهاجرت SaaS غیرفعال شده است. از API V2 و Token مخصوص Workspace استفاده کنید.',
        'upgrade'=>'/api/v2'
    ]
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
