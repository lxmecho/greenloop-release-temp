$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$edgeCandidates = @(
    'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    'C:\Program Files\Microsoft\Edge\Application\msedge.exe'
)

$edgePath = $edgeCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edgePath) {
    throw 'Microsoft Edge was not found. Cannot export PDFs.'
}

$documentNameBase64 = @(
    '572R56uZ6K6+6K6h5paH5qGjLm1k',
    '572R56uZ5L2/55So5paH5qGjLeeUqOaIt+eJiC5tZA==',
    '572R56uZ5L2/55So5paH5qGjLeeuoeeQhuWRmOeJiC5tZA=='
)

$documents = $documentNameBase64 | ForEach-Object {
    [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($_))
}

function Convert-InlineMarkdownToHtml {
    param([string] $text)

    $encoded = [System.Net.WebUtility]::HtmlEncode($text)
    $encoded = [regex]::Replace($encoded, '`([^`]+)`', '<code>$1</code>')
    $encoded = [regex]::Replace($encoded, '\[([^\]]+)\]\(([^)]+)\)', '<a href="$2">$1</a>')
    return $encoded
}

function Convert-MarkdownToHtmlBody {
    param([string] $markdown)

    $lines = $markdown -split "`r?`n"
    $builder = New-Object System.Text.StringBuilder
    $paragraph = New-Object System.Collections.Generic.List[string]
    $inCodeBlock = $false
    $inUl = $false
    $inOl = $false

    function Flush-Paragraph {
        param($paragraphRef, $builderRef)
        if ($paragraphRef.Count -gt 0) {
            $text = ($paragraphRef | ForEach-Object { Convert-InlineMarkdownToHtml $_ }) -join '<br>'
            [void]$builderRef.AppendLine("<p>$text</p>")
            $paragraphRef.Clear()
        }
    }

    function Close-Lists {
        param([ref]$inUlRef, [ref]$inOlRef, $builderRef)
        if ($inUlRef.Value) {
            [void]$builderRef.AppendLine('</ul>')
            $inUlRef.Value = $false
        }
        if ($inOlRef.Value) {
            [void]$builderRef.AppendLine('</ol>')
            $inOlRef.Value = $false
        }
    }

    foreach ($line in $lines) {
        if ($line -match '^\s*```') {
            Flush-Paragraph $paragraph $builder
            Close-Lists ([ref]$inUl) ([ref]$inOl) $builder
            if (-not $inCodeBlock) {
                [void]$builder.AppendLine('<pre><code>')
                $inCodeBlock = $true
            } else {
                [void]$builder.AppendLine('</code></pre>')
                $inCodeBlock = $false
            }
            continue
        }

        if ($inCodeBlock) {
            [void]$builder.AppendLine([System.Net.WebUtility]::HtmlEncode($line))
            continue
        }

        if ($line.Trim() -eq '') {
            Flush-Paragraph $paragraph $builder
            Close-Lists ([ref]$inUl) ([ref]$inOl) $builder
            continue
        }

        if ($line -match '^(#{1,6})\s+(.*)$') {
            Flush-Paragraph $paragraph $builder
            Close-Lists ([ref]$inUl) ([ref]$inOl) $builder
            $level = $matches[1].Length
            $content = Convert-InlineMarkdownToHtml $matches[2]
            [void]$builder.AppendLine("<h$level>$content</h$level>")
            continue
        }

        if ($line -match '^\s*[-*]\s+(.*)$') {
            Flush-Paragraph $paragraph $builder
            if ($inOl) {
                [void]$builder.AppendLine('</ol>')
                $inOl = $false
            }
            if (-not $inUl) {
                [void]$builder.AppendLine('<ul>')
                $inUl = $true
            }
            $content = Convert-InlineMarkdownToHtml $matches[1]
            [void]$builder.AppendLine("<li>$content</li>")
            continue
        }

        if ($line -match '^\s*\d+\.\s+(.*)$') {
            Flush-Paragraph $paragraph $builder
            if ($inUl) {
                [void]$builder.AppendLine('</ul>')
                $inUl = $false
            }
            if (-not $inOl) {
                [void]$builder.AppendLine('<ol>')
                $inOl = $true
            }
            $content = Convert-InlineMarkdownToHtml $matches[1]
            [void]$builder.AppendLine("<li>$content</li>")
            continue
        }

        $paragraph.Add($line.Trim())
    }

    Flush-Paragraph $paragraph $builder
    Close-Lists ([ref]$inUl) ([ref]$inOl) $builder

    return $builder.ToString()
}

