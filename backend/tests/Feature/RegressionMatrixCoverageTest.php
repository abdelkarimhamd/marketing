<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegressionMatrixCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_regression_matrix_covers_features_31_to_50_with_minimum_test_cases(): void
    {
        $path = base_path('../qa/task51/regression_matrix_31_50.json');

        $this->assertFileExists($path, 'Regression matrix file is missing.');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['features'] ?? null);

        $features = collect($decoded['features']);

        $this->assertCount(20, $features, 'Expected exactly 20 features (31..50).');

        $ids = $features
            ->pluck('feature_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(range(31, 50), $ids);

        $features->each(function (mixed $feature): void {
            $this->assertIsArray($feature);
            $this->assertNotEmpty($feature['feature_name'] ?? null);
            $this->assertIsArray($feature['api_endpoints'] ?? null);
            $this->assertIsArray($feature['ui_screens'] ?? null);
            $this->assertIsArray($feature['test_cases'] ?? null);
            $this->assertGreaterThanOrEqual(
                3,
                count($feature['test_cases'] ?? []),
                'Each feature must define at least 3 test cases.'
            );
        });
    }
}
