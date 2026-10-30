<?php

declare(strict_types=1);

namespace SunsetReverie\Upload\Storage;

/**
 * 存储驱动契约。
 *
 * 只声明所有驱动都能保证的能力；云存储的额外能力（预签名、分片）
 * 应通过独立能力接口声明，不在本契约中"假装支持"。
 */
interface StorageInterface
{
    /**
     * 保存文件内容（供已落地的临时文件使用）。
     *
     * @param string $path 相对路径
     * @param string $sourcePath 源文件绝对路径
     * @return void
     */
    public function put(string $path, string $sourcePath): void;

    /**
     * 保存字符串内容。
     *
     * @param string $path 相对路径
     * @param string $contents 内容
     * @return void
     */
    public function putContents(string $path, string $contents): void;

    /**
     * 文件是否存在。
     *
     * @param string $path 相对路径
     * @return bool 存在返回 true
     */
    public function exists(string $path): bool;

    /**
     * 删除文件。
     *
     * @param string $path 相对路径
     * @return bool 删除成功返回 true
     */
    public function delete(string $path): bool;

    /**
     * 获取公开访问 URL。
     *
     * @param string $path 相对路径
     * @return string URL
     */
    public function url(string $path): string;

    /**
     * 获取物理路径（仅本地驱动有意义，云存储返回空串）。
     *
     * @param string $path 相对路径
     * @return string 绝对路径
     */
    public function path(string $path): string;
}
