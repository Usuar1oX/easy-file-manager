Write-Host "Deploying files from Local Repo to E:\..." -ForegroundColor Cyan

# Directorio origen (repositorio local)
$sourceDir = "$PSScriptRoot"

# Directorio destino (RaiDrive FTP)
$destDir = "e:\"

# Lista de archivos que componen el proyecto
$filesToDeploy = @(
    "index.html",
    "api\auth.php",
    "api\core.php",
    "api\config.php",
    "api\explorer.php",
    "api\upload.php"
)

foreach ($file in $filesToDeploy) {
    $sourcePath = Join-Path $sourceDir $file
    $destPath = Join-Path $destDir $file
    
    if (Test-Path $sourcePath) {
        Write-Host "Copiando $file..."
        # Usar WriteAllBytes o copia simple para evitar problemas de codificación/RaiDrive
        Copy-Item -Path $sourcePath -Destination $destPath -Force
    }
}

Write-Host "¡Despliegue completado con éxito!" -ForegroundColor Green
Start-Sleep -Seconds 2

