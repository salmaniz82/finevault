<?php

namespace Tests\Feature;

use App\Models\PortalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectSigningTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $image = imagecreatetruecolor(800, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 30, 80, 'Alexander Morgan', imagecolorallocate($image, 20, 30, 20));
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        return ['token' => (string) Str::uuid(), 'name' => 'Alexander Morgan', 'method' => 'type', 'consent' => 1,
            'signature' => 'data:image/png;base64,'.base64_encode($png),
            'source_sha256' => app(\App\Services\DirectSignature::class)->hotspots()['source_sha256']];
    }

    public function test_saves_real_pdf_once_and_scopes_downloads_to_owner(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $data = $this->payload();
        $this->actingAs($admin)->withSession(['direct_signing.'.$admin->id => $data['token']]);
        $this->get('/direct-signing-demo')->assertOk()->assertSee('A signature, simply.');
        $this->post('/direct-signing-demo/save', $data)->assertRedirect('/direct-signing-demo')->assertSessionHasNoErrors();
        $record = PortalRecord::where('kind', 'direct_signature_demo')->sole();
        $pdf = Storage::disk('local')->get($record->data['path']);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(hash('sha256', $pdf), $record->data['signed_sha256']);
        $parser = new \setasign\Fpdi\Fpdi();
        $this->assertSame($record->data['signing_hotspots']['page_count'], $parser->setSourceFile(Storage::disk('local')->path($record->data['path'])));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->post('/direct-signing-demo/save', $data)->assertRedirect('/direct-signing-demo');
        $this->assertSame(1, PortalRecord::where('kind', 'direct_signature_demo')->count());
        $this->get('/direct-signing-demo')->assertSee('Your agreement is complete.');
        $this->get('/direct-signing-demo/'.$record->id.'/download')->assertOk();
        $this->post('/direct-signing-demo/reset')->assertRedirect('/direct-signing-demo');
        $this->get('/direct-signing-demo')->assertSee('Awaiting signature')->assertSee('Saved demonstrations');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/direct-signing-demo/'.$record->id.'/download')->assertNotFound();
    }

    public function test_rejects_invalid_or_blank_images_consent_and_stale_documents(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $data = $this->payload();
        $this->actingAs($admin)->withSession(['direct_signing.'.$admin->id => $data['token']]);
        foreach ([['consent' => 0], ['signature' => 'data:image/png;base64,invalid'], ['source_sha256' => str_repeat('0', 64)]] as $change) {
            $this->post('/direct-signing-demo/save', array_replace($data, $change))->assertSessionHasErrors();
            $this->assertSame(0, PortalRecord::where('kind', 'direct_signature_demo')->count());
            $this->assertCount(0, Storage::disk('local')->allFiles());
        }
        $image = imagecreatetruecolor(800, 200); imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start(); imagepng($image); $blank = ob_get_clean(); imagedestroy($image);
        $this->post('/direct-signing-demo/save', array_replace($data, ['signature' => 'data:image/png;base64,'.base64_encode($blank)]))->assertSessionHasErrors('signature');
    }

    public function test_all_endpoints_require_admin_and_token_is_not_forgeable(): void
    {
        $this->get('/direct-signing-demo')->assertRedirect('/login');
        foreach (['client', 'manager'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['', '/sample', '/1/download'] as $path) $this->get('/direct-signing-demo'.$path)->assertForbidden();
            foreach (['save', 'reset'] as $path) $this->post('/direct-signing-demo/'.$path)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/direct-signing-demo/save', $this->payload())->assertStatus(419);
    }

    public function test_extended_sample_signs_on_measured_page_and_ignores_forged_coordinates(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/direct-signing-demo/reset', ['variant' => 'extended'])->assertRedirect();
        $data = $this->payload();
        $data['token'] = session('direct_signing.'.$admin->id);
        $hotspots = app(\App\Services\DirectSignature::class)->hotspots('extended');
        $data['source_sha256'] = $hotspots['source_sha256'];
        $data += ['page' => 1, 'x' => .99, 'y' => .99, 'width' => 1];
        $this->post('/direct-signing-demo/save', $data)->assertSessionHasNoErrors();
        $record = PortalRecord::where('kind', 'direct_signature_demo')->sole();
        $this->assertSame($hotspots, $record->data['signing_hotspots']);
        $parser = new \setasign\Fpdi\Fpdi();
        $this->assertSame($hotspots['page_count'], $parser->setSourceFile(Storage::disk('local')->path($record->data['path'])));
        $this->assertGreaterThan(1, $hotspots['fields']['signature']['page']);
    }
}
