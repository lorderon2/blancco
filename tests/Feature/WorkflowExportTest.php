<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkflowExportTest extends TestCase
{
    public function test_export_has_valid_edges_no_secrets_and_no_reimage_after_completion_failure(): void
    {
        $raw = file_get_contents(resource_path('workflows/install-v1.json'));
        $workflow = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['objects'])->keyBy('item_id');
        foreach ($nodes as $node) {
            foreach (['itemAtOk', 'itemAtFail'] as $edge) {
                $this->assertTrue($node[$edge] === -1 || $nodes->has($node[$edge]));
            }
        }
        $this->assertStringNotContainsString('AAAAC3Nza', $raw);
        $this->assertStringContainsString('REPLACE_WITH_API_TOKEN', $raw);
        $this->assertCount(4, $nodes->where('itemType', 'Reimage'));
        $complete = $nodes->firstWhere('itemBriefText', 'Zapisz sukces Reimage');
        $queue = [$complete['itemAtFail']];
        $seen = [];
        while ($queue) {
            $id = array_pop($queue);
            if ($id === -1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $node = $nodes[$id];
            $this->assertNotSame('Reimage', $node['itemType']);
            $queue[] = $node['itemAtOk'];
            $queue[] = $node['itemAtFail'];
        }
        foreach ($nodes->where('itemType', 'Reimage') as $node) {
            $this->assertSame($complete['item_id'], $node['itemAtOk']);
        }
    }
}
