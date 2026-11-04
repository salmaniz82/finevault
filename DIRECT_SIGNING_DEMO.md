# Direct signing demonstration

Open **Direct signing demo** in the admin dashboard (`/direct-signing-demo`).

1. Choose Standard or Extended under Sample length and click Load sample. This starts a new demonstration and clears unsaved signature input.
2. Review the PDF. Go to signing fields opens the measured signing page: page 1 for Standard, page 4 for Extended.
3. Type your name and select one of eight styles, draw, or upload a PNG/JPG up to 2 MB.
4. Signature, full name and date fill the highlighted hotspots automatically. Confirm demonstration consent and save.
5. Completion follows successful private PDF storage. Download it or start another demonstration; previous signed copies remain retained.

No external signing account or paid service is used. This is an admin-only visual-signature demo, not certificate signing or identity verification. Live client deal signing remains the existing sandbox workflow.

## Template contract

Both generated samples and deal agreements include `resources/views/agreements/signing-block.blade.php`. Its three elements are tagged `data-signing-field` with `signature`, `full_name`, and `signed_date`. The block stays together across page breaks. Existing DocuSign text anchors are preserved.

`SigningDocument::generate(html)` returns `pdf` and `hotspots`. Dompdf callbacks capture final rendered field boxes after layout, so there is no fixed signing page or assumed vertical position. The versioned map specifies top-left PDF points, page sizes/count and a SHA-256 binding to the exact source PDF. Missing, duplicate, split, overlapping or out-of-page fields fail generation.

The live deal generator persists the map in `document.data.signing_hotspots` alongside the private PDF. To integrate direct signing later, authorise the document first, load that map and unchanged PDF, and call `DirectSignature::render(input, imagePath, utcTime, sourcePath, manifest)`. Do not accept positions from clients or infer them for older PDFs. Preserve the existing signed-revision approval process. This task prepares the generator; it does not enable live client direct signing.

New PDFs use template v3. Already generated PDFs and signed copies are not rewritten. Demo source PDFs and `.pdf.json` sidecars are committed under `resources/demo`; rebuild both using `php artisan signing:build-samples` after template changes. A stale browser must reload/review before signing a rebuilt PDF.

## Dependencies and checks

Run `composer install`; enable PHP GD. PDF.js and signature fonts are locally served committed assets, so no Node build is required for this screen. npm dependencies are pinned with licences copied alongside browser assets.

`php artisan test --compact` verifies generation, pagination, hash binding, missing-hotspot failure, private ownership, duplicate-save handling, forged-coordinate rejection and existing portal/DocuSign flows. On 4 November 2026: 43 tests, 412 assertions passed. Browser check: extended PDF showed all three fields on page 4 and saved successfully.
