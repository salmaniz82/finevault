<?php

namespace App\Http\Controllers;

use App\Services\DocusignDemo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class SigningDemoController extends Controller
{
    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
        if ($request->session()->get('docusign_demo.owner') !== $request->user()->id) {
            $request->session()->forget('docusign_demo');
            $request->session()->put('docusign_demo.owner', $request->user()->id);
        }
    }

    public function show(Request $request, DocusignDemo $demo)
    {
        $this->admin($request);
        return view('signing-demo', ['title' => 'Signing demonstration', 'ready' => $demo->ready(), 'status' => $request->session()->get('docusign_demo.status', 'Not started')]);
    }

    public function sample(Request $request)
    {
        $this->admin($request);
        return response()->file(resource_path('demo/fineworth-onboarding.pdf'), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="fineworth-sample-agreement.pdf"']);
    }

    public function connect(Request $request, DocusignDemo $demo)
    {
        $this->admin($request);
        $request->session()->put('docusign_demo.consent_state', $state = Str::random(48));
        return redirect()->away($demo->consentUrl($state));
    }

    public function consent(Request $request)
    {
        $this->admin($request);
        $state = $request->session()->get('docusign_demo.consent_state');
        abort_unless(is_string($state) && is_string($request->query('state')) && hash_equals($state, $request->query('state')), 403);
        $request->session()->forget('docusign_demo.consent_state');
        // JWT consent is granted at DocuSign; the authorization code is deliberately not stored.
        return redirect('/signing-demo')->with($request->has('error') ? 'errors' : 'success', $request->has('error')
            ? new \Illuminate\Support\MessageBag(['docusign' => 'DocuSign consent was not granted. You can connect again.'])
            : 'DocuSign consent returned. You can now start the sample signing.');
    }

    public function sign(Request $request, DocusignDemo $demo)
    {
        $this->admin($request);
        try {
            $id = $request->session()->get('docusign_demo.envelope');
            if (! $id) {
                $id = $demo->createEnvelope();
                $request->session()->put('docusign_demo.envelope', $id);
            } elseif ($demo->status($id) === 'completed') {
                $request->session()->put('docusign_demo.status', 'completed');
                return redirect('/signing-demo')->with('success', 'This agreement is already signed. Download it below or reset the demonstration.');
            }
            $request->session()->put('docusign_demo.return_state', $state = Str::random(48));
            return redirect()->away($demo->signingUrl($id, url('/signing-demo/return').'?state='.$state));
        } catch (RuntimeException|\Illuminate\Http\Client\ConnectionException $e) {
            return redirect('/signing-demo')->withErrors(['docusign' => $e instanceof RuntimeException ? $e->getMessage() : 'DocuSign could not be reached. Try again shortly.']);
        }
    }

    public function refresh(Request $request, DocusignDemo $demo)
    {
        $this->admin($request);
        if ($request->isMethod('get')) {
            $state = $request->session()->get('docusign_demo.return_state');
            abort_unless(is_string($state) && is_string($request->query('state')) && hash_equals($state, $request->query('state')), 403);
            $request->session()->forget('docusign_demo.return_state');
        }
        $id = $request->session()->get('docusign_demo.envelope');
        abort_unless($id, 404);
        try {
            // Never trust the browser's event=signing_complete parameter.
            $request->session()->put('docusign_demo.status', $demo->status($id));
            return redirect('/signing-demo')->with('success', 'Agreement status checked with DocuSign.');
        } catch (RuntimeException|\Illuminate\Http\Client\ConnectionException $e) {
            return redirect('/signing-demo')->withErrors(['docusign' => 'Unable to verify signing status. Use Refresh status to try again.']);
        }
    }

    public function download(Request $request, DocusignDemo $demo)
    {
        $this->admin($request);
        abort_unless($id = $request->session()->get('docusign_demo.envelope'), 404);
        try {
            return response($demo->completedPdf($id), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="fineworth-demo-signed.pdf"']);
        } catch (RuntimeException|\Illuminate\Http\Client\ConnectionException $e) {
            return redirect('/signing-demo')->withErrors(['docusign' => 'The completed PDF is not available yet. Refresh the signing status and try again.']);
        }
    }

    public function reset(Request $request)
    {
        $this->admin($request);
        $request->session()->forget('docusign_demo');
        return redirect('/signing-demo')->with('success', 'Demo reset. The next signing creates a new sandbox envelope; earlier envelopes remain in the DocuSign sandbox.');
    }
}
