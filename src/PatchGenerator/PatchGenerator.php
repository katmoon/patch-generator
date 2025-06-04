<?php

declare(strict_types=1);

namespace PatchGenerator;

use Exception;
use InvalidArgumentException;
class PatchGenerator
{
    private array $env;
    private string $ticketId;
    private string $patchVersion;
    private ?string $gitPrs;
    private array $jiraResponse;
    private array $excludedPaths;

    public function __construct(
        array $env = [],
        string $ticketId = '',
        string $patchVersion = '',
        ?string $gitPrs = null
    ) {
        $this->env = array_merge($this->loadEnv(), $env);
        $this->validateEnv();
        $this->ticketId = $ticketId;
        $this->patchVersion = $this->normalizeVersion($patchVersion);
        $this->gitPrs = $gitPrs;
        $this->jiraResponse = [];
        $this->excludedPaths = $this->parseExcludedPaths($this->env['EXCLUDED_PATHS'] ?? null);
    }

    private function loadEnv(): array
    {
        $env = [];

        // Try loading from current directory
        $envFile = getcwd() . '/.env';
        if (file_exists($envFile)) {
            $env = array_merge($env, parse_ini_file($envFile));
        }

        // Try loading from home directory
        $homeEnvFile = getenv('HOME') . '/.env';
        if (file_exists($homeEnvFile)) {
            $env = array_merge($env, parse_ini_file($homeEnvFile));
        }

        // Merge with existing environment variables
        return array_merge($_ENV, getenv(), $env);
    }

