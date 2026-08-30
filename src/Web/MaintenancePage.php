<?php

declare(strict_types=1);

namespace GnuCms\Web;

use GnuCms\Db\MaintenanceRequired;

/**
 * 스키마를 옮기는 중이거나 옮기지 못했을 때 내는 503 화면.
 * DB·테마·Slim 없이 그린다. 방문자에게 오류 원문은 보이지 않는다.
 */
final class MaintenancePage
{
    public static function send(MaintenanceRequired $e): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 30');
        header('Cache-Control: no-store');
        echo self::html($e);
    }

    public static function html(MaintenanceRequired $e): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $name = $h(GNUCMS);
        if ($e->kind() === MaintenanceRequired::BUSY) {
            $title = '잠시만 기다려 주세요';
            $body = '<p>데이터 구조를 새 판으로 옮기는 중입니다. 잠시 뒤 새로고침해 주세요.</p>';
        } else {
            $title = '점검이 필요합니다';
            $body = '<p>데이터 구조를 새 판으로 옮기지 못했습니다. 관리자가 <code>storage/logs/error.log</code> 를 확인해야 합니다.</p>';
            if ($e->backup() !== null) {
                $body .= '<p>옮기기 전 백업: <code>' . $h(basename($e->backup())) . '</code> (<code>storage/backups/</code>)</p>';
            }
        }

        return '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="refresh" content="30">'
            . '<title>' . $h($title) . ' · ' . $name . '</title>'
            . '<style>'
            . ':root{color-scheme:light dark;--bg:#f4f8fd;--panel:#fff;--fg:#0f172a;--muted:#64748b;--line:#dbe4f0;--primary:#2f7fe0}'
            . '@media(prefers-color-scheme:dark){:root{--bg:#0b1220;--panel:#111a2b;--fg:#e5edf8;--muted:#94a3b8;--line:#243043}}'
            . '*{box-sizing:border-box}body{margin:0;padding:64px 16px;background:var(--bg);color:var(--fg);font:15px/1.7 system-ui,-apple-system,"Segoe UI","Noto Sans KR",sans-serif}'
            . 'main{max-width:560px;margin:auto;padding:36px;border:1px solid var(--line);border-radius:20px;background:var(--panel)}'
            . 'h1{margin:0 0 12px;font-size:26px;letter-spacing:-.03em}p{margin:0 0 10px}code{padding:2px 6px;border-radius:6px;background:rgba(47,127,224,.12)}'
            . '.brand{color:var(--primary);font-weight:800;margin-bottom:22px}'
            . '</style></head><body><main><div class="brand">' . $name . '</div><h1>' . $h($title) . '</h1>' . $body . '</main></body></html>';
    }
}
