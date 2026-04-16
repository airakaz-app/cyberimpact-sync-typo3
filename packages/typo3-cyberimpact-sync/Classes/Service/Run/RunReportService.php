<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class RunReportService
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly StorageRepository $storageRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, int|string> $summary
     * @param array<int, array<string, mixed>> $errors
     */
    public function writeRunCsvReport(array $run, array $summary, array $errors): int
    {
        $settings = $this->getSettings();
        $storageUid = (int)($settings['falStorageUid'] ?? 1);
        $reportsFolder = (string)($settings['reportsFolder'] ?? 'reports/');

        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null) {
            return 0;
        }
        if (!$storage->hasFolder($reportsFolder)) {
            $storage->createFolder($reportsFolder);
        }

        $folder = $storage->getFolder($reportsFolder);
        $tmpFilePath = tempnam(sys_get_temp_dir(), 'cyberimpact_report_');
        if ($tmpFilePath === false) {
            return 0;
        }

        $handle = fopen($tmpFilePath, 'wb');
        if ($handle === false) {
            @unlink($tmpFilePath);
            return 0;
        }

        try {
            fputcsv($handle, ['section', 'key', 'value']);
            fputcsv($handle, ['run', 'uid', (string)($run['uid'] ?? '')]);
            fputcsv($handle, ['run', 'status', (string)($run['status'] ?? '')]);
            fputcsv($handle, ['run', 'exact_sync', (string)($run['exact_sync'] ?? 0)]);

            foreach ($summary as $key => $value) {
                fputcsv($handle, ['summary', (string)$key, (string)$value]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['error_uid', 'chunk_uid', 'stage', 'code', 'message']);

            foreach ($errors as $error) {
                fputcsv($handle, [
                    (string)($error['uid'] ?? ''),
                    (string)($error['chunk_uid'] ?? ''),
                    (string)($error['stage'] ?? ''),
                    (string)($error['code'] ?? ''),
                    (string)($error['message'] ?? ''),
                ]);
            }

            fclose($handle);
            $handle = null;

            $fileName = sprintf('run_%d_%d.csv', (int)($run['uid'] ?? 0), time());
            $falFile = $storage->addFile($tmpFilePath, $folder, $fileName, 'changeName');

            return (int)$falFile->getUid();
        } catch (\Throwable) {
            return 0;
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
            if (file_exists($tmpFilePath)) {
                @unlink($tmpFilePath);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
