<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/pos_update_git.php';

$base = sys_get_temp_dir() . '/posmain-update-git-' . getmypid() . '-' . bin2hex(random_bytes(3));
$remote = $base . '/remote.git';
$seed = $base . '/seed';
$local = $base . '/local';
mkdir($base, 0700, true);

try {
    updateGitRun(['git', 'init', '--bare', $remote]);
    updateGitRun(['git', 'clone', $remote, $seed]);
    updateGitRun(['git', '-C', $seed, 'config', 'user.email', 'updates@example.test']);
    updateGitRun(['git', '-C', $seed, 'config', 'user.name', 'Update Test']);
    file_put_contents($seed . '/version.json', json_encode(['version' => '1.0.0', 'min_php' => '8.2']) . PHP_EOL);
    file_put_contents($seed . '/version.txt', "1.0.0\n");
    updateGitRun(['git', '-C', $seed, 'add', 'version.json', 'version.txt']);
    updateGitRun(['git', '-C', $seed, 'commit', '-m', 'release 1']);
    updateGitRun(['git', '-C', $seed, 'branch', '-M', 'main']);
    updateGitRun(['git', '-C', $seed, 'push', '-u', 'origin', 'main']);
    updateGitRun(['git', '--git-dir=' . $remote, 'symbolic-ref', 'HEAD', 'refs/heads/main']);
    updateGitRun(['git', 'clone', $remote, $local]);

    putenv('POSMAIN_UPDATE_GIT_REMOTE=origin');
    putenv('POSMAIN_UPDATE_GIT_BRANCH=main');
    $current = posmainUpdateGitSyncState($local);
    updateGitAssert($current['state'] === 'up_to_date', 'fresh clone must be up to date');
    updateGitAssert($current['clean'] === true, 'fresh clone must be clean');
    updateGitAssert($current['can_update'] === false, 'up-to-date clone must not update');

    file_put_contents($seed . '/version.json', json_encode(['version' => '1.1.0', 'min_php' => '8.2']) . PHP_EOL);
    file_put_contents($seed . '/version.txt', "1.1.0\n");
    file_put_contents($seed . '/release.php', "<?php\n");
    updateGitRun(['git', '-C', $seed, 'add', 'version.json', 'version.txt', 'release.php']);
    updateGitRun(['git', '-C', $seed, 'commit', '-m', 'release 2']);
    updateGitRun(['git', '-C', $seed, 'push']);

    $behind = posmainUpdateGitPreflight($local, '1.1.0');
    updateGitAssert($behind['state'] === 'behind', 'older clone must be behind');
    updateGitAssert($behind['can_update'] === true, 'clean behind clone must be updateable');
    file_put_contents($local . '/concurrent-change.txt', "changed after preflight\n");
    try {
        posmainUpdateGitFastForward($local, (string) $behind['remote_commit'], (string) $behind['local_commit']);
        throw new RuntimeException('post-preflight change must fail');
    } catch (RuntimeException $exception) {
        updateGitAssert(
            $exception->getMessage() === 'UPDATE_GIT_WORKTREE_CHANGED_AFTER_PREFLIGHT',
            'activation must reject changes made after preflight'
        );
    }
    unlink($local . '/concurrent-change.txt');
    $updated = posmainUpdateGitFastForward(
        $local,
        (string) $behind['remote_commit'],
        (string) $behind['local_commit']
    );
    updateGitAssert($updated['commit_after'] === $behind['remote_commit'], 'fast-forward must activate exact fetched commit');
    updateGitAssert(trim((string) file_get_contents($local . '/version.txt')) === '1.1.0', 'release files must come from fetched commit');

    file_put_contents($local . '/local-change.txt', "dirty\n");
    $dirty = posmainUpdateGitSyncState($local);
    updateGitAssert($dirty['clean'] === false, 'untracked file must make checkout dirty');
    updateGitAssert(in_array('git_worktree_dirty', $dirty['blockers'], true), 'dirty checkout must be blocked');
    try {
        posmainUpdateGitPreflight($local, '1.1.0');
        throw new RuntimeException('dirty preflight must fail');
    } catch (RuntimeException $exception) {
        updateGitAssert(
            $exception->getMessage() === 'UPDATE_GIT_NOT_BEHIND:up_to_date',
            'up-to-date dirty checkout must not enter update'
        );
    }
    unlink($local . '/local-change.txt');

    file_put_contents($local . '/ahead.txt', "ahead\n");
    updateGitRun(['git', '-C', $local, 'config', 'user.email', 'updates@example.test']);
    updateGitRun(['git', '-C', $local, 'config', 'user.name', 'Update Test']);
    updateGitRun(['git', '-C', $local, 'add', 'ahead.txt']);
    updateGitRun(['git', '-C', $local, 'commit', '-m', 'local ahead']);
    $ahead = posmainUpdateGitSyncState($local);
    updateGitAssert($ahead['state'] === 'ahead', 'local-only commit must be ahead');
    updateGitAssert(in_array('git_ahead', $ahead['blockers'], true), 'ahead checkout must be blocked');

    echo "update-git-safety-ok\n";
} finally {
    putenv('POSMAIN_UPDATE_GIT_REMOTE');
    putenv('POSMAIN_UPDATE_GIT_BRANCH');
    updateGitRemoveTree($base);
}

function updateGitRun(array $command): void
{
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start git fixture command');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(trim((string) ($stderr ?: $stdout)));
    }
}

function updateGitAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function updateGitRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        is_dir($child) && !is_link($child) ? updateGitRemoveTree($child) : @unlink($child);
    }
    @rmdir($path);
}
