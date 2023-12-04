<?php
declare(strict_types=1);

namespace PrestaShop\Module\GitHubUpgradeManager;

use Hook;
use PrestaShop\CircuitBreaker\Contract\CircuitBreakerInterface;
use PrestaShop\PrestaShop\Core\Module\SourceHandler\SourceHandlerFactory;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Tools;

class UpgradeManager
{
    public const ALLOWED_FAILURES = 2;
    public const TIMEOUT_IN_SECONDS = 3;
    public const THRESHOLD_SECONDS = 86400; // 24 hours
    public const CACHE_LIFETIME_SECONDS = 86400; // 24 hours

    private const GITHUB_RELEASE_ENDPOINT = 'https://api.github.com/repos/%s/releases/latest';

    /** @var CircuitBreakerInterface */
    private $circruitBreaker;

    /** @var SourceHandlerFactory */
    private $sourceHandlerFactory;

    /** @var string */
    private $cacheDirectory;

    /** @var string */
    private $githubToken;

    public function __construct(
        CircuitBreakerInterface $circruitBreaker,
        SourceHandlerFactory $sourceHandlerFactory,
        string $cacheDirectory,
        string $githubToken
    ) {
        $this->circruitBreaker = $circruitBreaker;
        $this->sourceHandlerFactory = $sourceHandlerFactory;
        $this->cacheDirectory = rtrim($cacheDirectory, '/');
        $this->githubToken = $githubToken;
    }

    /**
     * @return string
     */
    protected function getLatestReleaseUrl(string $repository) : string
    {
        return sprintf(self::GITHUB_RELEASE_ENDPOINT, $repository);
    }

    /**
     * @return array<array<string, string>>
     */
    public function getModulesList($cache = false): array
    {
        $modules = [];

        $cachedFile = $this->cacheDirectory . '/github-upgrade-manager/' . Tools::hashIV(str_replace('/', '_', \Context::getContext()->shop->name));

        if ($cache) {
            if (file_exists($cachedFile)) {
                $modules = file_get_contents($cachedFile);
                $modules = json_decode($modules, true);
            }

            return $modules;
        }

        $repositories = Hook::exec(
            'actionRegisterGitHubAutoUpgrade',
            [],
            null,
            true,
            true,
            false,
            null,
            true
        );
        foreach ($repositories as $moduleName => $repository) {
            $latestRelease = $this->getLatestRelease($moduleName, $repository);
            if (is_array($latestRelease) && isset($latestRelease['version_available'])) {
                $attributes = [
                    'name' => $moduleName,
                    'version_available' => $latestRelease['version_available'],
                    'archive_url' => $latestRelease['download_url'],
                    'asset_url' => $latestRelease['asset_url'],
                    'changeLog' => $latestRelease['changeLog'],
                ];
                $modules[] = $attributes;
            }
        }

        file_put_contents($cachedFile, json_encode($modules));

        return $modules;
    }

    public function downloadModule(string $moduleName): void
    {
        $modules = $this->getModulesList(true);
        foreach ($modules as $module) {
            if ($module['name'] === $moduleName) {
                $this->doDownload($module);
                break;
            }
        }
    }

    /**
     * Extracts the download URL from a module data structure
     *
     * @param array{archive_url?: string} $module Module data structure, from API response
     *
     * @return string Download URL
     */
    protected function getModuleDownloadUrl(array $module): string
    {
        if (!isset($module['archive_url'])) {
            throw new RuntimeException('Could not determine URL to download the module from');
        }

        return $module['archive_url'];
    }

    /**
     * Extracts the download URL from a module data structure
     *
     * @param array{asset_url?: string} $module Module data structure, from API response
     *
     * @return string Download URL
     */
    protected function getAssetDownloadUrl(array $module): string
    {
        if (!isset($module['asset_url'])) {
            throw new RuntimeException('Could not determine URL to download the module from');
        }

        return $module['asset_url'];
    }

    /**
     * @param array<string, string> $module
     *
     * @return void
     */
    private function doDownload(array $module): void
    {
        $downloadUrl = $this->getModuleDownloadUrl($module);

        $moduleZip = @file_get_contents($downloadUrl);

        if ($moduleZip === false || $moduleZip == 'Not Found') {
            $moduleZip = self::file_get_contents_curl($this->getAssetDownloadUrl($module), false, $this->getGithubHeaders(true));
        }

        $downloadPath = $this->getModuleDownloadDirectory($module['name']);
        $this->createDownloadDirectoryIfNeeded($downloadPath);

        file_put_contents($this->getModuleDownloadDirectory($module['name']), $moduleZip);

        $handler = $this->sourceHandlerFactory->getHandler($this->getModuleDownloadDirectory($module['name']));
        $handler->handle($this->getModuleDownloadDirectory($module['name']));

        Tools::deleteFile($this->getModuleDownloadDirectory($module['name']));
    }

    private function getModuleDownloadDirectory(string $moduleName): string
    {
        return $this->cacheDirectory . '/downloads/' . $moduleName . '.zip';
    }

    private function createDownloadDirectoryIfNeeded(string $downloadPath): void
    {
        if (!file_exists(dirname($downloadPath))) {
            mkdir(dirname($downloadPath), 0777, true);
        }
    }

