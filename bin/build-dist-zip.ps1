param(
    [string]$OutputDir = "dist",
    [string]$ZipName = "localepilot.zip",
    [string]$PluginSlug = "localepilot"
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot

$DistIgnorePath = Join-Path $ProjectRoot ".distignore"
$ExcludedTopLevel = @()
if (Test-Path $DistIgnorePath) {
    $ExcludedTopLevel = Get-Content $DistIgnorePath |
        ForEach-Object { $_.Trim().TrimStart('/').TrimEnd('/') } |
        Where-Object { $_ -ne "" -and -not $_.StartsWith("#") }
}

$OutPath = Join-Path $ProjectRoot $OutputDir
if (-not (Test-Path $OutPath)) {
    New-Item -ItemType Directory -Path $OutPath -Force | Out-Null
}

$ZipFilePath = Join-Path $OutPath $ZipName
if (Test-Path $ZipFilePath) {
    Remove-Item -Path $ZipFilePath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Archive = [System.IO.Compression.ZipFile]::Open($ZipFilePath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $AllFiles = Get-ChildItem -Path $ProjectRoot -Recurse -File -Force
    foreach ($File in $AllFiles) {
        $RelPath = $File.FullName.Substring($ProjectRoot.Length).TrimStart('\', '/')
        $NormalizedRel = $RelPath -replace '\\', '/'
        $TopSegment = ($NormalizedRel -split '/')[0]

        if ($ExcludedTopLevel -contains $TopSegment -or $ExcludedTopLevel -contains $NormalizedRel) {
            continue
        }

        if ($File.Name -like "*.zip" -or $File.Name -like "*.log" -or $File.Name -eq ".DS_Store" -or $File.Name -eq "Thumbs.db") {
            continue
        }

        $EntryName = "$PluginSlug/$NormalizedRel"
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $Archive,
            $File.FullName,
            $EntryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $Archive.Dispose()
}

$VerifyArchive = [System.IO.Compression.ZipFile]::OpenRead($ZipFilePath)
try {
    $Entries = $VerifyArchive.Entries | Select-Object -ExpandProperty FullName
    $ForbiddenPatterns = @('vendor/', 'composer.json', 'composer.lock', 'composer.phar', 'wp-cli.phar', '.tools/', 'tests/', 'phpcs.xml.dist', 'phpunit.xml.dist', 'docker-compose.yml', 'bin/', '.github/')
    foreach ($Pattern in $ForbiddenPatterns) {
        $Matched = $Entries | Where-Object { $_ -like "*$Pattern*" }
        if ($Matched) {
            throw "Distribution ZIP validation failed: forbidden path '$Pattern' found in archive."
        }
    }

    $RequiredEntries = @(
        "$PluginSlug/localepilot.php",
        "$PluginSlug/uninstall.php",
        "$PluginSlug/includes/Autoloader.php",
        "$PluginSlug/includes/Compatibility.php",
        "$PluginSlug/includes/Environment.php",
        "$PluginSlug/includes/Plugin.php"
    )
    foreach ($Req in $RequiredEntries) {
        if ($Entries -notcontains $Req) {
            throw "Distribution ZIP validation failed: required file '$Req' missing from archive."
        }
    }

    Write-Output "BUILT_ZIP: $ZipFilePath"
    Write-Output "ENTRY_COUNT: $($Entries.Count)"
    foreach ($Entry in $Entries) {
        Write-Output "  - $Entry"
    }
}
finally {
    $VerifyArchive.Dispose()
}
