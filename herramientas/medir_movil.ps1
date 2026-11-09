<#
.SYNOPSIS
    Mide el recorte horizontal de cada ruta pública en anchos de teléfono.

.DESCRIPTION
    Levanta Chrome sin interfaz, lo pone en modo emulación de móvil a 320, 360,
    390 y 412 px, carga cada ruta y pide al navegador las coordenadas reales de
    cada elemento.

    ── POR QUÉ NO BASTA CON scrollWidth ────────────────────────────────────

    La primera versión comparaba `scrollWidth` con `clientWidth` y daba cero
    desborde en la portada. La portada estaba rota: el título, el buscador de
    radicado y el botón de acceso se salían del recuadro y quedaban cortados.

    El motivo es que un hijo de `grid` o `flex` tiene `min-width: auto` y no
    baja de su contenido mínimo; cuando un ancestro lleva `overflow-hidden`,
    ese exceso se recorta en vez de generar barra de desplazamiento. El
    documento no crece, así que `scrollWidth` no se entera. Lo único que lo
    delata es preguntar a cada elemento dónde termina.

    ── POR QUÉ NO BASTA CON --window-size ──────────────────────────────────

    Chrome sin interfaz no maqueta por debajo de unos 485 px con `--window-size`,
    ni siquiera en el modo nuevo. Para llegar a los anchos donde está la mayoría
    de los teléfonos hace falta emulación de dispositivo, y eso solo se activa
    por el protocolo DevTools. De ahí el rodeo por WebSocket.

.PARAMETER Base
    URL base de la aplicación. Por defecto http://localhost/botonvioleta

.EXAMPLE
    powershell -File herramientas/medir_movil.ps1
    powershell -File herramientas/medir_movil.ps1 -Base http://botonvioleta.test
#>
param(
    [string] $Base = "http://localhost/botonvioleta",
    [int[]]  $Anchos = @(320, 360, 390, 412),
    [string[]] $Rutas = @("home", "login", "recuperar", "denuncia-publica", "seguimiento", "portal")
)

$ErrorActionPreference = "Stop"

$chrome = @(
    "C:\Program Files\Google\Chrome\Application\chrome.exe",
    "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $chrome) {
    Write-Host "No se encontró Chrome ni Edge. Sin navegador no hay medición."
    exit 2
}

$puerto = 9227
$perfil = Join-Path $env:TEMP "bv_medir_movil"
if (Test-Path $perfil) { Remove-Item -Recurse -Force $perfil -ErrorAction SilentlyContinue }

$proc = Start-Process -FilePath $chrome -PassThru -WindowStyle Hidden -ArgumentList @(
    "--headless=new", "--disable-gpu", "--no-sandbox", "--disable-extensions",
    "--remote-debugging-port=$puerto", "--user-data-dir=$perfil", "about:blank"
)

