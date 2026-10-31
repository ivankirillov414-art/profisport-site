$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskFixture = Join-Path $PSScriptRoot 'migration-web-fixture.php'
$taskRuntime = Join-Path $PSScriptRoot 'migration-web-runtime'
$taskResult = Join-Path $PSScriptRoot 'migration-web-results.json'
$taskPrivateRuntime = Join-Path $PSScriptRoot 'migration-runtime\local-runtime.private.json'
$taskConfig = [IO.File]::ReadAllText($taskPrivateRuntime).TrimStart([char]0xfeff) | ConvertFrom-Json
if ($taskConfig.host -ne '127.0.0.1' -or $taskConfig.database_source -ne 'kcrm_migration_source_test' -or $taskConfig.database_target -ne 'kcrm_migration_target_test') {
    throw 'Only the isolated disposable database names are accepted.'
}
$taskPhp = $taskConfig.php
$taskExt = $taskConfig.extension_dir
$taskChecks = [Collections.Generic.List[object]]::new()
$taskHttpTimings = [Collections.Generic.List[object]]::new()
$taskServer = $null
$taskStarted = $false
$taskClients = [Collections.Generic.List[object]]::new()
$taskFailure = $null
$taskCleanup = $false
$taskServerStopped = $false
$taskArchiveHash = $null
$taskArchiveSize = 0
$taskArchiveEntries = 0
$taskSourceState = $null
$taskTargetState = $null
$taskAuditActions = @()
$taskPhpDiagnostics = @()
$taskPort = 0

function Assert-Fixture([bool]$condition, [string]$label) {
    if (-not $condition) { throw ('FAILED: ' + $label) }
    $taskChecks.Add([pscustomobject]@{name=$label; status='PASS'})
}
function Invoke-FixtureCli([string]$action) {
    $raw = & $taskPhp -n -d "extension_dir=$taskExt" -d extension=pdo_mysql -d extension=zip -d extension=fileinfo -d display_errors=0 $taskFixture $action
    if ($LASTEXITCODE -ne 0) { throw "Fixture CLI action failed: $action" }
    return (($raw -join "`n").Trim() | ConvertFrom-Json)
}
function New-FixtureClient {
    $handler = [Net.Http.HttpClientHandler]::new()
    $handler.UseProxy = $false
    $handler.AllowAutoRedirect = $false
    $handler.UseCookies = $true
    $handler.CookieContainer = [Net.CookieContainer]::new()
    $client = [Net.Http.HttpClient]::new($handler)
    $client.Timeout = [TimeSpan]::FromSeconds(90)
    $taskClients.Add($client)
    return $client
}
function Send-FixtureRequest($client, [string]$method, [string]$path, $content = $null, [string]$fixtureToken = '') {
    $request = [Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::new($method), ('http://127.0.0.1:' + $taskPort + $path))
    if ($null -ne $content) { $request.Content = $content }
    if ($fixtureToken -ne '') { $null = $request.Headers.TryAddWithoutValidation('X-Fixture-Token', $fixtureToken) }
    $response = $null
    $requestTime=[Diagnostics.Stopwatch]::StartNew()
    try {
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        [byte[]]$bytes = $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
        $headers = @{}
        foreach ($header in $response.Headers) { $headers[$header.Key.ToLowerInvariant()] = $header.Value -join ', ' }
        foreach ($header in $response.Content.Headers) { $headers[$header.Key.ToLowerInvariant()] = $header.Value -join ', ' }
        $taskHttpTimings.Add([pscustomobject]@{method=$method;path=$path;status=[int]$response.StatusCode;elapsed_ms=$requestTime.ElapsedMilliseconds})
        return [pscustomobject]@{Status=[int]$response.StatusCode; Bytes=$bytes; Text=[Text.Encoding]::UTF8.GetString($bytes); Headers=$headers}
    } finally {
        if ($null -ne $response) { $response.Dispose() }
        $request.Dispose()
    }
}
function Form-Content([hashtable]$fields) {
    $pairs = [Collections.Generic.List[Collections.Generic.KeyValuePair[string,string]]]::new()
    foreach ($key in $fields.Keys) { $pairs.Add([Collections.Generic.KeyValuePair[string,string]]::new([string]$key,[string]$fields[$key])) }
    return [Net.Http.FormUrlEncodedContent]::new($pairs)
}
function Upload-Content([hashtable]$fields, [byte[]]$bytes) {
    $content = [Net.Http.MultipartFormDataContent]::new()
    foreach ($key in $fields.Keys) { $content.Add([Net.Http.StringContent]::new([string]$fields[$key]), [string]$key) }
    $binary = [Net.Http.ByteArrayContent]::new($bytes)
    $binary.Headers.ContentType = [Net.Http.Headers.MediaTypeHeaderValue]::new('application/zip')
    $content.Add($binary, 'archive', 'fixture-backup.zip')
    return ,$content
}
function Get-Csrf([string]$html) {
    $match = [regex]::Match($html, 'name="csrf" value="([a-f0-9]{64})"')
    if (-not $match.Success) { throw 'Owner response did not contain a CSRF field.' }
    return $match.Groups[1].Value
}
function Get-FixtureState {
    $stateResponse=Send-FixtureRequest $owner 'GET' '/fixture/state' $null $secret.token
    if ($stateResponse.Status -ne 200) { throw 'Token-protected local fixture state request failed.' }
    return ($stateResponse.Text | ConvertFrom-Json)
}
function Bytes-Sha256([byte[]]$bytes) {
    $hash = [Security.Cryptography.SHA256]::Create()
    try { return [BitConverter]::ToString($hash.ComputeHash($bytes)).Replace('-','').ToLowerInvariant() } finally { $hash.Dispose() }
}

