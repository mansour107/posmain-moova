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

        $repository = posmainUpdateRunGitCommand($root, 'git rev-parse --is-inside-work-tree');
        if ($repository['exit_code'] !== 0 || trim($repository['stdout']) !== 'true') {
            return [
                'ok' => false,
                'git_behind' => false,
                'error' => 'not_a_git_repository',
                'state' => 'unavailable',
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
                'state' => 'unavailable',
            ];
        }

        $localCommit = posmainUpdateRunGitCommand($root, 'git rev-parse HEAD');
        $remoteCommit = posmainUpdateRunGitCommand($root, 'git rev-parse --verify ' . escapeshellarg($remoteRef));
        if ($localCommit['exit_code'] !== 0 || $remoteCommit['exit_code'] !== 0) {
            return [
                'ok' => false,
                'git_behind' => false,
                'error' => 'git_ref_unavailable',
                'state' => 'unavailable',
            ];
        }

        $localSha = $localCommit['stdout'];
        $remoteSha = $remoteCommit['stdout'];
        $currentBranchResult = posmainUpdateRunGitCommand($root, 'git symbolic-ref --quiet --short HEAD');
        $currentBranch = $currentBranchResult['exit_code'] === 0 ? trim($currentBranchResult['stdout']) : '';
        $status = posmainUpdateRunGitCommand($root, 'git status --porcelain --untracked-files=normal');
        if ($status['exit_code'] !== 0) {
            return [
                'ok' => false,
                'git_behind' => false,
                'error' => 'git_status_failed',
                'message' => trim($status['stderr'] ?: $status['stdout']),
                'state' => 'unavailable',
            ];
        }
        $clean = trim($status['stdout']) === '';
        $state = 'up_to_date';
        if ($localSha !== $remoteSha) {
            $localAncestor = posmainUpdateRunGitCommand(
                $root,
                'git merge-base --is-ancestor ' . escapeshellarg($localSha) . ' ' . escapeshellarg($remoteSha)
            );
            $remoteAncestor = posmainUpdateRunGitCommand(
                $root,
                'git merge-base --is-ancestor ' . escapeshellarg($remoteSha) . ' ' . escapeshellarg($localSha)
            );
            if ($localAncestor['exit_code'] === 0) {
                $state = 'behind';
            } elseif ($remoteAncestor['exit_code'] === 0) {
                $state = 'ahead';
            } else {
                $state = 'diverged';
            }
        }

        $remoteVersion = posmainUpdatePublishedVersionFromGit($root, $remoteRef);
        $branchMatches = $currentBranch !== '' && hash_equals($branch, $currentBranch);
        $blockers = [];
        if (!$branchMatches) {
            $blockers[] = 'git_branch_mismatch';
        }
        if (!$clean) {
            $blockers[] = 'git_worktree_dirty';
        }
        if (in_array($state, ['ahead', 'diverged'], true)) {
            $blockers[] = 'git_' . $state;
        }
        if ($remoteVersion === null) {
            $blockers[] = 'remote_version_manifest_invalid';
        }

        return [
            'ok' => true,
            'git_behind' => $state === 'behind',
            'state' => $state,
            'clean' => $clean,
            'branch_matches' => $branchMatches,
            'can_update' => $state === 'behind' && $clean && $branchMatches && $remoteVersion !== null,
            'blockers' => $blockers,
            'local_commit' => $localSha,
            'remote_commit' => $remoteSha,
            'current_branch' => $currentBranch,
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
        $repository = posmainUpdateRunGitCommand($root, 'git rev-parse --is-inside-work-tree');
        if ($repository['exit_code'] !== 0 || trim($repository['stdout']) !== 'true') {
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

if (!function_exists('posmainUpdateGitPreflight')) {
    function posmainUpdateGitPreflight(?string $root, string $targetVersion): array
    {
        $root = posmainUpdateProjectRoot($root);
        $sync = posmainUpdateGitSyncState($root);
        if (empty($sync['ok'])) {
            throw new RuntimeException('UPDATE_GIT_UNAVAILABLE:' . (string) ($sync['error'] ?? 'unknown'));
        }
        if (($sync['state'] ?? '') !== 'behind') {
            throw new RuntimeException('UPDATE_GIT_NOT_BEHIND:' . (string) ($sync['state'] ?? 'unknown'));
        }
        if (empty($sync['clean'])) {
            throw new RuntimeException('UPDATE_GIT_WORKTREE_DIRTY');
        }
        if (empty($sync['branch_matches'])) {
            throw new RuntimeException('UPDATE_GIT_BRANCH_MISMATCH');
        }
        if (empty($sync['can_update'])) {
            throw new RuntimeException('UPDATE_GIT_PREFLIGHT_FAILED');
        }

        $manifest = is_array($sync['remote_version'] ?? null) ? $sync['remote_version'] : [];
        $manifestVersion = trim((string) ($manifest['version'] ?? ''));
        if ($targetVersion === '' || !hash_equals($manifestVersion, $targetVersion)) {
            throw new RuntimeException('UPDATE_TARGET_VERSION_MISMATCH');
        }
        $minimumPhp = trim((string) ($manifest['min_php'] ?? ''));
        if ($minimumPhp !== '' && version_compare(PHP_VERSION, $minimumPhp, '<')) {
            throw new RuntimeException('UPDATE_PHP_VERSION_UNSUPPORTED:' . $minimumPhp);
        }

        return $sync;
    }
}

if (!function_exists('posmainUpdateGitFastForward')) {
    function posmainUpdateGitFastForward(string $root, string $expectedCommit, ?string $expectedCurrentCommit = null): array
    {
        $root = posmainUpdateProjectRoot($root);
        if (preg_match('/^[a-f0-9]{40}$/', $expectedCommit) !== 1) {
            throw new InvalidArgumentException('UPDATE_TARGET_COMMIT_INVALID');
        }

        $headBefore = posmainUpdateRunGitCommand($root, 'git rev-parse HEAD');
        if ($headBefore['exit_code'] !== 0 || trim($headBefore['stdout']) === '') {
            throw new RuntimeException('UPDATE_GIT_HEAD_UNAVAILABLE');
        }
        if (
            $expectedCurrentCommit !== null
            && (
                preg_match('/^[a-f0-9]{40}$/', $expectedCurrentCommit) !== 1
                || !hash_equals($expectedCurrentCommit, trim($headBefore['stdout']))
            )
        ) {
            throw new RuntimeException('UPDATE_GIT_HEAD_CHANGED_AFTER_PREFLIGHT');
        }
        $status = posmainUpdateRunGitCommand($root, 'git status --porcelain --untracked-files=normal');
        if ($status['exit_code'] !== 0 || trim($status['stdout']) !== '') {
            throw new RuntimeException('UPDATE_GIT_WORKTREE_CHANGED_AFTER_PREFLIGHT');
        }
        $merge = posmainUpdateRunGitCommand(
            $root,
            'git merge --ff-only ' . escapeshellarg($expectedCommit)
        );
        if ($merge['exit_code'] !== 0) {
            throw new RuntimeException('UPDATE_GIT_FAST_FORWARD_FAILED:' . trim($merge['stderr'] ?: $merge['stdout']));
        }

        $headAfter = posmainUpdateRunGitCommand($root, 'git rev-parse HEAD');
        if ($headAfter['exit_code'] !== 0 || !hash_equals($expectedCommit, trim($headAfter['stdout']))) {
            throw new RuntimeException('UPDATE_GIT_COMMIT_VERIFICATION_FAILED');
        }

        return [
            'commit_before' => trim($headBefore['stdout']),
            'commit_after' => trim($headAfter['stdout']),
            'stdout' => trim($merge['stdout']),
        ];
    }
}
