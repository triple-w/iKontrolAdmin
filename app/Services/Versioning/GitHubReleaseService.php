<?php

namespace App\Services\Versioning;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubReleaseService
{
    public function discover(): array
    {
        $releases = [];
        for ($page = 1; $page <= 10; $page++) {
            $items = $this->client()->get($this->apiUrl('/releases'), ['per_page' => 100, 'page' => $page])->throw()->json();
            if (! is_array($items)) throw new RuntimeException('GitHub devolvió una lista de releases inválida.');
            foreach ($items as $item) if (is_array($item) && ! ($item['draft'] ?? false)) $releases[] = $item;
            if (count($items) < 100) break;
        }
        return $releases;
    }

    public function commitSha(string $tag): string
    {
        $this->assertTag($tag);
        $sha = $this->client()->get($this->apiUrl('/commits/'.rawurlencode($tag)))->throw()->json('sha');
        if (! is_string($sha) || ! preg_match('/\A[a-f0-9]{40}\z/i', $sha)) throw new RuntimeException('GitHub no devolvió un commit SHA válido.');
        return strtolower($sha);
    }

    public function manifest(string $version, string $tag): string
    {
        if (! preg_match('/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?(?:\+[0-9A-Za-z.]+)?\z/', $version)) throw new RuntimeException('Versión insegura para consultar el manifest.');
        $this->assertTag($tag);
        $response = $this->client()->get($this->apiUrl('/contents/updates/'.rawurlencode($version).'/manifest.json'), ['ref' => $tag])->throw()->json();
        if (! is_array($response) || ($response['encoding'] ?? null) !== 'base64' || ! is_string($response['content'] ?? null)) throw new RuntimeException('GitHub devolvió un manifest inválido.');
        $decoded = base64_decode(str_replace(["\r", "\n"], '', $response['content']), true);
        if ($decoded === false) throw new RuntimeException('No fue posible decodificar el manifest.');
        return $decoded;
    }

    public function repository(): string
    {
        $repository = (string) config('ikontrol.releases.repository');
        if (! preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repository)) throw new RuntimeException('Repositorio GitHub inválido.');
        return $repository;
    }

    private function client(): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout(20)->connectTimeout(10)->withHeaders([
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'iKontrolAdmin-VersionManager',
        ]);
        $token = (string) config('ikontrol.releases.github_token');
        return $token !== '' ? $request->withToken($token) : $request;
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('ikontrol.releases.api_url', 'https://api.github.com'), '/').'/repos/'.$this->repository().$path;
    }

    private function assertTag(string $tag): void
    {
        if (! preg_match('/\Av?\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?\z/', $tag)) throw new RuntimeException('Tag GitHub inválido.');
    }
}
