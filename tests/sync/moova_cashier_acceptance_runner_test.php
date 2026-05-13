<?php

use PHPUnit\Framework\TestCase;

class MoovaCashierAcceptanceRunnerTest extends TestCase
{
    public function testHelpDocsAndSourceDescribeLocalMockBackedBoundary(): void
    {
        exec('php ' . escapeshellarg($this->root() . '/tools/moova_cashier_acceptance_runner.php') . ' --help', $lines, $code);

        $output = implode("\n", $lines);
        $source = $this->source('tools/moova_cashier_acceptance_runner.php');
        $doc = $this->source('docs/moova_cashier_acceptance_runner.md');
        $readinessDoc = $this->source('docs/branch_go_live_readiness.md');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('--output=/absolute/path/to/moova-cashier-acceptance.md', $output);
        $this->assertStringContainsString('--skip-live-topology', $output);
        $this->assertStringContainsString('moova_reachability_smoke.php', $source);
        $this->assertStringContainsString('moova_local_topology_check.php', $source);
        $this->assertStringContainsString('local_mock_backed_acceptance', $source);
        $this->assertStringContainsString('final real-shop hosted cashier acceptance', $source);
        $this->assertStringNotContainsString('INSERT INTO', $source);
        $this->assertStringNotContainsString('UPDATE ', $source);
        $this->assertStringNotContainsString('DELETE FROM', $source);
        $this->assertStringContainsString('queued_new_order=pass', $doc);
        $this->assertStringContainsString('=fail', $doc);
        $this->assertStringContainsString('tools/moova_cashier_acceptance_runner.php', $readinessDoc);
    }

    public function testRunnerWritesReadinessMarkersFromTwoMockServerSmoke(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'posmain-moova-acceptance-');
        $this->assertIsString($output);

        try {
            $cmd = 'php ' . escapeshellarg($this->root() . '/tools/moova_cashier_acceptance_runner.php')
                . ' --skip-live-topology'
                . ' --output=' . escapeshellarg($output)
                . ' --branch-uuid=ffffffff-5656-4566-8566-ffffffffffff'
                . ' --operator=' . escapeshellarg('phpunit')
                . ' --json';
            exec($cmd, $lines, $code);
            $raw = implode("\n", $lines);
            $payload = json_decode($raw, true);

            $this->assertSame(0, $code, $raw);
            $this->assertIsArray($payload, $raw);
            $this->assertTrue($payload['ok']);
            $this->assertSame('local_mock_backed_acceptance', $payload['evidence_type']);
            $this->assertTrue($payload['topology']['skipped']);
            $this->assertTrue($payload['smoke']['ok']);
            $this->assertGreaterThanOrEqual(7, $payload['smoke']['step_count']);
            $this->assertSame($output, $payload['output']);

            $contents = file_get_contents($output);
            $this->assertIsString($contents);
            foreach ([
                'queued_new_order=pass',
                'queued_edit_order=pass',
                'queued_cancel_order=pass',
                'pos_drop_recovery=pass',
                'moova_drop_recovery=pass',
            ] as $marker) {
                $this->assertStringContainsString($marker, $contents);
            }
            $this->assertStringContainsString('generated_by=moova_cashier_acceptance_runner', $contents);
            $this->assertStringContainsString('local_mock_backed_acceptance', $contents);
        } finally {
            @unlink($output);
        }
    }

    private function source(string $path): string
    {
        $absolute = $this->root() . '/' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../..');
        $this->assertNotFalse($root);

        return $root;
    }
}

class moova_cashier_acceptance_runner_test extends MoovaCashierAcceptanceRunnerTest
{
}
