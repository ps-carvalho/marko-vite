<?php

declare(strict_types=1);

namespace Marko\Vite;

use Marko\Config\ConfigRepositoryInterface;
use Marko\Core\Path\ProjectPaths;
use Throwable;

readonly class Vite
{
    public function __construct(
        private ConfigRepositoryInterface $config,
        private ProjectPaths $paths,
    ) {}

    /**
     * Generate Vite script/link tags for the HTML head.
     */
    public function headTags(string $entry = 'app/web/resources/js/app.js'): string
    {
        if ($this->useDevServer()) {
            return $this->devServerTags($entry);
        }

        return $this->manifestTags($entry);
    }

    /**
     * Check if Vite dev server should be used.
     */
    public function useDevServer(): bool
    {
        try {
            return $this->config->getBool('vite.useDevServer');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tags for Vite dev server (hot module replacement).
     */
    private function devServerTags(string $entry): string
    {
        $url = rtrim($this->config->getString('vite.devServerUrl'), '/');
        $tags = '';

        foreach ($this->devServerStylesheets() as $stylesheet) {
            $stylesheet = ltrim($stylesheet, '/');
            $tags .= "<link rel=\"stylesheet\" href=\"{$url}/{$stylesheet}\">\n";
        }

        if ($this->isReactEntry($entry)) {
            $tags .= $this->reactRefreshPreamble($url)."\n";
        }

        $tags .= <<<HTML
<script type="module" src="{$url}/@vite/client"></script>
<script type="module" src="{$url}/{$entry}"></script>
HTML;

        return $tags;
    }

    /**
     * React Fast Refresh expects this preamble when Vite is not serving the
     * application HTML itself.
     */
    private function reactRefreshPreamble(string $url): string
    {
        return <<<HTML
<script type="module">
import { injectIntoGlobalHook } from "{$url}/@react-refresh";
injectIntoGlobalHook(window);
window.\$RefreshReg\$ = () => {};
window.\$RefreshSig\$ = () => (type) => type;
</script>
HTML;
    }

    private function isReactEntry(string $entry): bool
    {
        return str_ends_with($entry, '.jsx') || str_ends_with($entry, '.tsx');
    }

    /**
     * Stylesheets served directly by Vite in dev mode.
     *
     * @return list<string>
     */
    private function devServerStylesheets(): array
    {
        try {
            $stylesheets = $this->config->getArray('vite.devServerStylesheets');
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $stylesheets,
            static fn (mixed $stylesheet): bool => is_string($stylesheet) && $stylesheet !== '',
        ));
    }

    /**
     * Tags from production build manifest.
     */
    private function manifestTags(string $entry): string
    {
        $manifestPath = $this->manifestPath();

        if (! is_file($manifestPath)) {
            return '<!-- Vite manifest not found. Run: npm run build -->';
        }

        $manifestContents = file_get_contents($manifestPath);
        if ($manifestContents === false) {
            return '<!-- Vite manifest is invalid -->';
        }

        $manifest = $this->decodeManifest($manifestContents);
        if ($manifest === null) {
            return '<!-- Vite manifest is invalid -->';
        }

        $buildDir = $this->config->getString('vite.buildDirectory');
        $basePath = '/'.trim($buildDir, '/').'/';

        $entryData = $manifest[$entry] ?? null;
        if (! is_array($entryData)) {
            return "<!-- Vite entry '{$entry}' not found in manifest -->";
        }

        $entryFile = $entryData['file'] ?? null;
        if (! is_string($entryFile) || $entryFile === '') {
            return "<!-- Vite entry '{$entry}' is invalid -->";
        }

        $importedChunks = $this->importedChunks($manifest, $entryData);
        $stylesheets = [];

        foreach (array_merge([$entryData], $importedChunks) as $chunk) {
            if (! isset($chunk['css']) || ! is_array($chunk['css'])) {
                continue;
            }

            foreach ($chunk['css'] as $css) {
                if (! is_string($css) || $css === '') {
                    continue;
                }

                $stylesheets[$css] = "<link rel=\"stylesheet\" href=\"{$basePath}{$css}\">";
            }
        }

        $tags = array_values($stylesheets);
        $tags[] = $this->entryTag($basePath, $entryFile);

        foreach ($importedChunks as $chunk) {
            $chunkFile = $chunk['file'] ?? null;

            if (! is_string($chunkFile) || $chunkFile === '' || str_ends_with($chunkFile, '.css')) {
                continue;
            }

            $tags[] = "<link rel=\"modulepreload\" href=\"{$basePath}{$chunkFile}\">";
        }

        return implode("\n", $tags);
    }

    /**
     * Resolve the absolute path to the Vite manifest file.
     */
    private function manifestPath(): string
    {
        $buildDir = $this->config->getString('vite.buildDirectory');
        $manifestFilename = $this->config->getString('vite.manifestFilename');

        return $this->paths->base.'/public/'.trim($buildDir, '/').'/'.$manifestFilename;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function decodeManifest(string $manifestContents): ?array
    {
        $manifest = json_decode($manifestContents, true);

        if (! is_array($manifest)) {
            return null;
        }

        $normalizedManifest = [];

        foreach ($manifest as $key => $chunk) {
            if (! is_string($key)) {
                return null;
            }

            $normalizedChunk = $this->normalizeChunk($chunk);

            if ($normalizedChunk === null) {
                return null;
            }

            $normalizedManifest[$key] = $normalizedChunk;
        }

        return $normalizedManifest;
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     * @param array<string, mixed> $entryData
     * @return list<array<string, mixed>>
     */
    private function importedChunks(array $manifest, array $entryData): array
    {
        $seen = [];

        return array_values($this->collectImportedChunks($manifest, $entryData, $seen));
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     * @param array<string, mixed> $chunk
     * @param array<string, true> $seen
     * @return array<string, array<string, mixed>>
     */
    private function collectImportedChunks(array $manifest, array $chunk, array &$seen): array
    {
        $imports = $chunk['imports'] ?? null;
        $chunks = [];

        if (! is_array($imports)) {
            return $chunks;
        }

        foreach ($imports as $import) {
            if (! is_string($import) || isset($seen[$import])) {
                continue;
            }

            $importedChunk = $manifest[$import] ?? null;

            if (! is_array($importedChunk)) {
                continue;
            }

            $seen[$import] = true;
            $chunks[$import] = $importedChunk;

            foreach ($this->collectImportedChunks($manifest, $importedChunk, $seen) as $nestedImport => $nestedChunk) {
                $chunks[$nestedImport] = $nestedChunk;
            }
        }

        return $chunks;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeChunk(mixed $chunk): ?array
    {
        if (! is_array($chunk)) {
            return null;
        }

        $normalizedChunk = [];

        foreach ($chunk as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $normalizedChunk[$key] = $value;
        }

        return $normalizedChunk;
    }

    private function entryTag(string $basePath, string $entryFile): string
    {
        if (str_ends_with($entryFile, '.css')) {
            return "<link rel=\"stylesheet\" href=\"{$basePath}{$entryFile}\">";
        }

        return "<script type=\"module\" src=\"{$basePath}{$entryFile}\"></script>";
    }
}
