param([string]$ProjectRoot=".")
$ErrorActionPreference="Stop"

$root=(Resolve-Path $ProjectRoot).Path
$here=Split-Path -Parent $MyInvocation.MyCommand.Path
$payload=Join-Path $here "payload"

$required=@("index.php","assets\app.js","assets\style.css",".cpanel.yml","app\bootstrap.php")
foreach($f in $required){if(-not(Test-Path(Join-Path $root $f))){throw "Project file not found: $f"}}

$backup=Join-Path $env:TEMP ("AccountingCRM_V3_2_"+(Get-Date -Format "yyyyMMdd_HHmmss"))
New-Item -ItemType Directory -Force -Path $backup|Out-Null
foreach($f in @("assets\app.js","assets\style.css",".cpanel.yml")){
  $src=Join-Path $root $f
  $dst=Join-Path $backup $f
  New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent)|Out-Null
  Copy-Item $src $dst -Force
}

Copy-Item (Join-Path $payload "v3_2_api.php") (Join-Path $root "v3_2_api.php") -Force
Copy-Item (Join-Path $payload "io.php") (Join-Path $root "io.php") -Force
Copy-Item (Join-Path $payload "app\Core\DataIO.php") (Join-Path $root "app\Core\DataIO.php") -Force

$utf8=New-Object System.Text.UTF8Encoding($false)

$appPath=Join-Path $root "assets\app.js"
$app=[IO.File]::ReadAllText($appPath,[Text.Encoding]::UTF8)
if($app -notmatch "__ACCOUNTING_V32__"){
  $addon=[IO.File]::ReadAllText((Join-Path $payload "assets\v3_2_features.js"),[Text.Encoding]::UTF8)
  [IO.File]::WriteAllText($appPath,$app+"`r`n`r`n"+$addon,$utf8)
}

$cssPath=Join-Path $root "assets\style.css"
$css=[IO.File]::ReadAllText($cssPath,[Text.Encoding]::UTF8)
if($css -notmatch "Accounting CRM V3\.2"){
  $addon=[IO.File]::ReadAllText((Join-Path $payload "assets\v3_2_features.css"),[Text.Encoding]::UTF8)
  [IO.File]::WriteAllText($cssPath,$css+"`r`n`r`n"+$addon,$utf8)
}

$cpPath=Join-Path $root ".cpanel.yml"
$cp=[IO.File]::ReadAllText($cpPath,[Text.Encoding]::UTF8)
if($cp -notmatch "/bin/cp io\.php"){
  $needle="- /bin/cp index.php `$DEPLOYPATH"
  if($cp.Contains($needle)){
    $cp=$cp.Replace($needle,$needle+"`n    - /bin/cp io.php `$DEPLOYPATH`n    - /bin/cp v3_2_api.php `$DEPLOYPATH")
  }else{
    throw "Could not find index.php deploy line in .cpanel.yml"
  }
  [IO.File]::WriteAllText($cpPath,$cp,$utf8)
}

Write-Host ""
Write-Host "Accounting CRM V3.2 patch applied successfully." -ForegroundColor Green
Write-Host "Backup: $backup"
Write-Host ""
Write-Host "Next:"
Write-Host "  git status"
Write-Host "  git add ."
Write-Host '  git commit -m "Add bulk delete, import export and calendar quick actions V3.2"'
Write-Host "  git pull --rebase origin main"
Write-Host "  git push origin main"
Write-Host ""
Write-Host "Then cPanel: Update from Remote -> Deploy HEAD Commit"