    protected function getLatestRelease(string $moduleName, string $repository): array
    {
        $latestRelease = [];

        $endpoint = $this->getLatestReleaseUrl($repository);
        $response = $this->getResponse($endpoint);

        if (is_array($response)) {
            $latestRelease['version_available'] = str_replace(['v', 'V'], '', $response['tag_name']);
            $latestRelease['download_url'] = $this->getDownloadUrl($response, $moduleName);
            $latestRelease['asset_url'] = $this->getAssetUrl($response, $moduleName);
            $latestRelease['changeLog'] = $this->getChangelog($response, $latestRelease['version_available']);
        }

        if (empty($latestRelease['download_url'])) {
            return [];
        }

        return $latestRelease;
    }

    public function getDownloadUrl($latestRelease = [], $moduleName = '')
    {
      if (empty($latestRelease['assets']) || !is_array($latestRelease['assets'])) {
        return false;
      }

      foreach ($latestRelease['assets'] as $asset) {
        $assetName = str_replace('.zip', '', $asset['name']);
        if ('application/zip' == $asset['content_type'] && $moduleName == $assetName) {
          return $asset['browser_download_url'];
        }
      }

      return '';
    }

    public function getAssetUrl($latestRelease = [], $moduleName = '')
    {
      if (empty($latestRelease['assets']) || !is_array($latestRelease['assets'])) {
        return false;
      }

      foreach ($latestRelease['assets'] as $asset) {
        $assetName = str_replace('.zip', '', $asset['name']);
        if ('application/zip' == $asset['content_type'] && $moduleName == $assetName) {
          return $asset['url'];
        }
      }

      return '';
    }

    public function getChangelog($latestRelease = [], $version = '')
    {
      if (empty($latestRelease['body'])) {
        return null;
      }

      $fullChangelog = '';

      $lines = preg_split("/\r\n|\n|\r/", $latestRelease['body']);

      if (!is_array($lines)) {
        return null;
      }

      $changelog = [
        $version => [
          0 => '',
        ],
      ];
      foreach ($lines as $line) {
        if (substr($line, 0, 1) === "-") {
          $line = str_replace('-', '', $line);
          $changelog[$version][] = $line;
        } else if (substr($line, 0, 18) === "**Full Changelog**") {
            $fullChangelog = str_replace('**', '', $line);
        }
      }

      if (!empty($fullChangelog)) {
        $changelog[$version][] = $fullChangelog;
      }

      return $changelog;
    }

    protected function getGithubToken()
    {
        if (!empty($this->githubToken)) {
            return $this->githubToken;
        }

        if (is_file(_PS_ROOT_DIR_ . '/.env')) {
            $envVars = (new Dotenv())->parse(\file_get_contents(_PS_ROOT_DIR_ . '/.env'));

            if (isset($envVars['GITHUB_TOKEN'])) {
                return $envVars['GITHUB_TOKEN'];
            }
        } else if (is_file(_PS_MODULE_DIR_ . 'ghupgrademanager/.env')) {
            $envVars = (new Dotenv())->parse(\file_get_contents(_PS_MODULE_DIR_ . 'ghupgrademanager/.env'));

            if (isset($envVars['GITHUB_TOKEN'])) {
                return $envVars['GITHUB_TOKEN'];
            }
        }

        return '';
    }

    public function getGithubHeaders($downloadMode = false)
    {
        $headers = [
            'http' => [
                'method'=> "GET",
                'header'=> [
                    "Authorization: Bearer " . $this->getGithubToken(),
                    "X-GitHub-Api-Version: 2022-11-28",
                ],
            ],
        ];

        if (!$downloadMode) {
            $headers['http']['header'][] = 'Accept: application/vnd.github+json';
        } else {
            $headers['http']['header'][] = 'Accept: application/octet-stream';
        }

        return $headers;
    }

    /**
     * @param string $endpoint
     *
     * @return array<array<string, string>>
     */
    private function getResponse(string $endpoint): array
    {
        $fallbackResponse = function () use ($endpoint) {
            return self::file_get_contents_curl($endpoint, false, $this->getGithubHeaders());
        };

        $response = $this->circruitBreaker->call($endpoint, $this->getGithubHeaders(), $fallbackResponse);

        /** @var array<array<string, string>> $json */
        $json = json_decode($response, true) ?: [];

        return $json;
    }

    /**
     * @param string $url
     * @param int $curl_timeout
     * @param array $opts
     *
     * @return bool|string
     *
     * @throws Exception
     */
    private static function file_get_contents_curl(
      $url,
      $curl_timeout,
      $opts,
      $user_agent = 'PrestaShop-ModuleAutoUpgrade'
    ) {
        $content = false;

        if (function_exists('curl_init')) {
          Tools::refreshCACertFile();
          $curl = curl_init();

          curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
          curl_setopt($curl, CURLOPT_URL, $url);
          curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
          curl_setopt($curl, CURLOPT_TIMEOUT, $curl_timeout);
          curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
          curl_setopt($curl, CURLOPT_CAINFO, _PS_CACHE_CA_CERT_FILE_);
          curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
          curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
          curl_setopt($curl, CURLOPT_USERAGENT, $user_agent);

          if (isset($opts['http']['header'])) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $opts['http']['header']);
          }

          $content = curl_exec($curl);

          if (false === $content && _PS_MODE_DEV_) {
            $errorMessage = sprintf('file_get_contents_curl failed to download %s : (error code %d) %s',
              $url,
              curl_errno($curl),
              curl_error($curl)
            );

            throw new \Exception($errorMessage);
          }

          curl_close($curl);
        }

      return $content;
    }
}
