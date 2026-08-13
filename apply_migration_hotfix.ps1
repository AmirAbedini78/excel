param(
    [string]$ProjectRoot = "."
)

$ErrorActionPreference = "Stop"

$root = (Resolve-Path $ProjectRoot).Path
$schemaPath = Join-Path $root "app\Core\Schema.php"

if (-not (Test-Path $schemaPath)) {
    throw "Schema.php پیدا نشد. این اسکریپت را از ریشه پروژه اجرا کنید؛ جایی که پوشه app وجود دارد."
}

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupDir = Join-Path $root "_backup_before_migration_hotfix_$stamp"
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $backupDir "app\Core") | Out-Null
Copy-Item $schemaPath (Join-Path $backupDir "app\Core\Schema.php") -Force

$content = Get-Content -Raw -Encoding UTF8 $schemaPath

$pattern = '(?s)private static function addColumn\(PDO \$pdo,\s*string \$table,\s*string \$column,\s*string \$definition\): void\s*\{\s*\$st\s*=\s*\$pdo->prepare\("SHOW COLUMNS FROM `\$table` LIKE \?"\);\s*\$st->execute\(\[\$column\]\);\s*if\s*\(!\$st->fetchColumn\(\)\)\s*\$pdo->exec\("ALTER TABLE `\$table` ADD COLUMN `\$column` \$definition"\);\s*\}'

$replacement = @'
private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        // MySQL does not reliably accept a parameter marker in SHOW COLUMNS ... LIKE ?.
        // Check INFORMATION_SCHEMA instead; parameter markers are valid in this SELECT.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid schema identifier');
        }

        $st = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $st->execute([$table, $column]);

        if (!$st->fetchColumn()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
'@

$newContent = [regex]::Replace($content, $pattern, $replacement)

if ($newContent -eq $content) {
    if ($content -match 'INFORMATION_SCHEMA\.COLUMNS') {
        Write-Host "Hotfix قبلاً اعمال شده است." -ForegroundColor Yellow
        exit 0
    }
    throw "الگوی معیوب addColumn پیدا نشد؛ برای جلوگیری از تغییر اشتباه هیچ فایلی دستکاری نشد."
}

[System.IO.File]::WriteAllText($schemaPath, $newContent, (New-Object System.Text.UTF8Encoding($false)))

Write-Host ""
Write-Host "Migration hotfix applied successfully." -ForegroundColor Green
Write-Host "Backup: $backupDir"
Write-Host ""
Write-Host "Next commands:" -ForegroundColor Cyan
Write-Host "  git status"
Write-Host "  git add app/Core/Schema.php"
Write-Host '  git commit -m "Fix MySQL migration column detection"'
Write-Host "  git pull --rebase origin main"
Write-Host "  git push origin main"
Write-Host ""
Write-Host "Then deploy HEAD in cPanel and open:"
Write-Host "  https://excel.bcsrp.ir/migrate_v2.php"
