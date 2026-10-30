<?php

declare(strict_types=1);

namespace SunsetReverie\Upload;

use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Foundation\ServiceProvider;
use SunsetReverie\Upload\Exception\UploadException;
use SunsetReverie\Upload\Storage\LocalStorage;
use SunsetReverie\Upload\Storage\StorageInterface;

/**
 * 上传组件服务提供者。
 *
 * 按配置创建存储驱动；v0.9 只内置本地驱动，云存储通过 extend 扩展。
 */
final class UploadServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(UploadManager::class, function (Container $container): UploadManager {
            /** @var Config $config */
            $config = $container->get(Config::class);
            /** @var Application $app */
            $app = $container->get(Application::class);

            $uploadConfig = (array) $config->get('upload', []);
            $disk = (string) ($uploadConfig['disk'] ?? 'local');
            $diskConfig = (array) ($uploadConfig['disks'][$disk] ?? []);

            return new UploadManager($uploadConfig, $this->createStorage($diskConfig, $app->basePath()));
        });

        $this->container->bind('upload', UploadManager::class);
    }

    /**
     * 创建存储驱动。
     *
     * @param array<string, mixed> $config 驱动配置
     * @param string $basePath 项目根目录
     * @return StorageInterface 存储驱动
     * @throws UploadException 驱动不支持时抛出
     */
    private function createStorage(array $config, string $basePath): StorageInterface
    {
        $driver = (string) ($config['driver'] ?? 'local');

        if ($driver !== 'local') {
            throw new UploadException(sprintf(
                '不支持的存储驱动 [%s]，v0.9 仅内置 local；云存储请通过自定义 Provider 注册',
                $driver
            ), 500);
        }

        $root = (string) ($config['root'] ?? 'storage/uploads');

        if (!str_starts_with($root, '/')) {
            $root = rtrim($basePath, '/') . '/' . ltrim($root, '/');
        }

        return new LocalStorage($root, (string) ($config['url_prefix'] ?? '/uploads'));
    }
}
