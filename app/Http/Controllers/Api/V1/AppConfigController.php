<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppConfigController extends DomainResourceController
{
    protected string $table = 'app_configs';

    public function showKey(string $key)
    {
        $row = DB::table('app_configs')->where('key', $key)->where('public', true)->first();
        if (! $row && $key === 'revenue_policy') return $this->success(config('revenue_policy'));
        return $row ? $this->success($this->value($row)) : $this->failure('NOT_FOUND', 'Configuration not found.', 404);
    }

    public function upsertKey(Request $request, string $key)
    {
        $data = $request->validate(['value' => ['required', 'array']])['value'];
        if ($key === 'revenue_policy') {
            $rules = [];
            foreach (config('revenue_policy') as $field => $default) {
                $rules[$field] = ['required', 'numeric', 'min:0', str_ends_with($field, 'Pct') ? 'max:1' : 'max:100000000'];
            }
            $data = validator($data, $rules)->validate();
        }
        $now = now();
        DB::table('app_configs')->updateOrInsert(
            ['key' => $key],
            ['legacy_id' => $key, 'value' => json_encode($data), 'public' => true, 'updated_at' => $now, 'created_at' => $now],
        );
        return $this->success($data);
    }

    private function value(object $row): array
    {
        return is_array($row->value) ? $row->value : (json_decode((string) $row->value, true) ?: []);
    }
}
