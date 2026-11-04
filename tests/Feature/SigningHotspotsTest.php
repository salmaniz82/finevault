<?php

namespace Tests\Feature;

use App\Services\SigningDocument;
use Tests\TestCase;

class SigningHotspotsTest extends TestCase
{
    public function test_signing_block_follows_content_and_stays_together(): void
    {
        $renderer = app(SigningDocument::class);
        $short = $renderer->generate(view('agreements.direct-demo', ['extended' => false])->render());
        $long = $renderer->generate(view('agreements.direct-demo', ['extended' => true])->render());
        $this->assertGreaterThan($short['hotspots']['fields']['signature']['page'], $long['hotspots']['fields']['signature']['page']);
        foreach ([$short, $long] as $result) {
            $map = $result['hotspots'];
            $this->assertCount(3, $map['fields']);
            $this->assertCount(1, array_unique(array_column($map['fields'], 'page')));
            $this->assertSame(hash('sha256', $result['pdf']), $map['source_sha256']);
            $this->assertLessThan($map['page_height'] - 40, $map['fields']['full_name']['y'] + $map['fields']['full_name']['height']);
        }
    }

    public function test_missing_hotspots_fail_generation(): void
    {
        $this->expectException(\RuntimeException::class);
        app(SigningDocument::class)->generate('<html><body>No signing block</body></html>');
    }

    public function test_hash_mismatch_rejects_hotspots_from_another_document(): void
    {
        $renderer = app(SigningDocument::class);
        $result = $renderer->generate(view('agreements.direct-demo', ['extended' => false])->render());
        $this->expectException(\RuntimeException::class);
        $renderer->validate($result['hotspots'], $result['pdf'].'changed');
    }
}
