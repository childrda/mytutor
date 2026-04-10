<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TutorRegistryActive;
use App\Services\Ai\LlmClient;
use App\Services\Ai\ModelRegistry;
use App\Services\Ai\ModelRegistryException;
use App\Services\Ai\ModelRegistryHttpExecutor;
use App\Services\Ai\ModelRegistryTemplate;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\RegistryTemplateVarsResolver;
use App\Services\Integrations\IntegrationProbes;
use App\Services\Settings\ModelsJsonFileStore;
use App\Services\Settings\TutorRegistryActiveSettings;
use App\Support\ApiJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 4 — authenticated JSON API for provider catalog, models.json CRUD, active keys, and probes.
 *
 * @see SettingsRegistryActiveController legacy routes `/settings/registry-active` (same active semantics).
 */
final class SettingsCatalogController extends Controller
{
    private const array MODEL_ENTRY_KEYS = [
        'id', 'provider', 'display_name', 'enabled', 'base_url',
        'endpoint', 'request_format', 'response_path', 'response_type',
        'request_encoding', 'request_headers', 'llm_input_wire',
        'auth_header', 'auth_scheme', '_note',
    ];

    public function __construct(
        private readonly ModelsJsonFileStore $modelsStore,
        private readonly TutorRegistryActiveSettings $activeSettings,
    ) {}

