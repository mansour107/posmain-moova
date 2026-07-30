<?php

require_once __DIR__ . '/ReleaseArtifactPolicy.php';

final class ReleaseArtifactBuilder
{
    private string $repositoryRoot;
    private ReleaseArtifactPolicy $policy;
    private string $gitBinary;

    public function __construct(
        string $repositoryRoot,
        ReleaseArtifactPolicy $policy,
        string $gitBinary = 'git'
    ) {
        $resolved = realpath($repositoryRoot);
        if (
            $resolved === false
            || (!is_dir($resolved . '/.git') && !is_file($resolved . '/.git'))
        ) {
            throw new InvalidArgumentException('Release repository must be a Git worktree.');
        }
        $this->repositoryRoot = $resolved;
        $this->policy = $policy;
        $this->gitBinary = $gitBinary;
    }

    /**
     * @return array{
     *   ok:bool,
     *   source_commit:string,
     *   source_commit_time:string,
     *   policy_version:int,
     *   included_count:int,
     *   excluded_count:int,
     *   blockers:list<array{code:string,path:string,message:string}>,
     *   included:list<string>,
     *   excluded:array<string,string>
     * }
     */
    public function preflight(string $ref): array
    {
        $commit = trim($this->git(['rev-parse', '--verify', $ref . '^{commit}']));
        if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw new RuntimeException('Unable to resolve an exact source commit for release.');
        }

        $tree = $this->treeEntries($commit);
        $paths = array_keys($tree);
        $evaluation = $this->policy->evaluate($paths);
        $blockers = $evaluation['blockers'];

        foreach ($tree as $path => $entry) {
            if ($entry['type'] !== 'blob' || !in_array($entry['mode'], ['100644', '100755'], true)) {
                $blockers[] = [
                    'code' => 'unsupported_git_entry',
                    'path' => $path,
                    'message' => 'Release artifacts may contain only regular committed files.',
                ];
            }
        }
        usort($blockers, static function (array $left, array $right): int {
            return [$left['code'], $left['path']] <=> [$right['code'], $right['path']];
        });

        $commitTime = trim($this->git(['show', '-s', '--format=%cI', $commit]));

