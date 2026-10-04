<?php
/**
 * 前端控制器：/ 输出 SPA，静态资源由虚拟主机原生服务。
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/schema.php';

if (!ww_installed()) {
    header('Location: install.php');
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/www/index.html');