try {
    Add-Type -AssemblyName System.Net.Http
    Add-Type -AssemblyName System.IO.Compression
    $setup = Invoke-FixtureCli 'setup'
    $taskStarted = $true
    $taskSourceState = $setup.state.source
    Assert-Fixture ($setup.ok -and $setup.state.target.counts.kcrm_users -eq 0) 'isolated source fixture and empty target installed'
    $secret = [IO.File]::ReadAllText((Join-Path $taskRuntime 'fixture.private.json')) | ConvertFrom-Json
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $listener.Start()
    $taskPort = $listener.LocalEndpoint.Port
    $listener.Stop()
    $taskServerArgs = @('-n','-d',('extension_dir="'+$taskExt+'"'),'-d','extension=pdo_mysql','-d','extension=zip','-d','extension=fileinfo','-d','display_errors=0','-d','log_errors=1','-d','max_execution_time=120','-d',('error_log="'+(Join-Path $taskRuntime 'php-error.log')+'"'),'-d','upload_max_filesize=16M','-d','post_max_size=20M','-S',('127.0.0.1:'+$taskPort),'-t',('"'+$taskRuntime+'"'),('"'+$taskFixture+'"'))
    $taskServer = Start-Process -FilePath $taskPhp -ArgumentList $taskServerArgs -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntime 'http.stdout.log') -RedirectStandardError (Join-Path $taskRuntime 'http.stderr.log')
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    $ready = $false
    while ([DateTime]::UtcNow -lt $deadline) {
        $taskServer.Refresh()
        if ($taskServer.HasExited) { throw 'Local PHP HTTP fixture exited before readiness.' }
        $tcp = [Net.Sockets.TcpClient]::new()
        try { $ready = $tcp.ConnectAsync('127.0.0.1',$taskPort).Wait(250) -and $tcp.Connected } catch {} finally { $tcp.Dispose() }
        if ($ready) { break }
        Start-Sleep -Milliseconds 100
    }
    Assert-Fixture $ready 'hidden PHP server listening only on loopback'
    $anonymous = New-FixtureClient
    $response = Send-FixtureRequest $anonymous 'GET' '/backup'
    Assert-Fixture ($response.Status -eq 401 -and $response.Headers['content-type'] -ne 'application/zip') 'unauthenticated backup access denied'
    $response = Send-FixtureRequest $anonymous 'GET' '/fixture.private.json'
    Assert-Fixture ($response.Status -eq 404) 'private runtime file cannot be fetched through router'
    $response = Send-FixtureRequest $anonymous 'POST' '/fixture/session' ([Net.Http.StringContent]::new('{"role":"owner"}',[Text.Encoding]::UTF8,'application/json'))
    Assert-Fixture ($response.Status -eq 403) 'fixture login endpoint rejects missing private token'
    $admin = New-FixtureClient
    $response = Send-FixtureRequest $admin 'POST' '/fixture/session' ([Net.Http.StringContent]::new('{"role":"admin"}',[Text.Encoding]::UTF8,'application/json')) $secret.token
    Assert-Fixture ($response.Status -eq 200) 'independent admin session created'
    $response = Send-FixtureRequest $admin 'GET' '/backup'
    Assert-Fixture ($response.Status -eq 403) 'admin denied despite core admin override'
    $owner = New-FixtureClient
    $response = Send-FixtureRequest $owner 'POST' '/fixture/session' ([Net.Http.StringContent]::new('{"role":"owner"}',[Text.Encoding]::UTF8,'application/json')) $secret.token
    Assert-Fixture ($response.Status -eq 200) 'independent owner session created'
    $response = Send-FixtureRequest $owner 'GET' '/backup'
    $csrf = Get-Csrf $response.Text
    Assert-Fixture ($response.Status -eq 200 -and ([regex]::Matches($response.Text,'name="csrf"')).Count -ge 3 -and $response.Text -match 'name="action" value="backup"' -and $response.Text -match 'name="action" value="verify"' -and $response.Text -match 'name="action" value="restore"') 'owner receives required backup, verify and restore forms with session CSRF'
    Assert-Fixture ($response.Headers['cache-control'] -match 'no-store' -and $response.Headers['x-content-type-options'] -eq 'nosniff') 'owner responses use private no-store and nosniff'
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Form-Content @{action='backup';csrf='incorrect';maintenance_confirmed='yes'})
    Assert-Fixture ($response.Status -eq 403 -and $response.Headers['content-type'] -ne 'application/zip') 'bad CSRF prevents backup download'
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Form-Content @{action='backup';csrf=$csrf})
    Assert-Fixture ($response.Status -eq 422) 'backup requires explicit maintenance acknowledgement'
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Form-Content @{action='backup';csrf=$csrf;maintenance_confirmed='yes'})
    Assert-Fixture ($response.Status -eq 200 -and $response.Headers['content-type'] -eq 'application/zip') 'owner downloads real ZIP through HTTP'
    $taskArchiveSize = $response.Bytes.Length
    $taskArchiveHash = Bytes-Sha256 $response.Bytes
    [byte[]]$archiveBytes = $response.Bytes
    Assert-Fixture ($taskArchiveHash -eq $response.Headers['x-backup-sha256']) 'HTTP ZIP bytes match X-Backup-SHA256'
    Assert-Fixture ([int64]$response.Headers['content-length'] -eq $archiveBytes.Length -and $response.Headers['content-disposition'] -match 'attachment') 'download length and attachment disposition match'
    $memory = [IO.MemoryStream]::new($archiveBytes,$false)
    $zip = [IO.Compression.ZipArchive]::new($memory,[IO.Compression.ZipArchiveMode]::Read,$false)
    try {
        $manifestEntry = $zip.GetEntry('manifest.json')
        $reader = [IO.StreamReader]::new($manifestEntry.Open(),[Text.Encoding]::UTF8)
        try { $manifest = $reader.ReadToEnd() | ConvertFrom-Json } finally { $reader.Dispose() }
        $taskArchiveEntries = $zip.Entries.Count
        $allHashes = $true
        foreach ($entryProperty in $manifest.entries.PSObject.Properties) {
            $entry = $zip.GetEntry($entryProperty.Name)
            if ($null -eq $entry -or $entry.Length -ne $entryProperty.Value.bytes) { $allHashes=$false; break }
            $stream = $entry.Open()
            $hash = [Security.Cryptography.SHA256]::Create()
            try { $actualHash=[BitConverter]::ToString($hash.ComputeHash($stream)).Replace('-','').ToLowerInvariant() } finally { $hash.Dispose(); $stream.Dispose() }
            if ($actualHash -ne $entryProperty.Value.sha256) { $allHashes=$false; break }
        }
        Assert-Fixture ($manifest.format -eq 'kosmoport-crm-backup' -and $manifest.tables.PSObject.Properties.Name.Count -eq 4 -and $zip.Entries.Count -eq 7) 'download contains real four-table manifest and two documents'
        Assert-Fixture $allHashes 'every downloaded table and document matches manifest SHA256'
    } finally { $zip.Dispose(); $memory.Dispose() }
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Upload-Content @{action='verify';csrf=$csrf} $archiveBytes)
    Assert-Fixture ($response.Status -eq 200 -and $response.Text -match 'Проверка пройдена') 'multipart ZIP upload is verified through actual PHP upload handling'
    $nextCsrf = Get-Csrf $response.Text
    Assert-Fixture ($nextCsrf -ne $csrf) 'successful verify rotates form CSRF'
    $csrf=$nextCsrf
    $state = Get-FixtureState
    Assert-Fixture ($state.source.rows_sha256 -eq $taskSourceState.rows_sha256 -and $state.target.counts.kcrm_users -eq 0 -and $state.target.file_count -eq 0) 'verification preserves source and empty target'
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Upload-Content @{action='verify';csrf=$csrf} ([Text.Encoding]::UTF8.GetBytes('corrupt ZIP fixture')))
    Assert-Fixture ($response.Status -eq 422) 'malformed uploaded archive rejected'
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Upload-Content @{action='restore';csrf=$csrf;target_database='incorrect_target_test';separate_target_confirmed='yes'} $archiveBytes)
    Assert-Fixture ($response.Status -eq 422) 'restore requires exact separate target database name'
    $state = Get-FixtureState
    Assert-Fixture ($state.target.counts.kcrm_users -eq 0 -and $state.target.file_count -eq 0) 'wrong-target refusal changes neither target rows nor files'
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Upload-Content @{action='restore';csrf=$csrf;target_database=$taskConfig.database_target;separate_target_confirmed='yes'} $archiveBytes)
    if ($response.Status -ne 200) {
        $notice=[regex]::Match($response.Text,'<p class="notice" role="status">(.*?)</p>')
        throw ('Restore returned HTTP '+$response.Status+': '+[Net.WebUtility]::HtmlDecode($notice.Groups[1].Value))
    }
    Assert-Fixture ($response.Status -eq 200 -and $response.Text -match 'Восстановлено в отдельную базу') 'multipart restore succeeds to explicitly confirmed empty target'
    $nextCsrf=Get-Csrf $response.Text
    Assert-Fixture ($nextCsrf -ne $csrf) 'successful restore rotates form CSRF'
    $csrf=$nextCsrf
    $state=Get-FixtureState
    $taskTargetState=$state.target
    Assert-Fixture ($state.source.rows_sha256 -eq $state.target.rows_sha256 -and $state.source.rows_sha256 -eq $taskSourceState.rows_sha256) 'restored SQL rows match source exactly and source remains unchanged'
    Assert-Fixture (($state.source.files | ConvertTo-Json -Compress) -eq ($state.target.files | ConvertTo-Json -Compress)) 'all restored file hashes match source'
    $response = Send-FixtureRequest $owner 'POST' '/backup' (Upload-Content @{action='restore';csrf=$csrf;target_database=$taskConfig.database_target;separate_target_confirmed='yes'} $archiveBytes)
    Assert-Fixture ($response.Status -eq 422) 'repeat restore refuses nonempty target'
    $state=Get-FixtureState
    Assert-Fixture ($state.target.rows_sha256 -eq $taskTargetState.rows_sha256 -and ($state.target.files | ConvertTo-Json -Compress) -eq ($taskTargetState.files | ConvertTo-Json -Compress)) 'repeat refusal preserves already restored rows and files'
    $auditPath=Join-Path $taskRuntime 'audit.jsonl'
    $audit=[IO.File]::ReadAllLines($auditPath) | ForEach-Object { $_ | ConvertFrom-Json }
    $taskAuditActions=@($audit | ForEach-Object { $_.action })
    Assert-Fixture (@($taskAuditActions | Where-Object { $_ -eq 'backup.created' }).Count -eq 1 -and @($taskAuditActions | Where-Object { $_ -eq 'backup.restored_to_separate_database' }).Count -eq 1 -and @($taskAuditActions | Where-Object { $_ -notin @('backup.created','backup.restore_to_separate_database_started','backup.restored_to_separate_database') }).Count -eq 0) 'successful backup and restore emit expected audit events'
} catch {
    $taskFailure=$_.Exception.Message
} finally {
    foreach ($client in $taskClients) { $client.Dispose() }
    if ($null -ne $taskServer) {
        $taskServer.Refresh()
        if (-not $taskServer.HasExited) { Stop-Process -Id $taskServer.Id -Force; $taskServer.WaitForExit(5000) | Out-Null }
        $taskServerStopped=$taskServer.HasExited
    }
    if ($taskStarted -or (Test-Path -LiteralPath $taskRuntime)) {
        $phpLog=Join-Path $taskRuntime 'php-error.log'
        if ([IO.File]::Exists($phpLog)) { $taskPhpDiagnostics=@([IO.File]::ReadAllLines($phpLog) | Select-Object -Last 8) }
        try { $cleanup=Invoke-FixtureCli 'cleanup'; $taskCleanup=$cleanup.ok -and $cleanup.fake_tables_removed -and $cleanup.runtime_removed } catch { if ($null -eq $taskFailure) { $taskFailure='Cleanup failed: '+$_.Exception.Message } }
    }
    $result=[ordered]@{
        ok=($null -eq $taskFailure -and $taskCleanup -and $taskServerStopped)
        scope='HTTP transport helper with frozen crm_* stubs and real isolated PDO MySQL; full core authentication and production hosting are not claimed'
        checked_at_utc=[DateTime]::UtcNow.ToString('o')
        server='PHP built-in HTTP server, loopback only, hidden'
        http_port=$taskPort
        mariadb_port=$taskConfig.port
        database_source=$taskConfig.database_source
        database_target=$taskConfig.database_target
        archive_bytes=$taskArchiveSize
        archive_sha256=$taskArchiveHash
        archive_entries=$taskArchiveEntries
        audit_actions=$taskAuditActions
        http_timeout_seconds=90
        fixture_php_max_execution_time_seconds=120
        php_diagnostics=$taskPhpDiagnostics
        http_timings=$taskHttpTimings
        checks=$taskChecks
        checks_passed=$taskChecks.Count
        fake_records_and_files_removed=$taskCleanup
        own_http_server_stopped=$taskServerStopped
        mariadb_server_kept_active=$true
        error=$taskFailure
    }
    [IO.File]::WriteAllText($taskResult,($result | ConvertTo-Json -Depth 10),[Text.UTF8Encoding]::new($false))
    [pscustomobject]@{ok=$result.ok;checks_passed=$taskChecks.Count;result=$taskResult;cleanup=$taskCleanup;http_stopped=$taskServerStopped;error=$taskFailure} | ConvertTo-Json -Compress
}
if ($null -ne $taskFailure -or -not $taskCleanup -or -not $taskServerStopped) { exit 1 }
