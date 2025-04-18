<?php

namespace Tests\PatchGenerator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PatchGenerator\PatchGenerator;

class PatchGeneratorTest extends TestCase
{
    private const TEST_DATA_DIR = __DIR__ . '/../data';
    private string $generatedDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generatedDir = self::TEST_DATA_DIR . '/generated';

        // Clean up and recreate generated directory
        if (is_dir($this->generatedDir)) {
            array_map('unlink', glob($this->generatedDir . '/*'));
        } else {
            mkdir($this->generatedDir, 0777, true);
        }
    }

    #[DataProvider('getDiffFilesProvider')]
    public function testProcessGitDiffFromFile(
        string $ticketId,
        string $inputDiffFile,
        string $expectedComposerPatchFile,
        string $expectedGitPatchFile
    ): void {
        // Mock only the necessary variables for testing
        $mockEnv = [
            'JIRA_HOST' => 'https://jira.example.com',
            'JIRA_USER' => 'test',
            'JIRA_PASS' => 'test',
            'GIT_TOKEN' => 'test',
            'JIRA_FIELD_PULL_REQUEST' => 'customfield_12345'
        ];

        $patchGenerator = new PatchGenerator($mockEnv, $ticketId);

        $diffContent = file_get_contents($inputDiffFile);
        $this->assertNotFalse($diffContent, "Failed to read input diff file: {$inputDiffFile}");

        $filteredContent = $patchGenerator->filterPatchContent($diffContent);

        $filteredContentFile = $this->generatedDir . "/{$ticketId}.filtered.diff";
        $generatedGitPatch = $this->generatedDir . "/{$ticketId}.git.patch";
        $generatedComposerPatch = $this->generatedDir . "/{$ticketId}.composer.patch";

        file_put_contents($filteredContentFile, $filteredContent);
        $patchGenerator->convertToComposer($filteredContentFile, $generatedComposerPatch);
        $patchGenerator->convertToComposer($generatedComposerPatch, $generatedGitPatch, true);

        $this->assertFileEquals(
            $expectedComposerPatchFile,
            $generatedComposerPatch,
            "Generated composer patch for {$ticketId} does not match expected output"
        );

        $this->assertFileEquals(
            $expectedGitPatchFile,
            $generatedGitPatch,
            "Generated git patch for {$ticketId} does not match expected output"
        );

        $this->assertIsString($filteredContent);
        $this->assertStringNotContainsString('/Test/', $filteredContent, "Test files should be excluded from {$ticketId}");
        $this->assertStringNotContainsString('/tests/', $filteredContent, "Test files should be excluded from {$ticketId}");
    }

    public static function getDiffFilesProvider(): array
    {
        $testCases = [];
        $inputDir = self::TEST_DATA_DIR . '/input';
        $outputDir = self::TEST_DATA_DIR . '/output';

        if (!is_dir($inputDir)) {
            throw new \RuntimeException("Input directory not found: {$inputDir}");
        }
        if (!is_dir($outputDir)) {
            throw new \RuntimeException("Output directory not found: {$outputDir}");
        }

        $diffFiles = glob($inputDir . '/*.diff');
        foreach ($diffFiles as $diffFile) {
            $ticketId = basename($diffFile, '.diff');
            $expectedPatch = $outputDir . "/{$ticketId}.patch";
            $expectedGitPatch = $outputDir . "/{$ticketId}.git.patch";

            if (!file_exists($expectedPatch) || !file_exists($expectedGitPatch)) {
                throw new \RuntimeException(
                    "Missing expected output files for {$ticketId}. " .
                    "Expected files:\n" .
                    "- {$expectedPatch}\n" .
                    "- {$expectedGitPatch}"
                );
            }

            $testCases[$ticketId] = [
                'ticketId' => $ticketId,
                'inputDiffFile' => $diffFile,
                'expectedComposerPatchFile' => $expectedPatch,
                'expectedGitPatchFile' => $expectedGitPatch
            ];
        }

        if (empty($testCases)) {
            throw new \RuntimeException("No test cases found in {$inputDir}");
        }

        return $testCases;
    }
}
