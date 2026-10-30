<?php

declare(strict_types=1);

use SunsetReverie\Database\Migration\Migration;
use SunsetReverie\Database\Schema\Blueprint;
use SunsetReverie\Database\Schema\Schema;

/**
 * 队列表（database 驱动使用）。
 *
 * 执行 `sr migrate` 前需先把 queue.driver 配成 database（默认即是）。
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 64);
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->timestamp('available_at');
            // 抢占令牌：NULL 表示未被任何 worker 拿走
            $table->string('reserved_token', 36)->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('created_at');
            // 取任务走 (queue, available_at)，重试判断走 reserved_at
            $table->index(['queue', 'available_at']);
            $table->index(['reserved_at']);
        });

        $schema->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 64);
            $table->text('payload');
            $table->text('exception');
            $table->timestamp('failed_at');
            $table->index(['failed_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('failed_jobs');
        $schema->dropIfExists('jobs');
    }
};