    private function validateEnv(): void
    {
        $requiredVars = [
            'JIRA_HOST',
            'JIRA_USER',
            'JIRA_PASS',
            'GIT_TOKEN',
            'JIRA_FIELD_PULL_REQUEST',
            'CONVERTER'
        ];

        $missingVars = array_filter($requiredVars, fn($var) => empty($this->env[$var]));
        if (!empty($missingVars)) {
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missingVars)
            );
        }
    }

    private function normalizeVersion(string $version): string
    {
        // Remove any leading/trailing whitespace
        $version = trim($version);

        if (empty($version)) {
            return '';
        }

        // Remove any _v, v, or _ prefix
        $version = preg_replace('/^[_v]+/', '', $version);

        // If version is 1, return empty string
        if ($version === '1') {
            return '';
        }

        return $version;
    }

    private function parseExcludedPaths(?string $paths): ?array
    {
        if (!$paths) {
            return null;
        }

        return array_filter(array_map('trim', explode(',', $paths)));
    }

    public function generate(): string
    {
        try {
            $this->fetchJiraTicket();
            if (empty($this->gitPrs)) {
                echo PHP_EOL . "Collecting PRs from ticket {$this->ticketId}:" . PHP_EOL;
                echo str_repeat("-", 80) . PHP_EOL;
                $this->gitPrs = $this->jiraResponse['fields'][$this->env['JIRA_FIELD_PULL_REQUEST']] ?? '';
                echo $this->gitPrs . PHP_EOL;
                echo str_repeat("-", 80) . PHP_EOL . PHP_EOL;
                if (empty($this->gitPrs)) {
                    throw new Exception('No pull request URLs found in the ticket. Use -g to specify git PRs.');
                }
            }

            $prUrls = $this->convertToGitApiUrls($this->gitPrs);

            $composerPatchFile = $this->downloadAndCreatePatches($prUrls);
        } catch (Exception $e) {
            $this->cleanup();
            throw $e;
        }

        return $composerPatchFile;
    }

    private function fetchJiraTicket(): void
    {
        $url = $this->env['JIRA_HOST'] . '/rest/api/2/issue/' . $this->ticketId;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => $this->env['JIRA_USER'] . ':' . $this->env['JIRA_PASS'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("\ncURL error: {$error}\n\nIs your VPN on?");
        }

        if ($httpCode !== 200) {
            throw new Exception("Jira API returned HTTP code: {$httpCode}");
        }

        $this->jiraResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse Jira response: ' . json_last_error_msg());
        }
    }

    private function convertToGitApiUrls(string $pulls): array
    {
        $pattern = '#(https://)(github\.com)/([^/]+/[^/]+)(/pull)(/\d+)#';

        if (!preg_match_all($pattern, $pulls, $matches)) {
            throw new Exception('Invalid GitHub pull request URLs provided');
        }

        $apiUrls = [];
        foreach ($matches[0] as $url) {
            if (strlen($url) > 10) {
                $apiUrl = preg_replace_callback($pattern, function ($match) {
                    return $match[1] . 'api.' . 'github.com/repos/' . $match[3] . $match[4] . 's' . $match[5];
                }, $url);

                if (!empty($apiUrl)) {
                    $apiUrls[] = $apiUrl;
                }
            }
        }

        if (empty($apiUrls)) {
            throw new Exception('Failed to convert GitHub URLs to API URLs');
        }

        return $apiUrls;
    }

    protected function downloadAndCreatePatches(array $prUrls): string
    {
        $version = $this->jiraResponse['fields']['versions'][0]['name'] ?? '';
        if (empty($version)) {
            echo "\033[33mWarning: Release version not found in the ticket\033[0m" . PHP_EOL;
        }

        $branchNames = [];
        $patchContents = [];

        foreach ($prUrls as $url) {
            $result = $this->downloadPrContent($url);
            $content = $result['content'];
            $branchName = $result['branchName'];
            $branchNames[] = $branchName;

            if ($this->excludedPaths) {
                // Filter out excluded paths before adding to contents
                $content = $this->filterPatchContent($content);
            }
            $patchContents[] = $content;
        }

        // Check if any branch name contains _DEBUG or _CUSTOM
        $hasDebugBranch = false;
        $hasCustomBranch = false;
        foreach ($branchNames as $branch) {
            if (stripos($branch, '_DEBUG') !== false) {
                $hasDebugBranch = true;
            }
            if (stripos($branch, '_CUSTOM') !== false) {
                $hasCustomBranch = true;
            }
        }

        $gitPatchFile = $this->getPatchFilename($version, '.git.patch', $hasDebugBranch, $hasCustomBranch);
        $composerPatchFile = $this->getPatchFilename($version, '.patch', $hasDebugBranch, $hasCustomBranch);

        if (!is_writable(dirname($gitPatchFile))) {
            throw new Exception("Directory is not writable: " . dirname($gitPatchFile));
        }

        // Write all collected patch contents to file, ensuring there's a newline at the end
        $content = implode("\n", $patchContents);
        if (substr($content, -1) !== "\n") {
            $content .= "\n";
        }
        file_put_contents($gitPatchFile, $content);

        $this->convertToComposer($gitPatchFile, $composerPatchFile);
        $this->cleanupTemporaryFiles($gitPatchFile);
        # Re-create the git patch file with the correct file paths
        $this->convertToComposer($composerPatchFile, $gitPatchFile, true);

        return $composerPatchFile;
    }

    protected function getPatchFilename(string $releaseVersion, string $extension, bool $hasDebugBranch = false, bool $hasCustomBranch = false): string
    {
        $releaseVersion = $releaseVersion ? '_' . $releaseVersion : '';
        $versionSuffix = empty($this->patchVersion) ? '' : "_v{$this->patchVersion}";
        $debugSuffix = $hasDebugBranch ? '_DEBUG' : '';
        $customSuffix = $hasCustomBranch ? '_CUSTOM' : '';

        return $this->ticketId . $releaseVersion . $debugSuffix . $customSuffix . $versionSuffix . $extension;
    }

    private function downloadPrContent(string $url): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new Exception('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Authorization: Bearer " . $this->env['GIT_TOKEN'],
                "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36"
            ]
        ]);

        $prInfo = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || $prInfo === false) {
            $error = curl_error($ch);
            throw new Exception("Failed to get PR info: " . ($error ?: "HTTP code: {$httpCode}"));
        }

        $prData = json_decode($prInfo, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse PR info: ' . json_last_error_msg());
        }

        $branchName = $prData['head']['ref'] ?? '';

        // Now get the PR diff content
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                "Accept: application/vnd.github.v3.diff",
                "Authorization: Bearer " . $this->env['GIT_TOKEN'],
                "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36"
            ]
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new Exception("cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("GitHub API returned HTTP code: {$httpCode}");
        }

        if (empty($result)) {
            throw new Exception("Empty response from GitHub API");
        }

        return ['content' => $result, 'branchName' => $branchName];
    }

    public function filterPatchContent(string $content): string
    {
        if (empty($this->excludedPaths)) {
            return $content;
        }

        $lines = explode("\n", $content);
        $filteredLines = [];
        $currentFile = null;
        $excludeCurrentFile = false;

        foreach ($lines as $line) {
            if (preg_match('/^diff --git a\/(.*?) b\//', $line, $matches)) {
                $currentFile = $matches[1];
                $excludeCurrentFile = false;
                foreach ($this->excludedPaths as $excludedPath) {
                    if (strpos($currentFile, $excludedPath) !== false) {
                        $excludeCurrentFile = true;
                        break;
                    }
                }
            }

            if (!$excludeCurrentFile) {
                $filteredLines[] = $line;
            }
        }

        return implode("\n", $filteredLines);
    }

    public function convertToComposer(string $fromPatchFile, string $toPatchFile, bool $reverse = false): void
    {
        $command = sprintf(
            '%s%s %s > %s',
            $this->env['CONVERTER'],
            $reverse ? ' -r' : '',
            escapeshellarg($fromPatchFile),
            escapeshellarg($toPatchFile)
        );

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Failed to convert patch file");
        }

        echo "Patch file created: {$toPatchFile}" . PHP_EOL;
    }

    private function cleanupTemporaryFiles(string $gitPatchFile): void
    {
        if (file_exists($gitPatchFile)) {
            unlink($gitPatchFile);
        }
    }

    private function mapEnvironmentType(string $envType): string
    {
        $envType = strtolower(trim($envType));

        return match ($envType) {
            'production', 'prod', 'prd' => 'production',
            'staging', 'stage', 'stg' => 'staging',
            'development', 'integration' => 'integration',
            default => throw new Exception("Unsupported environment type: {$envType}. Expected: Production, Staging, or Development")
        };
    }

    private function cleanup(): void
    {
        $version = $this->jiraResponse['fields']['versions'][0]['name'] ?? '';
        if ($version) {
            $files = [
                $this->getPatchFilename($version, '.git.patch'),
                $this->getPatchFilename($version, '.patch')
            ];

            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
