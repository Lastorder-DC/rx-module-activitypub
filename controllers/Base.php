<?php

namespace Rhymix\Modules\Activitypub\Controllers;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * ActivityPub 연동 모듈 - 기본 컨트롤러
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class Base extends \ModuleObject
{
	/**
	 * 디버그 메시지를 파일에 기록
	 *
	 * exit; 호출로 인해 라이믹스의 debugPrint 핸들러가 동작하지 않으므로,
	 * 수동으로 파일에 메시지를 저장합니다.
	 *
	 * @param string $message
	 */
	public static function debugLog($message)
	{
		// 디버그 모드가 비활성화된 경우 즉시 반환
		$config = \Rhymix\Modules\Activitypub\Models\Config::getConfig();
		if (($config->debug_enabled ?? 'N') !== 'Y')
		{
			return;
		}

		$dir = \RX_BASEDIR . 'files/debug';
		if (!is_dir($dir))
		{
			@mkdir($dir, 0755, true);
		}

		$file = $dir . '/activitypub.php';

		// 파일이 없으면 PHP exit 가드를 추가하여 웹에서 직접 접근 방지
		if (!file_exists($file))
		{
			@file_put_contents($file, "<?php exit; ?>\n", LOCK_EX);
		}

		$timestamp = date('Y-m-d H:i:s');
		$entry = '[' . $timestamp . '] ' . $message . "\n";
		@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
	}
}
