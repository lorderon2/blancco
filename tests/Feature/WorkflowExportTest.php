<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkflowExportTest extends TestCase
{
    public function test_api_failures_reach_manual_selection_without_extra_questions(): void
    {
        $workflow = json_decode(file_get_contents(resource_path('workflows/install-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('V2', $workflow['editorMetadata']['editorGeneration']);
        $this->assertSame('7.20.0', $workflow['version']);
        $nodes = collect($workflow['objects'])->keyBy('item_id');
        foreach (['Pobierz identyfikator uruchomienia', 'Pobierz zlecenie', 'Rozpocznij według zamówienia'] as $title) {
            foreach ($nodes->where('itemBriefText', $title) as $node) {
                $reset = $nodes[$node['itemAtFail']];
                $this->assertSame('<VARIABLE AttemptId> = ""', $reset['setValueExp']);
                $this->assertSame('Klucz dla instalacji ręcznej', $nodes[$reset['itemAtOk']]['itemBriefText']);
            }
        }
        foreach ($nodes->where('itemBriefText', 'Zarejestruj ręczną próbę') as $node) {
            $clear = $nodes[$node['itemAtFail']];
            $this->assertSame('<VARIABLE AttemptId> = ""', $clear['setValueExp']);
            $this->assertSame('Reimage', $nodes[$clear['itemAtOk']]['itemType']);
        }
        $this->assertCount(2, $nodes->where('itemType', 'Question'));
    }

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
            $this->assertSame('Czy AttemptId = ?', $nodes[$node['itemAtOk']]['itemBriefText']);
        }
    }
}
