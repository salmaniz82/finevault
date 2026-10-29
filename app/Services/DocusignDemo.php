<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DocusignDemo
{
    public const NAME = 'Alexander Morgan';
    public const EMAIL = 'alexander.morgan@example.com';
    public const CLIENT_ID = 'fineworth-fictional-signer';

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        // Use an explicitly configured trusted bundle; never disable TLS checks.
        return Http::withOptions(['verify' => config('docusign.ca_bundle') ?: true]);
    }

    public function ready(): bool
    {
        return config('docusign.integration_key') && config('docusign.user_id') && config('docusign.account_id') && is_file(config('docusign.private_key_path'));
    }

    public function consentUrl(string $state): string
    {
        return 'https://account-d.docusign.com/oauth/auth?'.http_build_query([
            'response_type' => 'code', 'scope' => 'signature impersonation',
            'client_id' => config('docusign.integration_key'),
            'redirect_uri' => config('docusign.redirect_uri'), 'state' => $state,
        ]);
    }

    public function checkConnection(): void
    {
        $response = $this->http()->withToken($this->token())->timeout(20)->get('https://account-d.docusign.com/oauth/userinfo');
        if (! $response->successful() || ! collect($response->json('accounts', []))->contains(fn ($account) => ($account['account_id'] ?? null) === config('docusign.account_id'))) {
            throw new RuntimeException('The configured sandbox account is not accessible to this DocuSign user.');
        }
    }

    private function token(): string
    {
        if (! $this->ready()) {
            throw new RuntimeException('DocuSign sandbox credentials are not configured.');
        }
        return Cache::remember('docusign-demo-token-'.hash('sha256', config('docusign.integration_key').config('docusign.user_id')), 3000, function () {
            $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $jwt = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
                'iss' => config('docusign.integration_key'), 'sub' => config('docusign.user_id'),
                'aud' => 'account-d.docusign.com', 'iat' => time(), 'exp' => time() + 3600,
                'scope' => 'signature impersonation',
            ]));
            if (! openssl_sign($jwt, $signature, file_get_contents(config('docusign.private_key_path')), OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The sandbox signing key could not be loaded.');
            }
            $response = $this->http()->asForm()->timeout(20)->post('https://account-d.docusign.com/oauth/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt.'.'.$encode($signature),
            ]);
            if (! $response->successful()) {
                throw new RuntimeException($response->json('error') === 'consent_required'
                    ? 'DocuSign requires account consent. Use Connect DocuSign, then try again.'
                    : 'DocuSign sandbox authentication failed. Check the integration account and RSA key.');
            }
            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('DocuSign did not return an access token.');
            }
            return $token;
        });
    }

    private function api(string $method, string $path, array $body = [])
    {
        $response = $this->http()->withToken($this->token())->timeout(30)->send($method,
            'https://demo.docusign.net/restapi/v2.1/accounts/'.rawurlencode(config('docusign.account_id')).$path,
            $method === 'GET' ? [] : ['json' => $body]);
        if (! $response->successful()) {
            throw new RuntimeException('DocuSign could not complete the sandbox request (HTTP '.$response->status().'). Please retry or check the sandbox account configuration.');
        }
        return $response;
    }

    public function createEnvelope(): string
    {
        $response = $this->api('POST', '/envelopes', [
            'emailSubject' => 'DEMONSTRATION ONLY - Fineworth cask acquisition',
            'status' => 'sent',
            'documents' => [['documentBase64' => base64_encode(file_get_contents(resource_path('demo/fineworth-onboarding.pdf'))), 'name' => 'Fineworth sample acquisition agreement', 'fileExtension' => 'pdf', 'documentId' => '1']],
            'recipients' => ['signers' => [[
                'email' => self::EMAIL, 'name' => self::NAME, 'recipientId' => '1', 'clientUserId' => self::CLIENT_ID,
                'tabs' => [
                    'signHereTabs' => [['documentId' => '1', 'pageNumber' => '2', 'xPosition' => '55', 'yPosition' => '555']],
                    'dateSignedTabs' => [['documentId' => '1', 'pageNumber' => '2', 'xPosition' => '350', 'yPosition' => '555']],
                    // PDF coordinates are points from the top-left (page height 842).
                    // Keep names above their own lines, away from the signature label.
                    'fullNameTabs' => [
                        ['documentId' => '1', 'pageNumber' => '1', 'xPosition' => '155', 'yPosition' => '172', 'font' => 'Arial', 'fontSize' => 'Size11'],
                        ['documentId' => '1', 'pageNumber' => '2', 'xPosition' => '55', 'yPosition' => '635', 'font' => 'Arial', 'fontSize' => 'Size11'],
                    ],
                ],
            ]]],
        ]);
        $id = $response->json('envelopeId');
        if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            throw new RuntimeException('DocuSign did not return a valid envelope reference.');
        }
        return $id;
    }

    public function signingUrl(string $id, string $returnUrl): string
    {
        $url = $this->api('POST', '/envelopes/'.$id.'/views/recipient', [
            'returnUrl' => $returnUrl, 'authenticationMethod' => 'none',
            'email' => self::EMAIL, 'userName' => self::NAME, 'recipientId' => '1', 'clientUserId' => self::CLIENT_ID,
        ])->json('url');
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! preg_match('/(^|\.)docusign\.(net|com)$/i', parse_url($url, PHP_URL_HOST) ?? '')) {
            throw new RuntimeException('DocuSign returned an unexpected signing address.');
        }
        return $url;
    }

    public function status(string $id): string
    {
        return $this->api('GET', '/envelopes/'.$id)->json('status') ?? 'unknown';
    }

    public function completedPdf(string $id): string
    {
        if ($this->status($id) !== 'completed') {
            throw new RuntimeException('The signed PDF is available after DocuSign confirms completion.');
        }
        return $this->api('GET', '/envelopes/'.$id.'/documents/combined')->body();
    }
}
