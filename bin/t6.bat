@echo off
echo A
powershell -NoProfile -ExecutionPolicy Bypass -Command "$alvo = Get-CimInstance Win32_Process -Filter \"Name='php.exe' or Name='caddy.exe' or Name='mysqld.exe'\" | Where-Object { $_.ExecutablePath -like '*grape*portable*' -or $_.CommandLine -like '*websocket-server.php*' -or $_.CommandLine -like '*deploy*Caddyfile*' }; foreach ($proc in $alvo) { try { Stop-Process -Id $proc.ProcessId -Force -ErrorAction Stop; Write-Host ('  [limpo] porta liberada pelo processo ' + $proc.ProcessId) } catch {} }"
echo B
