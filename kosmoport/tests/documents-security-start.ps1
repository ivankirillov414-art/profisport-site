$ErrorActionPreference = 'Stop'
$reviewRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot 'documents-security-runtime'))
$reviewExpected = 'C:\Users\Izop300520251\Documents\Codex\2026-10-29\new-chat-3\work\kosmoport-crm\tests\documents-security-runtime'
if ($reviewRoot -ne $reviewExpected) { throw 'Unexpected document security runtime path' }
New-Item -ItemType Directory -Force -Path $reviewRoot | Out-Null
Set-Content -LiteralPath (Join-Path $reviewRoot '.gitignore') -Value "*`n!.gitignore" -Encoding utf8
$reviewRun = Join-Path $reviewRoot ('run-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $reviewRun | Out-Null
$reviewMaria = 'C:\Users\Izop300520251\AppData\Local\id-studio-test-runtime\mariadb\mariadb-10.11.16-winx64'
$reviewBytes = New-Object byte[] 32
$reviewRng = [Security.Cryptography.RandomNumberGenerator]::Create()
$reviewRng.GetBytes($reviewBytes)
$reviewRng.Dispose()
$reviewPassword = [BitConverter]::ToString($reviewBytes).Replace('-', '').ToLowerInvariant()
$reviewListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$reviewListener.Start()
$reviewPort = $reviewListener.LocalEndpoint.Port
$reviewListener.Stop()
$reviewData = Join-Path $reviewRun 'data'
& (Join-Path $reviewMaria 'bin\mariadb-install-db.exe') "--datadir=$reviewData" "--password=$reviewPassword" "--port=$reviewPort" --silent *> (Join-Path $reviewRun 'install.private.log')
if ($LASTEXITCODE -ne 0) { throw 'Isolated document database initialization failed; see private runtime log' }
$reviewArgs = @('--no-defaults', ('--basedir="' + $reviewMaria + '"'), ('--datadir="' + $reviewData + '"'), '--bind-address=127.0.0.1', "--port=$reviewPort", '--skip-name-resolve', '--max-connections=12', '--innodb-buffer-pool-size=32M', ('--pid-file="' + (Join-Path $reviewRun 'server.pid') + '"'), '--console')
$reviewProcess = Start-Process -FilePath (Join-Path $reviewMaria 'bin\mariadbd.exe') -ArgumentList $reviewArgs -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $reviewRun 'server.stdout.private.log') -RedirectStandardError (Join-Path $reviewRun 'server.stderr.private.log')
$reviewMetadata = @{host='127.0.0.1';port=$reviewPort;user='root';password=$reviewPassword;pid=$reviewProcess.Id;runtime_path=$reviewRun}
$reviewMetadata | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $reviewRoot 'runtime.private.json') -Encoding utf8
$reviewDeadline = [DateTime]::UtcNow.AddSeconds(35)
$reviewReady = $false
while ([DateTime]::UtcNow -lt $reviewDeadline) {
    $reviewProcess.Refresh()
    if ($reviewProcess.HasExited) { throw 'Isolated document database exited; inspect private runtime log' }
    $reviewClient = [Net.Sockets.TcpClient]::new()
    try { $reviewReady = $reviewClient.ConnectAsync('127.0.0.1', $reviewPort).Wait(300) -and $reviewClient.Connected } catch {} finally { $reviewClient.Dispose() }
    if ($reviewReady) { break }
    Start-Sleep -Milliseconds 150
}
if (-not $reviewReady) { throw 'Isolated document database did not start' }
Write-Output 'Document security loopback runtime started; credentials are private and were not printed.'
