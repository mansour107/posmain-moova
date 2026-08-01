param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][string]$DataFile
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $DataFile -PathType Leaf)) {
    throw 'Print data file is unavailable.'
}

Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public static class PosmainRawPrinter {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public class DOC_INFO_1 {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }

    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)]
    public static extern bool OpenPrinter(string printerName, out IntPtr printer, IntPtr defaults);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool ClosePrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)]
    public static extern int StartDocPrinter(IntPtr printer, int level, [In] DOC_INFO_1 docInfo);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndDocPrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool StartPagePrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndPagePrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr printer, byte[] bytes, int count, out int written);
}
'@

$bytes = [System.IO.File]::ReadAllBytes($DataFile)
if ($bytes.Length -lt 1 -or $bytes.Length -gt 4194304) {
    throw 'Print data has an invalid size.'
}

$handle = [IntPtr]::Zero
if (-not [PosmainRawPrinter]::OpenPrinter($PrinterName, [ref]$handle, [IntPtr]::Zero)) {
    throw "Unable to open printer queue. Windows error: $([Runtime.InteropServices.Marshal]::GetLastWin32Error())"
}

$documentStarted = $false
$pageStarted = $false
try {
    $info = New-Object PosmainRawPrinter+DOC_INFO_1
    $info.pDocName = 'POSMAIN receipt'
    $info.pOutputFile = $null
    $info.pDataType = 'RAW'
    $jobId = [PosmainRawPrinter]::StartDocPrinter($handle, 1, $info)
    if ($jobId -le 0) {
        throw "Unable to start print job. Windows error: $([Runtime.InteropServices.Marshal]::GetLastWin32Error())"
    }
    $documentStarted = $true
    if (-not [PosmainRawPrinter]::StartPagePrinter($handle)) {
        throw "Unable to start print page. Windows error: $([Runtime.InteropServices.Marshal]::GetLastWin32Error())"
    }
    $pageStarted = $true
    $written = 0
    if (-not [PosmainRawPrinter]::WritePrinter($handle, $bytes, $bytes.Length, [ref]$written) -or $written -ne $bytes.Length) {
        throw "Unable to send complete print data. Windows error: $([Runtime.InteropServices.Marshal]::GetLastWin32Error())"
    }
    Write-Output $jobId
}
finally {
    if ($pageStarted) { [void][PosmainRawPrinter]::EndPagePrinter($handle) }
    if ($documentStarted) { [void][PosmainRawPrinter]::EndDocPrinter($handle) }
    if ($handle -ne [IntPtr]::Zero) { [void][PosmainRawPrinter]::ClosePrinter($handle) }
}
