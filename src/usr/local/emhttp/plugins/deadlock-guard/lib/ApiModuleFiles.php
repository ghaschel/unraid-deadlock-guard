<?php
declare(strict_types=1);
namespace DeadlockGuard;

use RuntimeException;
use stdClass;

/** Install this dependency-free module without asking npm to rebuild Unraid's tree. */
final class ApiModuleFiles
{
    public const NAME = 'unraid-api-plugin-deadlock-guard';
    private string $target;
    private string $metadataPath;

    public function __construct(private string $base, private string $source)
    {
        $this->target = $base . '/node_modules/' . self::NAME;
        $this->metadataPath = $base . '/package.json';
    }

    public function assertSafe(): void
    {
        foreach ([$this->target, $this->metadataPath] as $path) {
            if (is_link($path)) {
                throw new RuntimeException(
                    'Cannot manage API module through a symbolic link: ' . $path,
                );
            }
        }
    }

    private function files(): array
    {
        $files = glob($this->source . '/*.mjs') ?: [];
        $files[] = $this->source . '/package.json';
        $metadata = Store::read($this->source . '/package.json');
        if (
            ($metadata['name'] ?? '') !== self::NAME ||
            ($metadata['main'] ?? '') !== 'index.mjs' ||
            !is_file($this->source . '/index.mjs')
        ) {
            throw new RuntimeException('Bundled API module metadata is invalid');
        }
        // Runtime dependencies are supplied by Unraid, never installed by this plugin.
        foreach (['dependencies', 'peerDependencies', 'optionalDependencies'] as $field) {
            if (!empty($metadata[$field])) {
                throw new RuntimeException('Bundled API module must not install dependencies');
            }
        }
        if (is_file(dirname($this->source) . '/LICENSE')) {
            $files[] = dirname($this->source) . '/LICENSE';
        }
        $contents = [];
        foreach ($files as $file) {
            if (
                is_link($file) ||
                !is_file($file) ||
                ($bytes = file_get_contents($file)) === false
            ) {
                throw new RuntimeException('Cannot read bundled API module file: ' . $file);
            }
            $contents[basename($file)] = $bytes;
        }
        return $contents;
    }

    private function metadata(): stdClass
    {
        $metadata = json_decode(
            file_get_contents($this->metadataPath),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        if (!($metadata instanceof stdClass)) {
            throw new RuntimeException('Unraid API package metadata must be an object');
        }
        foreach (['dependencies', 'peerDependencies'] as $field) {
            if (isset($metadata->$field) && !($metadata->$field instanceof stdClass)) {
                throw new RuntimeException('Invalid API package field: ' . $field);
            }
        }
        return $metadata;
    }

    public function matches(): bool
    {
        $this->assertSafe();
        $files = $this->files();
        foreach ($files as $name => $contents) {
            $path = $this->target . '/' . $name;
            if (is_link($path) || !is_file($path) || file_get_contents($path) !== $contents) {
                return false;
            }
        }
        $entries = array_diff(scandir($this->target), ['.', '..']);
        if (count($entries) !== count($files)) {
            return false;
        }
        $metadata = $this->metadata();
        return isset($metadata->peerDependencies->{self::NAME}) ||
            isset($metadata->dependencies->{self::NAME});
    }

    public function install(): void
    {
        $this->assertSafe();
        $files = $this->files();
        // Validate native metadata before touching the installed module.
        $this->metadata();
        if (!is_dir($this->target) && !mkdir($this->target, 0755, true)) {
            throw new RuntimeException('Cannot create API module directory');
        }
        foreach ($files as $name => $contents) {
            $this->write($this->target . '/' . $name, $contents, 0644);
        }
        foreach (array_diff(scandir($this->target), ['.', '..'], array_keys($files)) as $name) {
            $this->removePath($this->target . '/' . $name);
        }
        $this->updateMetadata(true);
        if (!$this->matches()) {
            throw new RuntimeException('Installed API module failed its integrity check');
        }
    }

    public function present(): bool
    {
        $this->assertSafe();
        if (file_exists($this->target)) {
            return true;
        }
        if (!is_file($this->metadataPath)) {
            return false;
        }
        $metadata = $this->metadata();
        return isset($metadata->peerDependencies->{self::NAME}) ||
            isset($metadata->dependencies->{self::NAME});
    }

    public function remove(): void
    {
        $this->assertSafe();
        if (is_file($this->metadataPath)) {
            $this->updateMetadata(false);
        }
        if (file_exists($this->target)) {
            $this->removePath($this->target);
        }
    }

    private function updateMetadata(bool $install): void
    {
        // Read just before writing, retaining unrelated fields and JSON object shapes.
        $metadata = $this->metadata();
        if ($install) {
            $field = isset($metadata->dependencies->{self::NAME})
                ? 'dependencies'
                : 'peerDependencies';
            $metadata->$field ??= new stdClass();
            $metadata->$field->{self::NAME} = 'file:' . dirname($this->source) . '/api-plugin.tgz';
        } else {
            unset($metadata->dependencies->{self::NAME}, $metadata->peerDependencies->{self::NAME});
        }
        $bytes =
            json_encode(
                $metadata,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
        $this->write($this->metadataPath, $bytes, fileperms($this->metadataPath) & 0777);
    }

    private function write(string $path, string $contents, int $mode): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (
                file_put_contents($temporary, $contents) !== strlen($contents) ||
                !chmod($temporary, $mode) ||
                !rename($temporary, $path)
            ) {
                throw new RuntimeException('Cannot write API module file: ' . $path);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function removePath(string $path): void
    {
        // Never follow links contained in our own module directory.
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $name) {
                $this->removePath($path . '/' . $name);
            }
            $removed = rmdir($path);
        } else {
            $removed = unlink($path);
        }
        if (!$removed) {
            throw new RuntimeException('Cannot remove API module file: ' . $path);
        }
    }
}
