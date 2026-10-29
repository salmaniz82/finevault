$ErrorActionPreference = 'Stop'
Push-Location (Join-Path $PSScriptRoot 'public')
try {
    & php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
} finally {
    Pop-Location
}
