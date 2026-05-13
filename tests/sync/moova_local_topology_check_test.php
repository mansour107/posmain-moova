<?php

use PHPUnit\Framework\TestCase;

class MoovaLocalTopologyCheckTest extends TestCase
{
    public function testTopologyCheckCoversLivePortsDockerAndActiveLinks(): void
    {
        $source = $this->source('tools/moova_local_topology_check.php');

        $this->assertStringContainsString('moova_topology_tcp_check($posUrl)', $source);
        $this->assertStringContainsString('moova_topology_http_check($posUrl)', $source);
        $this->assertStringContainsString("moova_topology_http_check(\$moovaUrl . '/readyz')", $source);
        $this->assertStringContainsString("moova_topology_http_check(\$moovaUrl . '/pos-widget')", $source);
        $this->assertStringContainsString('moova_topology_docker_hints()', $source);
        $this->assertStringContainsString('moova_topology_pos_db_links($options)', $source);
    }

    public function testTopologyCheckDefaultsToKnownLocalUrlsAndIsNonMutating(): void
    {
        $source = $this->source('tools/moova_local_topology_check.php');

        $this->assertStringContainsString('http://127.0.0.1:8010/index.php', $source);
        $this->assertStringContainsString('http://127.0.0.1:3001', $source);
        $this->assertStringContainsString('POSMAIN_LOCAL_POS_URL', $source);
        $this->assertStringContainsString('POSMAIN_LOCAL_MOOVA_URL', $source);
        $this->assertStringNotContainsString('INSERT INTO', $source);
        $this->assertStringNotContainsString('UPDATE ', $source);
        $this->assertStringNotContainsString('DELETE FROM', $source);
    }

    public function testTopologyCheckExplainsMoovaUnreachableRootCause(): void
    {
        $source = $this->source('tools/moova_local_topology_check.php');

        $this->assertStringContainsString('MOOVA_TCP_DOWN', $source);
        $this->assertStringContainsString('MOOVA_READYZ_DOWN', $source);
        $this->assertStringContainsString('ACTIVE_LINK_POINTS_TO_DOWN_MOOVA', $source);
        $this->assertStringContainsString('Moova/Cofe service is not accepting TCP connections.', $source);
        $this->assertStringContainsString('Active POS Moova link points to port 3001', $source);
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }
}

class moova_local_topology_check_test extends MoovaLocalTopologyCheckTest
{
}
