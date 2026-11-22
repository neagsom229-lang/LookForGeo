<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** CI configuration must stay parseable and use valid Dependabot values. */
class WorkflowConfigTest extends TestCase
{
    private function parse(string $relative): array
    {
        $path = base_path($relative);
        $this->assertFileExists($path);
        $parsed = Yaml::parseFile($path);
        $this->assertIsArray($parsed);

        return $parsed;
    }

    public function test_dependabot_uses_valid_version_update_values(): void
    {
        $config = $this->parse('.github/dependabot.yml');

        $this->assertSame(2, $config['version']);
        $allowed = [
            'version-update:semver-major',
            'version-update:semver-minor',
            'version-update:semver-patch',
        ];

        foreach ($config['updates'] as $update) {
            $this->assertLessThanOrEqual(3, $update['open-pull-requests-limit']);

            foreach ($update['ignore'] ?? [] as $rule) {
                $this->assertNotSame([], $rule['update-types']);
                foreach ($rule['update-types'] as $type) {
                    $this->assertContains($type, $allowed, 'Dependabot update-types must be version-update:semver-*');
                }
                // Major bumps must be explicitly ignored, not shortened.
                $this->assertContains('version-update:semver-major', $rule['update-types']);
            }

            foreach ($update['groups'] ?? [] as $group) {
                foreach ($group['update-types'] as $type) {
                    $this->assertContains($type, $allowed);
                }
            }
        }
    }

    public function test_workflows_are_parseable_and_deploy_has_a_concurrency_group(): void
    {
        foreach (['tests.yml', 'deploy.yml', 'eval-live.yml', 'gitleaks.yml'] as $file) {
            $this->parse('.github/workflows/'.$file);
        }

        $deploy = $this->parse('.github/workflows/deploy.yml');
        $this->assertArrayHasKey('concurrency', $deploy);
        $this->assertNotEmpty($deploy['concurrency']['group']);
    }
}
