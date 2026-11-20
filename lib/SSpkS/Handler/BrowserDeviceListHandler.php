<?php

namespace SSpkS\Handler;

use SSpkS\Device\DeviceList;
use SSpkS\Output\HtmlOutput;
use SSpkS\SiteStatistics;

final class BrowserDeviceListHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        return $this->isReadRequest() && $_GET === [];
    }

    public function handle(): void
    {
        $output = new HtmlOutput($this->config);
        $output->setVariable('seoDescription', $this->language->get('model_intro'));
        try {
            $deviceList = new DeviceList($this->config);
            $models = $deviceList->getDevices($this->config->models['priority_models']);
            if ($models === []) {
                $output->setTemplate('html_modellist_none');
            } else {
                $output->setVariable('modelCount', count($models));
                $output->setVariable('modellist', $models);
                $this->setOverviewVariables($output);
                $output->setTemplate('html_modellist');
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the model list: ' . $e->getMessage());
            http_response_code(503);
            header('Cache-Control: no-store');
            $output->setVariable('errorMessage', $this->language->get('data_error'));
            $output->setTemplate('html_modellist_error');
        }
        $output->output();
    }

    private function setOverviewVariables(HtmlOutput $output): void
    {
        try {
            $statistics = new SiteStatistics($this->config);
            $overview = $statistics->getPackageOverview();
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to build the home page overview: ' . $e->getMessage());
            return;
        }

        $total = (int) $overview['total'];
        $online = (int) $overview['online'];
        $stable = (int) $overview['stable'];
        $beta = (int) $overview['beta'];
        $archCounts = (array) $overview['archCounts'];
        $topArch = '';
        $topArchCount = 0;
        foreach ($archCounts as $architecture => $count) {
            if ($topArch === '') {
                $topArch = (string) $architecture;
                $topArchCount = (int) $count;
            }
        }
        $percent = static fn (int $value, int $base): string => $base > 0
            ? (string) (int) round($value * 100 / $base)
            : '0';

        $totalBytesLabel = $statistics->formatBytes((int) $overview['totalBytes']);
        $output->setVariable('overviewHeaderText', $this->language->get('overview_meta', [
            'packages' => $total,
            'size' => $totalBytesLabel,
        ]));
        $output->setVariable('overviewPackageTotal', $total);
        $output->setVariable('overviewPackageSub', $this->language->get('metric_packages_sub', [
            'stable' => $stable,
            'beta' => $beta,
        ]));
        $output->setVariable('overviewStableRate', $percent($stable, $total));
        $output->setVariable('overviewStableRateLabel', $this->language->get('rate_stable', [
            'value' => $percent($stable, $total),
        ]));
        $output->setVariable('overviewOnline', $online);
        $output->setVariable('overviewOnlineSub', $total > 0 && $online === $total
            ? $this->language->get('online_all')
            : $this->language->get('online_partial', ['online' => $online, 'total' => $total]));
        $output->setVariable('overviewOnlineRateLabel', $this->language->get('rate_online', [
            'value' => $percent($online, $total),
        ]));
        $output->setVariable('overviewArchTotal', count($archCounts));
        $archPreview = [];
        foreach ($archCounts as $architecture => $count) {
            if (count($archPreview) >= 3) {
                break;
            }
            $archPreview[] = ['arch' => (string) $architecture, 'count' => (int) $count];
        }
        $output->setVariable('overviewArchPreview', $archPreview);
        $output->setVariable('overviewArchTopRateLabel', $topArch === ''
            ? $this->language->get('rate_none')
            : $this->language->get('rate_arch_top', [
                'arch' => $topArch,
                'value' => $percent($topArchCount, $total),
            ]));
        $output->setVariable('overviewTotalSize', $totalBytesLabel);
        $output->setVariable('overviewAverageSub', $this->language->get('size_average', [
            'value' => $statistics->formatBytes((int) $overview['avgBytes']),
        ]));
        $output->setVariable('overviewMaxLabel', $this->language->get('size_max', [
            'value' => $statistics->formatBytes((int) $overview['maxBytes']),
        ]));
    }
}
