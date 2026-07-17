<?php declare(strict_types=1);

use App\Moo\Scaffold\ScaffoldServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    // moo 生态包的 host 接入层（App\Moo\<包>）。覆盖 scaffold 操作人身份契约等。
    ScaffoldServiceProvider::class,
];