    public function providers(): JsonResponse
    {
        $path = config_path('providers.json');
        if (! is_readable($path)) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'providers.json is not readable');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'providers.json read failed');
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'providers.json invalid JSON');
        }

        $envConfigured = [];
        $pmap = $data['providers'] ?? null;
        if (is_array($pmap)) {
            foreach ($pmap as $pid => $p) {
                if (! is_string($pid) || $pid === '' || str_starts_with($pid, '_') || ! is_array($p)) {
                    continue;
                }
                $ek = $p['env_key'] ?? null;
                if (! is_string($ek) || $ek === '' || $ek === '{env_key}') {
                    $envConfigured[$pid] = false;
                } else {
                    $v = env($ek);
                    $envConfigured[$pid] = is_string($v) && trim($v) !== '';
                }
            }
        }

        return ApiJson::success([
            'providers' => $data,
            'env_key_configured' => $envConfigured,
        ]);
    }

    public function modelsIndex(): JsonResponse
    {
        try {
            return ApiJson::success(['models' => $this->modelsStore->readRaw()]);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }
    }

    public function modelsForCapability(string $capability): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }
        try {
            $raw = $this->modelsStore->readRaw();
            $list = $raw[$capability] ?? [];

            return ApiJson::success([
                'capability' => $capability,
                'models' => is_array($list) ? $list : [],
            ]);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }
    }

    public function storeModel(Request $request, string $capability): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'provider' => ['required', 'string', 'max:128'],
            'display_name' => ['required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if (! app(ProviderRegistry::class)->has($validated['provider'])) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Unknown provider id "'.$validated['provider'].'".');
        }

        $row = $this->filterModelEntry(array_merge($request->all(), $validated));
        if (($row['id'] ?? '') !== $validated['id']) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Body id mismatch.');
        }
        if (! isset($row['enabled'])) {
            $row['enabled'] = true;
        }

        if (! $this->entryExecutableOrStub($row)) {
            return ApiJson::error(
                ApiJson::INVALID_REQUEST,
                422,
                'Model must include request_format + endpoint + response_path/response_type, or a stub with _note only.',
            );
        }

        try {
            $doc = $this->modelsStore->readRaw();
            $list = $doc[$capability] ?? [];
            if (! is_array($list)) {
                $list = [];
            }
            foreach ($list as $existing) {
                if (is_array($existing) && ($existing['id'] ?? null) === $row['id']) {
                    return ApiJson::error(ApiJson::INVALID_REQUEST, 409, 'Model id already exists for this capability.');
                }
            }
            $list[] = $row;
            $doc[$capability] = $list;
            $this->modelsStore->writeRaw($doc);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        return ApiJson::success(['saved' => true, 'capability' => $capability, 'id' => $row['id']]);
    }

    public function updateModel(Request $request, string $capability, string $id): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }

        $request->validate([
            'provider' => ['sometimes', 'string', 'max:128'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        try {
            $doc = $this->modelsStore->readRaw();
            $list = $doc[$capability] ?? [];
            if (! is_array($list)) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'No models for capability');
            }
            $idx = $this->findRowIndex($list, $id);
            if ($idx === null) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Model not found');
            }
            $merged = array_merge($list[$idx], $this->filterModelEntry($request->all()));
            $merged['id'] = $id;
            if (isset($merged['provider']) && is_string($merged['provider'])
                && ! app(ProviderRegistry::class)->has($merged['provider'])) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Unknown provider id "'.$merged['provider'].'".');
            }
            if (! $this->entryExecutableOrStub($merged)) {
                return ApiJson::error(
                    ApiJson::INVALID_REQUEST,
                    422,
                    'Model must include request_format + endpoint + response_path/response_type, or a stub with _note only.',
                );
            }
            $list[$idx] = $merged;
            $doc[$capability] = $list;
            $this->modelsStore->writeRaw($doc);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        return ApiJson::success(['saved' => true, 'capability' => $capability, 'id' => $id]);
    }

    public function destroyModel(string $capability, string $id): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }

        if (TutorRegistryActive::query()->where('capability', $capability)->where('active_key', $id)->exists()) {
            return ApiJson::error(
                ApiJson::INVALID_REQUEST,
                409,
                'This model is the saved active selection. Clear it under Active registry first.',
            );
        }

        try {
            $doc = $this->modelsStore->readRaw();
            $list = $doc[$capability] ?? [];
            if (! is_array($list)) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Model not found');
            }
            $idx = $this->findRowIndex($list, $id);
            if ($idx === null) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Model not found');
            }
            array_splice($list, $idx, 1);
            $doc[$capability] = $list;
            $this->modelsStore->writeRaw($doc);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        return ApiJson::success(['deleted' => true, 'capability' => $capability, 'id' => $id]);
    }

    public function destroyModelBundle(Request $request, string $capability): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }

        $validated = $request->validate([
            'row_ids' => ['required', 'array', 'min:1'],
            'row_ids.*' => ['string', 'max:128'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values(array_unique($validated['row_ids']));

        foreach ($ids as $id) {
            if (TutorRegistryActive::query()->where('capability', $capability)->where('active_key', $id)->exists()) {
                return ApiJson::error(
                    ApiJson::INVALID_REQUEST,
                    409,
                    'Row "'.$id.'" is the saved active selection for this capability. Clear it under Active registry first.',
                );
            }
        }

        try {
            $doc = $this->modelsStore->readRaw();
            $list = $doc[$capability] ?? [];
            if (! is_array($list)) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'No models for capability');
            }

            $want = array_fill_keys($ids, true);
            $newList = [];
            $removed = 0;
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rid = $row['id'] ?? null;
                if (is_string($rid) && isset($want[$rid])) {
                    $removed++;

                    continue;
                }
                $newList[] = $row;
            }

            if ($removed === 0) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'No matching row ids in this capability.');
            }

            $doc[$capability] = $newList;
            $this->modelsStore->writeRaw($doc);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        return ApiJson::success([
            'deleted' => true,
            'capability' => $capability,
            'removed' => $removed,
            'requested' => count($ids),
        ]);
    }

    public function updateProviderBaseUrl(Request $request, string $capability): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:128'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'row_ids' => ['sometimes', 'array'],
            'row_ids.*' => ['string', 'max:128'],
        ]);

        if (! app(ProviderRegistry::class)->has($validated['provider'])) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Unknown provider id.');
        }

        $rawBase = isset($validated['base_url']) ? trim((string) $validated['base_url']) : '';
        if ($rawBase !== '' && ! preg_match('#\Ahttps?://#i', $rawBase)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'base_url must be empty or start with http:// or https://');
        }
        $normalized = $rawBase !== '' ? rtrim($rawBase, '/') : '';
        /** @var list<string> $onlyIds */
        $onlyIds = isset($validated['row_ids']) && is_array($validated['row_ids']) ? array_values($validated['row_ids']) : [];

        try {
            $doc = $this->modelsStore->readRaw();
            $list = $doc[$capability] ?? [];
            if (! is_array($list)) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'No models for capability');
            }
            $changed = 0;
            foreach ($list as $i => $row) {
                if (! is_array($row) || ($row['provider'] ?? null) !== $validated['provider']) {
                    continue;
                }
                $rid = $row['id'] ?? null;
                if ($onlyIds !== [] && (! is_string($rid) || ! in_array($rid, $onlyIds, true))) {
                    continue;
                }
                if ($normalized === '') {
                    unset($list[$i]['base_url']);
                } else {
                    $list[$i]['base_url'] = $normalized;
                }
                $changed++;
            }
            if ($changed === 0) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'No rows matched for this provider (and optional row_ids).');
            }
            $doc[$capability] = array_values($list);
            $this->modelsStore->writeRaw($doc);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        try {
            $models = $this->modelsStore->readRaw();
        } catch (Throwable) {
            $models = [];
        }

        return ApiJson::success([
            'saved' => true,
            'updated_rows' => $changed,
            'models' => $models,
        ]);
    }

    public function storeModelVariant(Request $request, string $capability): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'provider' => ['required', 'string', 'max:128'],
            'display_name' => ['required', 'string', 'max:255'],
            'api_model_id' => ['nullable', 'string', 'max:256'],
            'template_base_url' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! app(ProviderRegistry::class)->has($validated['provider'])) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Unknown provider id.');
        }

        try {
            $doc = $this->modelsStore->readRaw();
            $list = $doc[$capability] ?? [];
            if (! is_array($list)) {
                $list = [];
            }
            foreach ($list as $existing) {
                if (is_array($existing) && ($existing['id'] ?? null) === $validated['id']) {
                    return ApiJson::error(ApiJson::INVALID_REQUEST, 409, 'Model id already exists for this capability.');
                }
            }
            $wantBase = isset($validated['template_base_url']) ? trim((string) $validated['template_base_url']) : '';
            $wantBase = $wantBase !== '' ? rtrim($wantBase, '/') : null;

            $template = null;
            foreach ($list as $row) {
                if (! is_array($row) || ($row['provider'] ?? null) !== $validated['provider']) {
                    continue;
                }
                if ($wantBase !== null) {
                    $rb = isset($row['base_url']) && is_string($row['base_url']) ? rtrim(trim($row['base_url']), '/') : '';
                    if ($rb !== $wantBase) {
                        continue;
                    }
                }
                if (isset($row['request_format'], $row['endpoint']) && is_array($row['request_format']) && is_string($row['endpoint'])) {
                    $template = $row;
                    break;
                }
            }
            if ($template === null) {
                return ApiJson::error(
                    ApiJson::INVALID_REQUEST,
                    422,
                    'No executable template row exists for this provider — add a full model entry first.',
                );
            }
            /** @var array<string, mixed> $newRow */
            $newRow = json_decode(json_encode($template), true);
            if (! is_array($newRow)) {
                return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, 'Clone failed');
            }
            $newRow['id'] = $validated['id'];
            $newRow['display_name'] = $validated['display_name'];
            $modelDefault = is_string($validated['api_model_id'] ?? null) && trim($validated['api_model_id']) !== ''
                ? trim($validated['api_model_id'])
                : $validated['id'];
            if (isset($newRow['request_format']) && is_array($newRow['request_format']) && array_key_exists('model', $newRow['request_format'])) {
                $newRow['request_format']['model'] = '{model|'.$modelDefault.'}';
            }
            unset($newRow['_note']);
            if (! $this->entryExecutableOrStub($newRow)) {
                return ApiJson::error(ApiJson::INVALID_REQUEST, 422, 'Cloned row failed validation.');
            }
            $list[] = $newRow;
            $doc[$capability] = $list;
            $this->modelsStore->writeRaw($doc);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        try {
            $models = $this->modelsStore->readRaw();
        } catch (Throwable) {
            $models = [];
        }

        return ApiJson::success([
            'saved' => true,
            'capability' => $capability,
            'id' => $validated['id'],
            'models' => $models,
        ]);
    }

    public function testModel(Request $request, string $capability, string $id): JsonResponse
    {
        if (! $this->validCapability($capability)) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, 'Unknown capability');
        }

        try {
            $entry = app(ModelRegistry::class)->get($capability, $id);
        } catch (ModelRegistryException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 404, $e->getMessage());
        }

        if (! isset($entry['request_format']) || ! is_array($entry['request_format'])) {
            return ApiJson::success([
                'ok' => false,
                'skipped' => true,
                'message' => 'Stub model has no request_format — nothing to probe.',
            ]);
        }

        try {
            $result = match ($capability) {
                'llm' => $this->probeLlm($request, $capability, $entry),
                'image' => $this->probeOpenAiCompatibleBase($request, $capability, $entry),
                'tts' => $this->probeOpenAiCompatibleBase($request, $capability, $entry),
                'asr' => $this->probeOpenAiCompatibleBase($request, $capability, $entry),
                'web_search' => $this->probeWebSearch($capability, $entry),
                'video' => $this->probeVideo($entry),
                default => ['ok' => false, 'skipped' => true, 'message' => 'No automated probe for capability '.$capability.'.'],
            };
        } catch (Throwable $e) {
            return ApiJson::success(['ok' => false, 'error' => $e->getMessage()]);
        }

        return ApiJson::success($result);
    }

    public function activeShow(): JsonResponse
    {
        return ApiJson::success($this->activeSettings->snapshot());
    }

    public function activeUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active' => ['required', 'array'],
            'active.*' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $this->activeSettings->save($validated['active']);
        } catch (InvalidArgumentException $e) {
            return ApiJson::error(ApiJson::INVALID_REQUEST, 422, $e->getMessage());
        } catch (Throwable $e) {
            return ApiJson::error(ApiJson::INTERNAL_ERROR, 500, $e->getMessage());
        }

        return ApiJson::success(['saved' => true]);
    }

    private function validCapability(string $capability): bool
    {
        return in_array($capability, ModelRegistry::CAPABILITIES, true);
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function findRowIndex(array $list, string $id): ?int
    {
        foreach ($list as $i => $row) {
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterModelEntry(array $data): array
    {
        return Arr::only($data, self::MODEL_ENTRY_KEYS);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function entryExecutableOrStub(array $row): bool
    {
        if (isset($row['request_format']) && is_array($row['request_format'])) {
            $hasEndpoint = isset($row['endpoint']) && is_string($row['endpoint']) && trim($row['endpoint']) !== '';
            $hasPath = isset($row['response_path']) && is_string($row['response_path']) && $row['response_path'] !== '';
            $hasType = isset($row['response_type']) && is_string($row['response_type']) && $row['response_type'] !== '';

            return $hasEndpoint && ($hasPath || $hasType);
        }

        return isset($row['_note']) && is_string($row['_note']) && trim($row['_note']) !== '';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function probeLlm(Request $request, string $capability, array $entry): array
    {
        $registryDefault = ModelRegistryTemplate::defaultModelIdFromEntry($entry);
        $globalDefault = (string) config('tutor.default_chat.model', 'gpt-4o-mini');
        $probeDefault = ($registryDefault !== null && $registryDefault !== '') ? $registryDefault : $globalDefault;

        $model = is_string($request->input('model')) && trim($request->input('model')) !== ''
            ? trim((string) $request->input('model'))
            : $probeDefault;

        $vars = RegistryTemplateVarsResolver::merge($capability, $entry, [
            'api_key' => (string) $request->input('apiKey', ''),
            'base_url' => (string) $request->input('baseUrl', ''),
            'model' => $model,
        ]);
        $apiKey = trim((string) ($vars['api_key'] ?? ''));
        $baseUrl = rtrim((string) ($vars['base_url'] ?? ''), '/');
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'No API key resolved (set apiKey in request or matching provider env_key).'];
        }

        return LlmClient::verifyLlmCatalogRowProbe($entry, $apiKey, $baseUrl, $model, 25.0);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function probeOpenAiCompatibleBase(Request $request, string $capability, array $entry): array
    {
        $vars = RegistryTemplateVarsResolver::merge($capability, $entry, [
            'api_key' => (string) $request->input('apiKey', ''),
            'base_url' => (string) $request->input('baseUrl', ''),
        ]);
        $apiKey = trim((string) ($vars['api_key'] ?? ''));
        $baseUrl = rtrim((string) ($vars['base_url'] ?? ''), '/');
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'No API key resolved for provider env_key.'];
        }

        return IntegrationProbes::openAiCompatibleAuth($baseUrl, $apiKey, 20.0);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function probeWebSearch(string $capability, array $entry): array
    {
        $vars = RegistryTemplateVarsResolver::merge($capability, $entry, [
            'api_key' => '',
            'query' => 'ping',
            'timeout' => 15.0,
        ]);
        if (trim((string) ($vars['api_key'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'No API key resolved for web search.'];
        }

        try {
            $result = app(ModelRegistryHttpExecutor::class)->execute($entry, $vars);
        } catch (ModelRegistryException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => $result->successful, 'status' => $result->status];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function probeVideo(array $entry): array
    {
        $vars = RegistryTemplateVarsResolver::merge('video', $entry, [
            'api_key' => '',
            'base_url' => rtrim((string) config('tutor.video_generation.base_url', ''), '/'),
        ]);
        $apiKey = trim((string) ($vars['api_key'] ?? ''));
        $baseUrl = rtrim((string) ($vars['base_url'] ?? ''), '/');
        if ($apiKey === '' || $baseUrl === '') {
            return ['ok' => false, 'message' => 'Video probe needs api key and base URL (registry + tutor.video_generation).'];
        }

        return IntegrationProbes::minimaxVideoAuth($baseUrl, $apiKey, 20.0);
    }
}
