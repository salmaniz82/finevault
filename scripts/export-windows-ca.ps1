$ErrorActionPreference = 'Stop'
# Export public certificates only from roots Windows already trusts. No private
# keys are exported, and no system trust or PHP configuration is modified.
$destination = Join-Path $PSScriptRoot '../storage/app/private/windows-ca-bundle.pem'
$certificates = @(Get-ChildItem Cert:\LocalMachine\Root, Cert:\CurrentUser\Root | Sort-Object Thumbprint -Unique)
if ($certificates.Count -eq 0) { throw 'No Windows trusted root certificates were found.' }
$bundle = foreach ($certificate in $certificates) {
    "-----BEGIN CERTIFICATE-----`n" + [Convert]::ToBase64String($certificate.RawData, [Base64FormattingOptions]::InsertLineBreaks) + "`n-----END CERTIFICATE-----"
}
[IO.File]::WriteAllText([IO.Path]::GetFullPath($destination), ($bundle -join "`n"), [Text.UTF8Encoding]::new($false))
Write-Output "Exported $($certificates.Count) public trusted roots to the private local CA bundle."