        return [
            'ok' => $blockers === [],
            'source_commit' => $commit,
            'source_commit_time' => $commitTime,
            'policy_version' => $this->policy->version(),
            'included_count' => count($evaluation['included']),
            'excluded_count' => count($evaluation['excluded']),
            'blockers' => $blockers,
            'included' => $evaluation['included'],
            'excluded' => $evaluation['excluded'],
        ];
    }

    /**
     * @return array{
     *   artifact_path:string,
     *   manifest_path:string,
     *   artifact_sha256:string,
     *   manifest_sha256:string,
     *   source_commit:string,
     *   file_count:int
     * }
     */
    public function build(string $ref, string $outputDirectory): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to build a release artifact.');
        }

        $preflight = $this->preflight($ref);
        if (!$preflight['ok']) {
            $codes = array_map(
                static fn(array $blocker): string => $blocker['code'] . ':' . $blocker['path'],
                $preflight['blockers']
            );
            throw new RuntimeException('RELEASE_PREFLIGHT_BLOCKED ' . implode(', ', $codes));
        }

        $outputDirectory = rtrim($outputDirectory, '/\\');
        if ($outputDirectory === '') {
            throw new InvalidArgumentException('Release output directory is required.');
        }
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Unable to create release output directory.');
        }
        $outputReal = realpath($outputDirectory);
        if ($outputReal === false || !is_dir($outputReal)) {
            throw new RuntimeException('Unable to resolve release output directory.');
        }

        $commit = $preflight['source_commit'];
        $shortCommit = substr($commit, 0, 12);
        $artifactPath = $outputReal . '/posmain-' . $shortCommit . '.zip';
        $manifestPath = $outputReal . '/posmain-' . $shortCommit . '-release-manifest.json';
        $temporaryDirectory = $this->temporaryDirectory();
        $sourceTar = $temporaryDirectory . '/source.tar';

        try {
            $this->git(['archive', '--format=tar', '--output=' . $sourceTar, $commit]);
            $source = new PharData($sourceTar);
            $zip = new ZipArchive();
            if ($zip->open($artifactPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create release ZIP.');
            }

            $files = [];
            foreach ($preflight['included'] as $path) {
                if (!isset($source[$path])) {
                    $zip->close();
                    throw new RuntimeException('Committed source is missing from Git archive: ' . $path);
                }
                $contents = $source[$path]->getContent();
                if (!is_string($contents)) {
                    $zip->close();
                    throw new RuntimeException('Unable to read committed source: ' . $path);
                }
                if (!$zip->addFromString($path, $contents)) {
                    $zip->close();
                    throw new RuntimeException('Unable to add release file: ' . $path);
                }
                $this->normalizeZipEntry($zip, $path);
                $files[] = [
                    'path' => $path,
                    'size' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                ];
            }

            $dependencyLocks = $this->dependencyLockEvidence($source);
            $manifestCore = [
                'schema' => 'posmain.release-artifact.v1',
                'policy_version' => $preflight['policy_version'],
                'source_commit' => $commit,
                'source_commit_time' => $preflight['source_commit_time'],
                'dependency_locks' => $dependencyLocks,
                'file_count' => count($files),
                'files' => $files,
            ];
            $manifestCanonical = $this->json($manifestCore, false);
            $manifest = $manifestCore + [
                'manifest_sha256' => hash('sha256', $manifestCanonical),
            ];
            $manifestJson = $this->json($manifest, true) . "\n";

            if (!$zip->addFromString('release-manifest.json', $manifestJson)) {
                $zip->close();
                throw new RuntimeException('Unable to embed release manifest.');
            }
            $this->normalizeZipEntry($zip, 'release-manifest.json');
            if (!$zip->close()) {
                throw new RuntimeException('Unable to finalize release ZIP.');
            }

            if (file_put_contents($manifestPath, $manifestJson, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write release manifest sidecar.');
            }

            return [
                'artifact_path' => $artifactPath,
                'manifest_path' => $manifestPath,
                'artifact_sha256' => hash_file('sha256', $artifactPath),
                'manifest_sha256' => (string) $manifest['manifest_sha256'],
                'source_commit' => $commit,
                'file_count' => count($files),
            ];
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    /**
     * @return array<string,array{mode:string,type:string,object:string}>
     */
    private function treeEntries(string $commit): array
    {
        $raw = $this->git(['ls-tree', '-rz', $commit]);
        $entries = [];
        foreach (explode("\0", $raw) as $record) {
            if ($record === '') {
                continue;
            }
            if (preg_match('/^([0-9]{6}) ([a-z]+) ([0-9a-f]{40})\t(.+)$/s', $record, $matches) !== 1) {
                throw new RuntimeException('Unable to parse Git tree entry.');
            }
            $path = str_replace('\\', '/', $matches[4]);
            $entries[$path] = [
                'mode' => $matches[1],
                'type' => $matches[2],
                'object' => $matches[3],
            ];
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    private function dependencyLockEvidence(PharData $source): array
    {
        $locks = [];
        foreach (['composer.lock', 'package-lock.json'] as $path) {
            if (!isset($source[$path])) {
                continue;
            }
            $contents = $source[$path]->getContent();
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read dependency lock: ' . $path);
            }
            $locks[] = [
                'path' => $path,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        }

        return $locks;
    }

    private function normalizeZipEntry(ZipArchive $zip, string $path): void
    {
        if (method_exists($zip, 'setMtimeName') && !$zip->setMtimeName($path, 315532800)) {
            throw new RuntimeException('Unable to normalize release timestamp: ' . $path);
        }
        if (!$zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9)) {
            throw new RuntimeException('Unable to normalize release compression: ' . $path);
        }
    }

    private function json(array $value, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($value, $flags);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode release manifest.');
        }

        return $json;
    }

    private function temporaryDirectory(): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\')
            . '/posmain-release-' . bin2hex(random_bytes(12));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create temporary release directory.');
        }

        return $path;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $temporaryRoot = rtrim(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), '/\\') . '/';
        $resolved = realpath($directory);
        if ($resolved === false || !str_starts_with($resolved . '/', $temporaryRoot . 'posmain-release-')) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($resolved);
    }

    private function git(array $arguments): string
    {
        $command = array_merge([$this->gitBinary, '-C', $this->repositoryRoot], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Git.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !is_string($stdout)) {
            throw new RuntimeException(
                'Git command failed: ' . trim(is_string($stderr) ? $stderr : '')
            );
        }

        return $stdout;
    }
}
