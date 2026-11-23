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

    public function test_dependabot_ignore_uses_version_update_semver_values(): void
    {
        $config = $this->parse('.github/dependabot.yml');
        $this->assertSame(2, $config['version']);

        $allowed = [
            'version-update:semver-major',
            'version-update:semver-minor',
            'version-update:semver-patch',
        ];
        $found = 0;
        foreach ($config['updates'] as $update) {
            foreach ($update['ignore'] ?? [] as $rule) {
                $found++;
                $this->assertArrayHasKey('update-types', $rule);
                $this->assertNotSame([], $rule['update-types']);
                foreach ($rule['update-types'] as $type) {
                    $this->assertContains($type, $allowed, 'ignore.update-types must be version-update:semver-*');
                }
            }
        }
        $this->assertGreaterThan(0, $found);
    }

    public function test_dependabot_groups_use_plain_minor_patch_values(): void
    {
        $config = $this->parse('.github/dependabot.yml');

        $found = 0;
        foreach ($config['updates'] as $update) {
            foreach ($update['groups'] ?? [] as $group) {
                $found++;
                $this->assertArrayHasKey('update-types', $group);
                foreach ($group['update-types'] as $type) {
                    // GitHub docs: group update-types are plain major/minor/patch.
                    $this->assertContains($type, ['major', 'minor', 'patch']);
                    $this->assertStringStartsNotWith('version-update:', $type);
                }
            }
        }
        $this->assertGreaterThan(0, $found);
    }

    public function test_dependabot_pull_request_limit_is_at_most_three(): void
    {
        foreach ($this->parse('.github/dependabot.yml')['updates'] as $update) {
            $this->assertArrayHasKey('open-pull-requests-limit', $update);
            $this->assertLessThanOrEqual(3, $update['open-pull-requests-limit']);
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
