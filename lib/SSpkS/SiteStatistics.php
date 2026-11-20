<?php

namespace SSpkS;

use SSpkS\Device\DeviceList;
use SSpkS\Package\BrowserPackageCatalog;
use SSpkS\Package\PackageFilter;
use think\facade\Cache;

final class SiteStatistics
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @return array{models: int, packages: int} */
    public function get(): array
    {
        $modelsFile = $this->config->basePath . DIRECTORY_SEPARATOR . $this->config->paths['models'];
        $cacheKey = 'site_statistics_v3_' . hash('sha256', serialize([
            $this->config->paths, $this->config->models,
            is_file($modelsFile) ? filesize($modelsFile) . ':' . filemtime($modelsFile) : '',
        ]));
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['models'], $cached['packages'])) {
                return [
                    'models' => max(0, (int) $cached['models']),
                    'packages' => max(0, (int) $cached['packages']),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the site statistics cache: ' . $e->getMessage());
        }

        $filter = new PackageFilter($this->config);
        $filter->setOldVersionFilter(true);
        $statistics = [
            'models' => count((new DeviceList($this->config))->getDevices($this->config->models['priority_models'])),
            'packages' => count($filter->getFilteredPackageList()),
        ];
        try {
            Cache::set($cacheKey, $statistics, 300);
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to write the site statistics cache: ' . $e->getMessage());
        }
        return $statistics;
    }

    /**
     * Aggregate the browser catalog into the numbers shown on the home page.
     *
     * @return array{total:int,stable:int,beta:int,online:int,totalBytes:int,maxBytes:int,avgBytes:int,archCounts:array<string,int>}
     */
    public function getPackageOverview(): array
    {
        $overview = [
            'total' => 0, 'stable' => 0, 'beta' => 0, 'online' => 0,
            'totalBytes' => 0, 'maxBytes' => 0, 'avgBytes' => 0, 'archCounts' => [],
        ];
        try {
            $packages = (new BrowserPackageCatalog($this->config))->getAll();
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the package overview: ' . $e->getMessage());
            return $overview;
        }

        $archCounts = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $overview['total']++;
            if (!empty($package['beta'])) {
                $overview['beta']++;
            }
            if (!empty($package['downloadEnabled'])) {
                $overview['online']++;
            }
            $size = max(0, (int) ($package['filesize'] ?? 0));
            $overview['totalBytes'] += $size;
            $overview['maxBytes'] = max($overview['maxBytes'], $size);
            foreach ($this->architecturesOf($package['arch'] ?? []) as $architecture) {
                $archCounts[$architecture] = ($archCounts[$architecture] ?? 0) + 1;
            }
        }

        $overview['stable'] = max(0, $overview['total'] - $overview['beta']);
        $overview['avgBytes'] = $overview['total'] > 0
            ? (int) round($overview['totalBytes'] / $overview['total'])
            : 0;
        arsort($archCounts, SORT_NUMERIC);
        $overview['archCounts'] = $archCounts;
        return $overview;
    }

    /** @return string[] */
    private function architecturesOf($value): array
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $list = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $list = [];
        }
        $architectures = [];
        foreach ($list as $item) {
            $architecture = strtolower(trim((string) $item));
            if ($architecture === '' || $architecture === 'noarch') {
                continue;
            }
            $architectures[$architecture] = true;
        }
        return array_keys($architectures);
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $exponent = (int) min(count($units) - 1, floor(log($bytes, 1024)));
        $value = $bytes / (1024 ** $exponent);
        $precision = $value >= 10 || $exponent === 0 ? 0 : 1;
        return number_format($value, $precision) . ' ' . $units[$exponent];
    }
}