function New-HtmlDocument {
    param(
        [string] $title,
        [string] $bodyHtml
    )

    return @"
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title</title>
    <style>
        @page {
            size: A4;
            margin: 18mm 16mm 18mm 16mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: "Microsoft YaHei", "PingFang SC", "Noto Sans SC", sans-serif;
            color: #1f2937;
            background: #ffffff;
            font-size: 14px;
            line-height: 1.72;
        }
        main {
            width: 100%;
        }
        h1, h2, h3, h4, h5, h6 {
            margin: 0 0 10px;
            line-height: 1.35;
            color: #0f172a;
            page-break-after: avoid;
        }
        h1 {
            font-size: 28px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dbe4f0;
            margin-bottom: 18px;
        }
        h2 {
            font-size: 22px;
            margin-top: 28px;
            padding-left: 10px;
            border-left: 4px solid #2f6fed;
        }
        h3 {
            font-size: 18px;
            margin-top: 20px;
        }
        p {
            margin: 0 0 12px;
            text-align: justify;
        }
        ul, ol {
            margin: 0 0 12px 22px;
            padding: 0;
        }
        li {
            margin: 0 0 6px;
        }
        code {
            font-family: Consolas, "Courier New", monospace;
            background: #f3f6fb;
            border: 1px solid #dbe4f0;
            border-radius: 4px;
            padding: 1px 5px;
            font-size: 0.95em;
        }
        pre {
            margin: 0 0 14px;
            padding: 12px 14px;
            background: #0f172a;
            color: #e5eefc;
            border-radius: 8px;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }
        pre code {
            background: transparent;
            border: 0;
            color: inherit;
            padding: 0;
        }
        a {
            color: #1858d3;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main>
        $bodyHtml
    </main>
</body>
</html>
"@
}

foreach ($document in $documents) {
    $sourcePath = Join-Path $workspace $document
    if (-not (Test-Path $sourcePath)) {
        throw "Document not found: $document"
    }

    $markdown = Get-Content $sourcePath -Encoding UTF8 -Raw
    $bodyHtml = Convert-MarkdownToHtmlBody -markdown $markdown
    $title = [System.IO.Path]::GetFileNameWithoutExtension($document)
    $html = New-HtmlDocument -title $title -bodyHtml $bodyHtml

    $htmlPath = Join-Path $workspace ($title + '.html')
    $pdfPath = Join-Path $workspace ($title + '.pdf')

    [System.IO.File]::WriteAllText($htmlPath, $html, [System.Text.UTF8Encoding]::new($false))

    $uri = 'file:///' + ($htmlPath -replace '\\', '/')
    $edgeArgs = @(
        '--headless',
        '--disable-gpu',
        '--allow-file-access-from-files',
        '--print-to-pdf-no-header',
        '--no-pdf-header-footer',
        "--print-to-pdf=$pdfPath",
        $uri
    )

    $process = Start-Process -FilePath $edgePath -ArgumentList $edgeArgs -PassThru -Wait -WindowStyle Hidden
    if ($process.ExitCode -ne 0) {
        throw "Edge PDF export failed for $document"
    }

    if (-not (Test-Path $pdfPath)) {
        throw "PDF was not created for $document"
    }

    Remove-Item -LiteralPath $htmlPath -Force -ErrorAction SilentlyContinue
}

Write-Output 'PDF export completed.'
