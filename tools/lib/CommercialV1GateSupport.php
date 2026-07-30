<?php

final class CommercialV1GateSupport
{
    public static function evidenceDirectory(string $root, array $options): string
    {
        $configured = trim((string) ($options['evidence-dir'] ?? getenv('POSMAIN_EVIDENCE_DIR') ?: ''));
        if ($configured === '') {
            return $root . '/var/evidence/posmain-commercial-v1';
        }

        return $configured[0] === '/'
            ? $configured
            : $root . '/' . ltrim($configured, '/');
    }

    /**
     * @param list<string> $arguments
     * @return array{code:int,output:string,command:string}
     */
    public static function run(string $root, string $script, array $arguments = []): array
    {
        $runtime = str_ends_with($script, '.js') ? 'node' : PHP_BINARY;
        $parts = [escapeshellarg($runtime), escapeshellarg($root . '/' . $script)];
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }
        $command = implode(' ', $parts) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);

        return [
            'code' => $code,
            'output' => implode("\n", $output),
            'command' => $command,
        ];
    }

    /**
     * A certification test must positively identify its success. An exit-zero
     * "skipped" result is not evidence.
     *
     * @return array{ok:bool,detail:string}
     */
    public static function verifyTestResult(array $result, string $successMarker): array
    {
        $output = (string) ($result['output'] ?? '');
        $skipped = preg_match('/(?:^|[^a-z])skipp?ed(?:[^a-z]|$)/i', $output) === 1;
        $markerPresent = $successMarker !== '' && str_contains($output, $successMarker);
        $ok = (int) ($result['code'] ?? 1) === 0 && !$skipped && $markerPresent;

        return [
            'ok' => $ok,
            'detail' => substr(
                'exit=' . (int) ($result['code'] ?? 1)
                . ' marker=' . ($markerPresent ? 'yes' : 'no')
                . ' skipped=' . ($skipped ? 'yes' : 'no')
                . ' output=' . $output,
                0,
                600
            ),
        ];
    }

    /** @return array{git_commit:string,git_branch:string,source_tree_clean:bool,status_porcelain:list<string>} */
    public static function sourceIdentity(string $root): array
    {
        $commit = trim((string) shell_exec(
            'git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'
        ));
        $branch = trim((string) shell_exec(
            'git -C ' . escapeshellarg($root) . ' branch --show-current 2>/dev/null'
        ));
        $statusRaw = trim((string) shell_exec(
            'git -C ' . escapeshellarg($root) . ' status --porcelain=v1 --untracked-files=all 2>/dev/null'
        ));
        $status = $statusRaw === '' ? [] : preg_split('/\R/', $statusRaw);

        return [
            'git_commit' => $commit,
            'git_branch' => $branch,
            'source_tree_clean' => $commit !== '' && $status === [],
            'status_porcelain' => array_values(array_filter($status, static fn($line): bool => $line !== '')),
        ];
    }

    public static function writeReceipt(string $evidenceDir, string $gate, array $receipt): string
    {
        if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0755, true) && !is_dir($evidenceDir)) {
            throw new RuntimeException('EVIDENCE_DIRECTORY_CREATE_FAILED');
        }

        $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $stamp = gmdate('YmdHis');
        $path = $evidenceDir . '/' . $gate . '-gate-' . $stamp . '.json';
        if (file_put_contents($path, $json) === false
            || file_put_contents($evidenceDir . '/' . $gate . '-bundle-latest.json', $json) === false) {
            throw new RuntimeException('EVIDENCE_RECEIPT_WRITE_FAILED');
        }

        return $path;
    }
}
