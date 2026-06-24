<?php

if (!function_exists('posmainUpdateProjectRoot')) {
    function posmainUpdateProjectRoot(?string $root = null): string
    {
        if ($root !== null && $root !== '') {
            return rtrim($root, '/\\');
        }

        $resolved = realpath(dirname(__DIR__));
        if ($resolved === false) {
            return rtrim(dirname(__DIR__), '/\\');
        }

        return $resolved;
    }
}

if (!function_exists('posmainUpdateGitBranch')) {
    function posmainUpdateGitBranch(): string
    {
        $branch = trim((string) (getenv('POSMAIN_UPDATE_GIT_BRANCH') ?: 'main'));

        return $branch !== '' ? $branch : 'main';
    }
}

if (!function_exists('posmainUpdateGitRemote')) {
    function posmainUpdateGitRemote(): string
    {
        $remote = trim((string) (getenv('POSMAIN_UPDATE_GIT_REMOTE') ?: 'origin'));

        return $remote !== '' ? $remote : 'origin';
    }
}

if (!function_exists('posmainUpdateRunGitCommand')) {
    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    function posmainUpdateRunGitCommand(string $root, string $command): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open unavailable'];
        }

        $fullCommand = 'cd ' . escapeshellarg($root) . ' && ' . $command;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($fullCommand, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => (int) $exitCode,
            'stdout' => is_string($stdout) ? trim($stdout) : '',
            'stderr' => is_string($stderr) ? trim($stderr) : '',
        ];
    }
}

if (!function_exists('posmainUpdateGitSyncState')) {
    function posmainUpdateGitSyncState(?string $root = null): array
    {
        $root = posmainUpdateProjectRoot($root);
        $branch = posmainUpdateGitBranch();
        $remote = posmainUpdateGitRemote();
        $remoteRef = $remote . '/' . $branch;

        if (!is_dir($root . '/.git')) {
            return [
                'ok' => false,
                'git_behind' => false,
                'error' => 'not_a_git_repository',
            ];
        }

        $fetch = posmainUpdateRunGitCommand(
            $root,
            'git fetch --quiet ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch)
        );
        if ($fetch['exit_code'] !== 0) {
            return [
                'ok' => false,
                'git_behind' => false,
                'error' => 'git_fetch_failed',
                'message' => trim($fetch['stderr'] ?: $fetch['stdout']),
            ];
        }

        $localCommit = posmainUpdateRunGitCommand($root, 'git rev-parse HEAD');
        $remoteCommit = posmainUpdateRunGitCommand($root, 'git rev-parse ' . escapeshellarg($remoteRef));
        if ($localCommit['exit_code'] !== 0 || $remoteCommit['exit_code'] !== 0) {
            return [
                'ok' => false,
                'git_behind' => false,
                'error' => 'git_ref_unavailable',
            ];
        }

        $localSha = $localCommit['stdout'];
        $remoteSha = $remoteCommit['stdout'];
        $gitBehind = false;
        if ($localSha !== '' && $remoteSha !== '' && $localSha !== $remoteSha) {
            $ancestor = posmainUpdateRunGitCommand(
                $root,
                'git merge-base --is-ancestor ' . escapeshellarg($localSha) . ' ' . escapeshellarg($remoteSha)
            );
            $gitBehind = $ancestor['exit_code'] === 0;
        }

        $remoteVersion = posmainUpdatePublishedVersionFromGit($root, $remoteRef);

        return [
            'ok' => true,
            'git_behind' => $gitBehind,
            'local_commit' => $localSha,
            'remote_commit' => $remoteSha,
            'branch' => $branch,
            'remote' => $remote,
            'remote_version' => $remoteVersion,
        ];
    }
}

if (!function_exists('posmainUpdatePublishedVersionFromGit')) {
    function posmainUpdatePublishedVersionFromGit(?string $root = null, ?string $ref = null): ?array
    {
        $root = posmainUpdateProjectRoot($root);
        if (!is_dir($root . '/.git')) {
            return null;
        }

        $branch = posmainUpdateGitBranch();
        $remote = posmainUpdateGitRemote();
        $gitRef = $ref ?: ($remote . '/' . $branch);
        $result = posmainUpdateRunGitCommand(
            $root,
            'git show ' . escapeshellarg($gitRef . ':version.json')
        );
        if ($result['exit_code'] !== 0 || $result['stdout'] === '') {
            return null;
        }

        $payload = json_decode($result['stdout'], true);
        if (!is_array($payload)) {
            return null;
        }

        $version = trim((string) ($payload['version'] ?? ''));
        if ($version === '' || preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $version) !== 1) {
            return null;
        }

        $payload['version'] = $version;
        $payload['source'] = 'git';

        return $payload;
    }
}
