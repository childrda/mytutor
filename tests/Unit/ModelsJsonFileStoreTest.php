<?php

namespace Tests\Unit;

use App\Services\Ai\ModelRegistry;
use App\Services\Settings\ModelsJsonFileStore;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No database — validates catalog file IO + {@see ModelRegistry} acceptance after write.
 */
class ModelsJsonFileStoreTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->tmp = storage_path('framework/testing/models_store_'.uniqid('', true).'.json');
        File::copy(config_path('models.json'), $this->tmp);
        config(['tutor.models_json_path' => $this->tmp]);
        app()->forgetInstance(ModelRegistry::class);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_file($this->tmp)) {
            @unlink($this->tmp);
        }
        parent::tearDown();
    }

    #[Test]
    public function round_trip_write_then_registry_loads(): void
    {
        $store = new ModelsJsonFileStore;
        $doc = $store->readRaw();
        $id = 'unit-stub-'.bin2hex(random_bytes(3));
        $doc['llm'][] = [
            'id' => $id,
            'provider' => 'openai',
            'display_name' => 'Unit stub',
            '_note' => 'temporary',
        ];
        $store->writeRaw($doc);

        app()->forgetInstance(ModelRegistry::class);
        $reg = app(ModelRegistry::class);
        $this->assertTrue($reg->has('llm', $id));
    }
}