try {
    # Esperar a que el protocolo responda
    $listo = $false
    for ($i = 0; $i -lt 20 -and -not $listo; $i++) {
        Start-Sleep -Milliseconds 500
        try { Invoke-WebRequest "http://127.0.0.1:$puerto/json/version" -UseBasicParsing -TimeoutSec 2 | Out-Null; $listo = $true } catch { }
    }
    if (-not $listo) { Write-Host "Chrome no abrió el protocolo DevTools."; exit 2 }

    $json = (Invoke-WebRequest "http://127.0.0.1:$puerto/json" -UseBasicParsing).Content | ConvertFrom-Json

    # PowerShell enumera los miembros de una colección: hay que extraer uno a uno
    $destinos = New-Object System.Collections.ArrayList
    foreach ($t in $json) {
        if ($t.type -eq 'page') { [void]$destinos.Add([string]$t.webSocketDebuggerUrl) }
    }
    if ($destinos.Count -eq 0) { Write-Host "Sin pestañas que controlar."; exit 2 }

    $sock = New-Object System.Net.WebSockets.ClientWebSocket
    $sock.ConnectAsync([Uri]$destinos[0], [Threading.CancellationToken]::None).Wait(10000) | Out-Null

    if ($sock.State -ne 'Open') { Write-Host "No se pudo abrir el WebSocket."; exit 2 }

    $script:id = 0

    function Enviar($metodo, $params) {
        $script:id++
        $msg = @{ id = $script:id; method = $metodo; params = $params } | ConvertTo-Json -Depth 10 -Compress
        $b = [Text.Encoding]::UTF8.GetBytes($msg)
        $sock.SendAsync((New-Object ArraySegment[byte] -ArgumentList @(, $b)), 'Text', $true,
                        [Threading.CancellationToken]::None).Wait(10000) | Out-Null

        $fin = [DateTime]::Now.AddSeconds(30)
        while ([DateTime]::Now -lt $fin) {
            $buf = New-Object byte[] 1048576
            $seg = New-Object ArraySegment[byte] -ArgumentList @(, $buf)
            $txt = ""
            do {
                $t = $sock.ReceiveAsync($seg, [Threading.CancellationToken]::None)
                if (-not $t.Wait(25000)) { return $null }
                $txt += [Text.Encoding]::UTF8.GetString($buf, 0, $t.Result.Count)
            } while (-not $t.Result.EndOfMessage)

            $o = $txt | ConvertFrom-Json
            if ($o.id -eq $script:id) { return $o }
        }
        return $null
    }

    # Se pregunta a cada elemento dónde termina, no al documento cuánto mide
    $medida = @'
JSON.stringify((function () {
  var vp = document.documentElement.clientWidth;
  var fuera = [];
  document.querySelectorAll('body *').forEach(function (el) {
    var r = el.getBoundingClientRect();
    if (r.width === 0 || r.right <= vp + 1) return;
    if (getComputedStyle(el).position === 'fixed') return;   // flota a propósito
    var p = el.parentElement;
    if (p && p.getBoundingClientRect().right > vp + 1) return;  // el padre ya se sale
    fuera.push({
      t: el.tagName.toLowerCase(),
      c: String(el.className).slice(0, 60),
      x: Math.round(r.right - vp),
      s: (el.textContent || '').trim().slice(0, 40)
    });
  });
  return { vp: vp, sw: document.documentElement.scrollWidth, fuera: fuera.slice(0, 6) };
})())
'@

    Write-Host ("=" * 67)
    Write-Host " MEDICIÓN EN ANCHOS DE TELÉFONO"
    Write-Host ("=" * 67)
    Write-Host ""

    $problemas = 0

    foreach ($w in $Anchos) {
        Enviar "Emulation.setDeviceMetricsOverride" @{
            width = $w; height = 780; deviceScaleFactor = 2; mobile = $true
        } | Out-Null

        Write-Host ("--- {0} px ---" -f $w)

        foreach ($ruta in $Rutas) {
            Enviar "Page.navigate" @{ url = "$Base/?page=$ruta" } | Out-Null
            Start-Sleep -Seconds 4

            $r = Enviar "Runtime.evaluate" @{ expression = $medida; returnByValue = $true }

            if ($null -eq $r -or $null -eq $r.result.result.value) {
                Write-Host ("  {0,-20} SIN MEDIR" -f $ruta)
                $problemas++
                continue
            }

            $d = $r.result.result.value | ConvertFrom-Json
            $scroll = $d.sw - $d.vp
            $n = @($d.fuera).Count

            if ($n -eq 0 -and $scroll -le 0) {
                Write-Host ("  {0,-20} OK" -f $ruta)
                continue
            }

            $problemas++
            Write-Host ("  {0,-20} {1} elemento(s) recortado(s), scroll +{2}px" -f $ruta, $n, [Math]::Max($scroll, 0))
            foreach ($e in $d.fuera) {
                # `<` sin comillas lo interpreta PowerShell como redirección
                Write-Host ("      +{0,-4}px  {1}  {2}" -f $e.x, $e.t, $e.s)
            }
        }
        Write-Host ""
    }

    Write-Host ("=" * 67)
    if ($problemas -eq 0) {
        Write-Host " Sin recortes en ninguna ruta ni ancho."
    } else {
        Write-Host (" {0} combinación(es) ruta/ancho con problemas." -f $problemas)
    }
    Write-Host ("=" * 67)

    $sock.Dispose()
    exit ($(if ($problemas -eq 0) { 0 } else { 1 }))
}
finally {
    if ($proc -and -not $proc.HasExited) { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue }
    Remove-Item -Recurse -Force $perfil -ErrorAction SilentlyContinue
}
