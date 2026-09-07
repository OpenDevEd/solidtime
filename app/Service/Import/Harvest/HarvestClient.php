<?php

declare(strict_types=1);

namespace App\Service\Import\Harvest;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HarvestClient
{
    public const array COLLECTIONS = [
        'users', 'clients', 'projects', 'tasks', 'user_assignments',
        'task_assignments', 'time_entries', 'expenses', 'expense_categories',
    ];

    public function configuredFor(string $organizationId): bool
    {
        return filled(config('harvest.access_token'))
            && filled(config('harvest.account_id'))
            && config('harvest.organization_id') === $organizationId;
    }

    /** @return array<string, mixed> */
    public function company(): array
    {
        return $this->get('https://api.harvestapp.com/v2/company');
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function pages(string $collection): \Generator
    {
        if (! in_array($collection, self::COLLECTIONS, true)) {
            throw new RuntimeException('Unsupported Harvest collection.');
        }
        $url = 'https://api.harvestapp.com/v2/'.$collection.'?per_page=2000';
        $visited = [];
        $ids = [];
        $expected = null;
        while ($url !== null) {
            if (isset($visited[$url]) || count($visited) >= 10000) {
                throw new RuntimeException('Harvest returned invalid pagination.');
            }
            $visited[$url] = true;
            $page = $this->get($url);
            if (! is_array($page[$collection] ?? null)
                || ! array_key_exists('next', $page['links'] ?? [])
                || ! is_int($page['total_entries'] ?? null)) {
                throw new RuntimeException('Harvest returned an incomplete collection.');
            }
            $expected ??= $page['total_entries'];
            if ($expected !== $page['total_entries']) {
                throw new RuntimeException('Harvest data changed during collection. Start a new preview.');
            }
            foreach ($page[$collection] as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || isset($ids[$row['id']])) {
                    throw new RuntimeException('Harvest returned missing or duplicate source IDs.');
                }
                $ids[$row['id']] = true;
            }
            yield $page;
            $url = $page['links']['next'];
            if ($url !== null && ! is_string($url)) {
                throw new RuntimeException('Harvest returned invalid pagination.');
            }
        }
        if (count($ids) !== $expected) {
            throw new RuntimeException('Harvest collection count does not match its pages.');
        }
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'api.harvestapp.com'
            || ! str_starts_with($parts['path'] ?? '', '/v2/')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Harvest returned an unsafe pagination URL.');
        }
        try {
            $response = Http::withToken((string) config('harvest.access_token'))
                ->withHeaders([
                    'Harvest-Account-Id' => (string) config('harvest.account_id'),
                    'User-Agent' => 'Solidtime Harvest Import',
                ])
                ->acceptJson()->connectTimeout(10)->timeout(60)
                ->withoutRedirecting()->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Harvest. Start a new preview to retry.');
        }
        // Never include upstream bodies or credentials in a browser-facing error.
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'Harvest denied access. Check the server token and account permissions.',
                429 => 'Harvest rate limit reached. Wait before starting another preview.',
                default => 'Harvest request failed. Start a new preview to retry.',
            });
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Harvest returned invalid JSON.');
        }

        return $data;
    }
}
