<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Migration;

use SunsetReverie\Database\Schema\Schema;

/**
 * 迁移基类。
 *
 * 迁移文件返回匿名子类实例：
 *
 *   return new class extends Migration {
 *       public function up(Schema $schema): void { ... }
 *       public function down(Schema $schema): void { ... }
 *   };
 */
abstract class Migration
{
    /**
     * 执行迁移（建表 / 加索引 / 数据初始化）。
     *
     * @param Schema $schema 表结构入口
     * @return void
     */
    abstract public function up(Schema $schema): void;

    /**
     * 回滚迁移（必须真实删除 up 创建的对象）。
     *
     * @param Schema $schema 表结构入口
     * @return void
     */
    abstract public function down(Schema $schema): void;
}
